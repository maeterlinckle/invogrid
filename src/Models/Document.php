<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

/**
 * A document making its way through the pipeline.
 *
 * How it arrived is recorded on it — `ingest_source`, `original_filename`,
 * `ingested_by`, `ingested_at` — and nothing past ingest reads any of it. That
 * is the point: a document uploaded by a person and one found in a watched
 * directory are the same document from here on.
 *
 * The status constants and the transition map are the state machine itself.
 * They live here rather than being written out at each call site so that adding
 * a stage later is one edit, and so that an impossible transition is caught
 * rather than quietly stored.
 */
final class Document
{
    public const RECEIVED         = 'received';
    public const OCR_PENDING      = 'ocr_pending';
    public const OCR_DONE         = 'ocr_done';
    public const EXTRACTING       = 'extracting';
    public const EXTRACTED        = 'extracted';
    public const MATCHING         = 'matching';

    /**
     * The New Invoice route's duplicate gate, and the only status on it that a
     * document reaches *instead of* a disposition rather than after one.
     *
     * A document is here because what was extracted from it looks like a
     * purchase document Clear Books already holds — see §34. It carries no
     * handwritten Clearbooks Number, so nothing short-circuited it onto the
     * Existing Invoice route, and submitting it would put the same purchase
     * into somebody's accounts twice.
     */
    public const POSSIBLE_DUPLICATE = 'possible_duplicate';

    public const NEEDS_REVIEW     = 'needs_review';
    public const READY_TO_SUBMIT  = 'ready_to_submit';
    public const EXISTING_INVOICE = 'existing_invoice';
    public const NEEDS_LINK       = 'needs_link';
    public const SUBMITTED        = 'submitted';
    public const FAILED           = 'failed';
    public const IGNORED          = 'ignored';

    /**
     * Which of the two flows a document is on, decided by the OCR stage the
     * moment the transcription lands and then left alone.
     *
     * A handwritten Clearbooks Number is a reference to an invoice that is
     * already in Clear Books, so the document is a scan belonging to a record
     * that exists — not a bill to post.
     *
     * **The route says what happens at the end, not which pipeline runs.** Both
     * flows run the same one: ingest, ocr, extract, match. A scan of an
     * existing invoice is still a document somebody will search for and report
     * on next year, and it has the same supplier, dates, line items and custom
     * fields as any other; skipping the extraction for it would leave a blank
     * row in every list, and would fork the pipeline in two so that every later
     * change to extraction had to be made twice. What differs is only the last
     * step — the New Invoice route creates a record in Clear Books, this one
     * matches an existing record and attaches the scan to it.
     *
     * Kept beside `status` rather than inferred from it. The status says where
     * the document is now; both flows end at `submitted`, and only this says
     * which way it came.
     */
    public const ROUTE_NEW      = 'new_invoice';
    public const ROUTE_EXISTING = 'existing_invoice';

    /** @var array<int,string> */
    public const ROUTES = [self::ROUTE_NEW, self::ROUTE_EXISTING];

    /** @var array<string,string> */
    public const ROUTE_LABELS = [
        self::ROUTE_NEW      => 'New invoice',
        self::ROUTE_EXISTING => 'Existing invoice',
    ];

    /**
     * Every status, in pipeline order. The two terminal-ish states come last
     * because that is how the dashboard reads them out.
     *
     * `existing_invoice` and `needs_link` sit after `ready_to_submit` because
     * that is where the two flows actually diverge: everything above them is
     * run by both, and these two are the Existing Invoice route's answer to
     * "resolved" and "waiting on a person" — the pair beside `ready_to_submit`
     * and `needs_review`, not a step after them. `submitted` follows all four,
     * because both arms end there.
     *
     * `possible_duplicate` sits between `matching` and `needs_review`, which is
     * its real position rather than a filing decision: it is the gate a New
     * Invoice document passes through on the way to a disposition, not a state
     * it can reach from one.
     *
     * @var array<int,string>
     */
    public const STATUSES = [
        self::RECEIVED,
        self::OCR_PENDING,
        self::OCR_DONE,
        self::EXTRACTING,
        self::EXTRACTED,
        self::MATCHING,
        self::POSSIBLE_DUPLICATE,
        self::NEEDS_REVIEW,
        self::READY_TO_SUBMIT,
        self::EXISTING_INVOICE,
        self::NEEDS_LINK,
        self::SUBMITTED,
        self::FAILED,
        self::IGNORED,
    ];

    /**
     * What each status may move to.
     *
     * `failed` is reachable from every working state and leads back to the
     * stage that failed, because a failed stage is retryable — that is the
     * whole reason the failure is recorded rather than the document dropped.
     * `ignored` is a human decision and is reachable from anywhere.
     *
     * **`matching` has four successors, and two of them are branches.** Both
     * flows run every stage up to and including this one; the matching stage
     * reads `route` on its way out and sends the document to `existing_invoice`
     * when this is a scan of something already in Clear Books, and otherwise
     * runs the duplicate check — `possible_duplicate` when what was extracted
     * looks like a purchase document Clear Books already holds, and
     * `needs_review` or `ready_to_submit` as usual when it does not.
     *
     * **`possible_duplicate` has exactly one way on, and it is `matching`.**
     * Confirming a document is genuinely new stamps `duplicate_cleared_at` and
     * re-runs the stage, which takes a different exit because the gate no
     * longer applies — the same shape as the Existing Invoice queue's "post it
     * as a new invoice", and for the same reason: a second copy of "where does
     * this document go now" would disagree with the stage eventually, and
     * invisibly. The other answer is deleting the document, which is not a
     * transition at all.
     *
     * **Nothing may be moved *into* `possible_duplicate` by hand**, which is
     * why only `matching` lists it — `failed` deliberately does not, though it
     * lists every other waiting status. The screen it waits on is a comparison
     * against records the matcher found, so a document parked there by a
     * dropdown would arrive at a page with nothing on one side of it; and a
     * retry resumes at the head of a stage, of which this is not one. A failed
     * document whose duplicate check should run again is retried from
     * `extracted`, which re-runs the matching stage and re-applies the gate
     * against whatever the invoice sync has fetched since.
     *
     * `existing_invoice` has three: `submitted` when the Clearbooks Number
     * found exactly one synced record and its date and total agreed exactly,
     * `needs_link` when it did not, and `matching` for a person overruling the
     * route. `needs_link` offers the same three plus `ignored`, which are the
     * queue's actions: link it by hand, look the number up again after a sync,
     * or push it back onto the New Invoice flow.
     *
     * `needs_review → existing_invoice` and `ready_to_submit → existing_invoice`
     * are the reverse gesture, from the document page. The routing rests on a
     * handwritten number read off a scan, and somebody holding the page is
     * entitled to overrule it in either direction — the alternative is ignoring
     * the document and uploading it again, which loses everything already paid
     * for.
     *
     * @var array<string,array<int,string>>
     */
    public const TRANSITIONS = [
        self::RECEIVED         => [self::OCR_PENDING, self::FAILED, self::IGNORED],
        self::OCR_PENDING      => [self::OCR_DONE, self::FAILED, self::IGNORED],
        self::OCR_DONE         => [self::EXTRACTING, self::FAILED, self::IGNORED],
        self::EXTRACTING       => [self::EXTRACTED, self::FAILED, self::IGNORED],
        self::EXTRACTED        => [self::MATCHING, self::FAILED, self::IGNORED],
        self::MATCHING         => [
            self::NEEDS_REVIEW, self::READY_TO_SUBMIT, self::EXISTING_INVOICE,
            self::POSSIBLE_DUPLICATE, self::FAILED, self::IGNORED,
        ],
        self::POSSIBLE_DUPLICATE => [self::MATCHING, self::FAILED, self::IGNORED],
        self::NEEDS_REVIEW     => [
            self::READY_TO_SUBMIT, self::MATCHING, self::EXISTING_INVOICE,
            self::FAILED, self::IGNORED,
        ],
        self::READY_TO_SUBMIT  => [
            self::SUBMITTED, self::NEEDS_REVIEW, self::EXISTING_INVOICE,
            self::FAILED, self::IGNORED,
        ],
        self::EXISTING_INVOICE => [
            self::SUBMITTED, self::NEEDS_LINK, self::MATCHING, self::FAILED, self::IGNORED,
        ],
        self::NEEDS_LINK       => [
            self::SUBMITTED, self::EXISTING_INVOICE, self::MATCHING, self::FAILED, self::IGNORED,
        ],
        self::SUBMITTED        => [self::IGNORED],

        // A retry puts the document back at the head of the stage that failed,
        // so every working state is a legitimate destination from here.
        self::FAILED           => [
            self::RECEIVED, self::OCR_PENDING, self::OCR_DONE, self::EXTRACTING,
            self::EXTRACTED, self::MATCHING, self::NEEDS_REVIEW, self::READY_TO_SUBMIT,
            self::EXISTING_INVOICE, self::NEEDS_LINK, self::IGNORED,
        ],
        self::IGNORED          => [self::RECEIVED],
    ];

    /** Human labels, for the dashboard and the queue. */
    public const LABELS = [
        self::RECEIVED         => 'Received',
        self::OCR_PENDING      => 'Reading pages',
        self::OCR_DONE         => 'Read',
        self::EXTRACTING       => 'Extracting',
        self::EXTRACTED        => 'Extracted',
        self::MATCHING         => 'Matching',
        self::POSSIBLE_DUPLICATE => 'Possible duplicate',
        self::NEEDS_REVIEW     => 'Needs review',
        self::READY_TO_SUBMIT  => 'Ready to submit',
        self::EXISTING_INVOICE => 'Finding the record',
        self::NEEDS_LINK       => 'Needs linking',
        self::SUBMITTED        => 'Submitted',
        self::FAILED           => 'Failed',
        self::IGNORED          => 'Ignored',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function routeLabel(?string $route): string
    {
        return $route === null ? 'not decided yet' : (self::ROUTE_LABELS[$route] ?? $route);
    }

    /**
     * Record which flow a document was sent down.
     *
     * Separate from `transitionTo()` because the two answer different questions
     * and are not always set together: the OCR stage decides both at once, but a
     * person re-routing a misread document changes the status through the
     * ordinary state machine and the route through here.
     */
    public static function setRoute(int $id, string $route): void
    {
        if (!in_array($route, self::ROUTES, true)) {
            throw new RuntimeException('There is no ' . $route . ' flow.');
        }

        Database::update('documents', ['route' => $route], $id);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM documents WHERE id = ?', [$id]);
    }

    /**
     * How many documents sit at each status.
     *
     * Every status is present in the result, zero included: a dashboard that
     * hides a status when it is empty makes "nothing failed" look the same as
     * "the failed count is missing".
     *
     * @return array<string,int>
     */
    public static function countsByStatus(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach (Database::select('SELECT status, COUNT(*) AS n FROM documents GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    public static function total(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM documents');
    }

    /**
     * The most recently touched documents, for the dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        return Database::select(
            'SELECT * FROM documents ORDER BY updated_at DESC, id DESC LIMIT ' . $limit
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function withStatus(string $status, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        return Database::select(
            'SELECT * FROM documents WHERE status = ? ORDER BY created_at LIMIT ' . $limit,
            [$status]
        );
    }

    /**
     * Move a document to a new status, refusing a transition the state machine
     * does not allow.
     *
     * Refusing rather than logging and continuing: an impossible transition
     * means the caller has misunderstood where the document is, and letting it
     * through produces a document in a state no stage will ever pick up.
     */
    public static function transitionTo(int $id, string $status, ?string $error = null): void
    {
        $document = self::find($id);

        if ($document === null) {
            throw new RuntimeException('No document ' . $id . '.');
        }

        $from = (string) $document['status'];

        if ($from === $status) {
            return;
        }

        if (!self::canTransition($from, $status)) {
            throw new RuntimeException(
                'A document cannot go from ' . self::label($from) . ' to ' . self::label($status) . '.'
            );
        }

        $fields = ['status' => $status];

        if ($status === self::FAILED) {
            $fields['error_message'] = $error;
        } else {
            // Leaving a stale error on a document that has since moved on makes
            // the queue page read as though it is still broken. The attempt
            // count goes with it: it describes one failure, not the document's
            // whole life, and `document_events` is the permanent record.
            $fields['error_message'] = null;
            $fields['failed_stage']  = null;
            $fields['attempts']      = 0;
        }

        Database::update('documents', $fields, $id);
    }

    /**
     * What the extraction concluded, copied onto the document itself.
     *
     * Denormalised on purpose: the list page shows a row per document and would
     * otherwise join to the newest extraction for every one of them, to display
     * two fields. The extraction stays the record of what was extracted; this is
     * a label.
     */
    public static function setExtractionSummary(int $id, ?string $docType, ?string $supplierName): void
    {
        $fields = [];

        if ($docType !== null) {
            $fields['doc_type'] = $docType;
        }

        // Only overwritten when the extraction actually found a name: a
        // document whose issuer could not be read keeps whatever it had.
        if ($supplierName !== null && trim($supplierName) !== '') {
            $fields['supplier_raw'] = mb_substr(trim($supplierName), 0, 255);
        }

        if ($fields !== []) {
            Database::update('documents', $fields, $id);
        }
    }

    /**
     * The supplier the matching stage settled on, copied onto the document.
     *
     * `matched_supplier_id` holds a **Clear Books** remote id, not
     * a row in this database — Clear Books owns those identifiers. Nulling it
     * when nothing matched is deliberate: a stale id left behind from an earlier
     * pass is exactly the kind of thing a submission would use without noticing.
     */
    public static function setMatchedSupplier(int $id, ?string $clearbooksId, ?string $name = null): void
    {
        $fields = ['matched_supplier_id' => $clearbooksId];

        // The name on file beats whatever was read off the letterhead, but
        // only when there is one — an unmatched supplier keeps what the
        // extraction read, which is all anybody has to go on.
        if ($name !== null && trim($name) !== '') {
            $fields['supplier_raw'] = mb_substr(trim($name), 0, 255);
        }

        Database::update('documents', $fields, $id);
    }

    /**
     * Record a stage failure and stop the document where a human can see it.
     *
     * `$attempts` comes from the job that gave up, because that is the number
     * a person reading the page means by "how many times has this been tried".
     * The document has no way of counting them itself — a retry from the UI
     * starts a fresh job — so it is told.
     */
    public static function markFailed(int $id, string $stage, string $error, int $attempts = 1): void
    {
        Database::update('documents', [
            'status'        => self::FAILED,
            'failed_stage'  => mb_substr($stage, 0, 32),
            'error_message' => mb_substr($error, 0, 2000),
            'attempts'      => max(0, $attempts),
        ], $id);
    }

    /**
     * The status a failed document should be put back to.
     *
     * The head of the stage that failed, so a retry re-runs that stage rather
     * than starting the whole document again — re-downloading a PDF because the
     * Clear Books submission failed would be wasteful and slow.
     *
     * Falls back to `received` when nothing recorded which stage broke.
     */
    public static function retryStatusFor(?string $failedStage): string
    {
        $stages = \App\Services\Pipeline::STAGES;

        if ($failedStage !== null && isset($stages[$failedStage]['from'])) {
            return (string) $stages[$failedStage]['from'];
        }

        return self::RECEIVED;
    }

    /**
     * How many documents are sitting in any of these statuses.
     *
     * @param array<int,string> $statuses
     */
    public static function countWithStatuses(array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM documents WHERE status IN (' . self::placeholders($statuses) . ')',
            $statuses
        );
    }

    /**
     * The review queue: documents waiting on a person, with enough to triage on.
     *
     * Joined to the newest extraction rather than read per row, because the
     * whole point of the list is deciding which one to open — and a list that
     * shows only a status forces every one of them open to find out.
     *
     * `unresolved` is the number that actually matters: a document with one
     * unmatched supplier is a minute's work, one with six unmatched account
     * codes is not, and they should not look the same in a queue.
     *
     * @param array<int,string> $statuses
     * @return array<int,array<string,mixed>>
     */
    public static function queue(array $statuses, int $limit = 25, int $offset = 0): array
    {
        if ($statuses === []) {
            return [];
        }

        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        return Database::select(
            'SELECT d.*,
                    e.id            AS extraction_id,
                    e.document_title,
                    e.invoice_number,
                    e.invoice_date,
                    e.due_date,
                    e.net_amount,
                    e.gross_amount,
                    e.currency,
                    e.review_notes,
                    e.edited_at,
                    (SELECT COUNT(*) FROM entity_matches m
                      WHERE m.extraction_id = e.id AND m.status IN (?, ?)) AS unresolved
               FROM documents d
               LEFT JOIN extractions e
                 ON e.id = (SELECT id FROM extractions
                             WHERE document_id = d.id
                             ORDER BY created_at DESC, id DESC LIMIT 1)
              WHERE d.status IN (' . self::placeholders($statuses) . ')
              ORDER BY d.updated_at DESC, d.id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            array_merge(['unmatched', 'rejected'], $statuses)
        );
    }

    /**
     * The Existing Invoice queue: documents whose Clearbooks Number did not
     * settle on its own.
     *
     * A separate reader from `queue()` rather than another status passed to it.
     * The two show the same document differently because they are asking
     * different questions: `queue()` counts what is unresolved, because a
     * reviewer is deciding which document is a minute's work; this one shows
     * the **Clearbooks Number, the invoice date and the gross total** side by
     * side, because those three are what the checksum compares and the mismatch
     * is what a person is here to look at.
     *
     * The most recent `link` event comes along as well, because it says *why*
     * the document is here — no such number, more than one, or a record whose
     * date or total disagreed. A queue that makes somebody open every row to
     * find that out is a queue that gets worked in the wrong order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function linkQueue(int $limit = 25, int $offset = 0): array
    {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        return Database::select(
            'SELECT d.*,
                    o.clearbooks_number,
                    o.project_code,
                    e.invoice_date,
                    e.gross_amount,
                    e.currency,
                    e.invoice_number,
                    e.document_title,
                    v.message AS link_message,
                    v.created_at AS link_at
               FROM documents d
               LEFT JOIN ocr_results o
                 ON o.id = (SELECT id FROM ocr_results
                             WHERE document_id = d.id
                             ORDER BY created_at DESC, id DESC LIMIT 1)
               LEFT JOIN extractions e
                 ON e.id = (SELECT id FROM extractions
                             WHERE document_id = d.id
                             ORDER BY created_at DESC, id DESC LIMIT 1)
               LEFT JOIN document_events v
                 ON v.id = (SELECT id FROM document_events
                             WHERE document_id = d.id AND stage = \'link\'
                             ORDER BY created_at DESC, id DESC LIMIT 1)
              WHERE d.status = ?
              ORDER BY d.updated_at DESC, d.id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            [self::NEEDS_LINK]
        );
    }

    /**
     * The duplicate queue: New Invoice documents that look like something Clear
     * Books already holds.
     *
     * A third reader beside `queue()` and `linkQueue()`, for the third time the
     * same rule applies: the columns a queue shows are the ones its question
     * turns on. `queue()` counts what is unresolved because a reviewer is
     * choosing which document is a minute's work; `linkQueue()` shows the
     * Clearbooks Number and the two values the checksum compares. This one
     * shows the **supplier, their reference, the date and the gross total** —
     * the four signals the comparison is made on — so a row can be dismissed or
     * opened without opening it first.
     *
     * The newest `dedup` event comes with it, because it names the Clear Books
     * records the document was stopped against. A queue that makes somebody
     * open every row to find out which record is at issue is a queue that gets
     * worked in the wrong order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function duplicateQueue(int $limit = 25, int $offset = 0): array
    {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        return Database::select(
            'SELECT d.*,
                    e.invoice_date,
                    e.invoice_number,
                    e.gross_amount,
                    e.currency,
                    e.document_title,
                    v.message AS dedup_message,
                    v.created_at AS dedup_at
               FROM documents d
               LEFT JOIN extractions e
                 ON e.id = (SELECT id FROM extractions
                             WHERE document_id = d.id
                             ORDER BY created_at DESC, id DESC LIMIT 1)
               LEFT JOIN document_events v
                 ON v.id = (SELECT id FROM document_events
                             WHERE document_id = d.id AND stage = \'dedup\'
                             ORDER BY created_at DESC, id DESC LIMIT 1)
              WHERE d.status = ?
              ORDER BY d.updated_at DESC, d.id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            [self::POSSIBLE_DUPLICATE]
        );
    }

    /**
     * Record that a person has said this document is genuinely new.
     *
     * The stamp is what stops the re-match putting the document straight back
     * where it came from: `MatchStage` runs the duplicate check only on a
     * document nobody has cleared, so this and the re-run are two halves of one
     * gesture. Written before the re-match for that reason.
     *
     * There is no method to *un*-clear one, and that is deliberate rather than
     * an omission. A person who decides afterwards that a document really was a
     * duplicate deletes it, which is the same answer the queue offered them —
     * putting it back into a queue to be asked the question a second time would
     * only produce the same decision.
     */
    public static function clearDuplicate(int $id, ?int $userId): void
    {
        Database::update('documents', [
            'duplicate_cleared_at' => date('Y-m-d H:i:s'),
            'duplicate_cleared_by' => $userId,
        ], $id);
    }

    /**
     * Delete a document outright, and everything derived from it.
     *
     * **The only destructive action in this application**, and the only one
     * that cannot be undone. Everything else that takes a document out of the
     * way — `ignored` — leaves it where a person can put it back. This does
     * not: the row goes, and with it the transcription, the page images, the
     * events and the stored PDF.
     *
     * It exists because the Existing Invoice queue needs it. A scan bearing a
     * Clearbooks Number that matches nothing is very often a second copy of a
     * document already filed, and a queue whose only answer to that is "mark it
     * ignored and leave it in the database for ever" is a queue that fills up
     * with rubbish nobody will ever look at again.
     *
     * Three things make it survivable:
     *
     *  - **The audit row outlives it.** `audit_log.document_id` is
     *    `ON DELETE SET NULL` precisely so the log can describe something that
     *    no longer exists, so the caller writes what happened *before* calling
     *    this, with the document's number in the text where a null column
     *    cannot lose it.
     *  - **The database does the cascading**, not this method. Pages, OCR
     *    results, extractions, events, jobs and submissions are all
     *    `ON DELETE CASCADE`; enumerating them here would be a second list to
     *    keep in step with the schema.
     *  - **The files go first.** A deleted row whose PDF is still on disk is
     *    storage nothing will ever reclaim, because nothing knows the directory
     *    is orphaned. A file removed for a row that then fails to delete is the
     *    lesser problem: the document is visibly broken rather than invisibly
     *    costly.
     *
     * @return array{files:int,bytes:int} What was removed from disk
     */
    public static function delete(int $id): array
    {
        $document = self::find($id);

        if ($document === null) {
            throw new RuntimeException('No document ' . $id . '.');
        }

        $removed = ['files' => 0, 'bytes' => 0];

        foreach (self::storedFiles($id, $document) as $path) {
            $size = @filesize($path);

            if (@unlink($path)) {
                $removed['files']++;
                $removed['bytes'] += $size === false ? 0 : $size;
            }
        }

        /*
         * The two directories, once they are empty. `rmdir` refuses a directory
         * that is not, which is the check rather than a reason to force it: a
         * file this method did not expect is a file worth leaving for somebody
         * to look at.
         *
         * `clearstatcache()` first, and it is load-bearing rather than
         * defensive. PHP caches what it knows about a path, and the `filesize`
         * and `unlink` above have just made that knowledge wrong: without this,
         * the `rmdir` fails on a directory that is by then empty and every
         * deleted document leaves an empty folder behind for ever. Verified on
         * this project's own storage — the same `rmdir` succeeds a line later
         * once the cache is dropped.
         */
        clearstatcache();

        foreach ([DocumentPage::directory($id), dirname(\App\Services\IngestStage::storagePath($id))] as $directory) {
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }

        Database::run('DELETE FROM documents WHERE id = ?', [$id]);

        return $removed;
    }

    /**
     * Every file on disk belonging to a document.
     *
     * Read from the rows rather than guessed from the directory layout, so a
     * path stored before the layout last changed is still found.
     *
     * @param array<string,mixed> $document
     * @return array<int,string>
     */
    private static function storedFiles(int $id, array $document): array
    {
        $paths = [];

        if ($document['pdf_path'] !== null) {
            $paths[] = (string) $document['pdf_path'];
        }

        foreach (DocumentPage::forDocument($id) as $page) {
            $paths[] = (string) $page['image_path'];
        }

        $absolute = [];

        foreach ($paths as $relative) {
            $path = \App\Services\IngestStage::absolutePath($relative);

            if ($path !== null) {
                $absolute[] = $path;
            }
        }

        return $absolute;
    }

    /** @param array<int,string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * A page of documents, newest first, optionally filtered.
     *
     * @param array{status?:string,q?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public static function paginate(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::filterClause($filters, 'd');

        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        // The Clear Books link comes along for the ride rather than being looked
        // up per row: on a list filtered to `submitted` every row wants it, and
        // it is the only way to reach a record's project code.
        return Database::select(
            'SELECT d.*,
                    s.clearbooks_id,
                    s.clearbooks_type,
                    s.clearbooks_url
               FROM documents d
               LEFT JOIN submissions s
                 ON s.id = (SELECT id FROM submissions
                             WHERE document_id = d.id AND status = \'success\' AND clearbooks_id IS NOT NULL
                             ORDER BY submitted_at DESC, id DESC LIMIT 1)'
            . $where
            . ' ORDER BY d.created_at DESC, d.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    }

    /** @param array{status?:string,q?:string} $filters */
    public static function countMatching(array $filters = []): int
    {
        // The same alias `paginate()` uses, and not optional.
        //
        // It used to pass none, which was harmless while every condition named
        // a column of `documents` — and stopped being harmless the moment one
        // of them correlated a subquery on `extractions`. An unqualified `id`
        // inside that subquery resolves to the *inner* table, so the EXISTS
        // became a self-comparison that is true for any row and every document
        // matched. The count said five, the list showed three, and neither was
        // obviously the wrong one.
        [$where, $params] = self::filterClause($filters, 'd');

        return (int) Database::scalar('SELECT COUNT(*) FROM documents d' . $where, $params);
    }

    /**
     * @param array{status?:string,q?:string} $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function filterClause(array $filters, string $alias): array
    {
        /*
         * The alias is required, not optional.
         *
         * `status` exists on both `documents` and `submissions`, so an
         * unqualified one is an error rather than a wrong answer — loud, and
         * therefore fine. The correlated subqueries below are the dangerous
         * case: an unqualified `id` inside one resolves to the *inner* table
         * and the subquery silently stops correlating at all. That is a wrong
         * answer with no error, so the prefix is not allowed to be empty.
         */
        if ($alias === '') {
            throw new \InvalidArgumentException('filterClause needs a table alias to correlate against.');
        }

        $prefix     = $alias . '.';
        $conditions = [];
        $params     = [];

        $status = $filters['status'] ?? '';
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $conditions[] = $prefix . 'status = ?';
            $params[]     = $status;
        }

        $docType = trim((string) ($filters['doc_type'] ?? ''));
        if ($docType !== '') {
            $conditions[] = $prefix . 'doc_type = ?';
            $params[]     = $docType;
        }

        $supplier = trim((string) ($filters['supplier'] ?? ''));
        if ($supplier !== '') {
            $conditions[] = $prefix . 'supplier_raw LIKE ?';
            $params[]     = '%' . self::escapeLike($supplier) . '%';
        }

        /*
         * The date range is the document's own date where there is one, and the
         * date InvoGrid heard about it where there is not.
         *
         * "Show me July" means the invoices dated July, not the ones that
         * happened to be scanned then — but a document that has not been read
         * yet has no invoice date at all, and dropping those out of the range
         * would hide exactly the ones somebody hunting for a missing invoice is
         * looking for.
         */
        $dated = 'COALESCE((SELECT e.invoice_date FROM extractions e
                             WHERE e.document_id = ' . $prefix . 'id
                             ORDER BY e.id DESC LIMIT 1), DATE(' . $prefix . 'created_at))';

        $from = trim((string) ($filters['from'] ?? ''));
        if (self::isDate($from)) {
            $conditions[] = $dated . ' >= ?';
            $params[]     = $from;
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if (self::isDate($to)) {
            $conditions[] = $dated . ' <= ?';
            $params[]     = $to;
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            // A bare number is InvoGrid's own document number — the one printed
            // on the summary somebody is holding. A word is a supplier or an
            // invoice number they are looking for.
            if (ctype_digit($search)) {
                $conditions[] = $prefix . 'id = ?';
                $params[]     = (int) $search;
            } else {
                $like = '%' . self::escapeLike($search) . '%';

                /*
                 * EXISTS rather than a join: a document has one extraction per
                 * run, and joining would return the same document once per run
                 * — so re-reading a document would make it appear twice in a
                 * list and three times after the next retry.
                 */
                $conditions[] = '(' . $prefix . 'supplier_raw LIKE ?
                    OR ' . $prefix . 'original_filename LIKE ?
                    OR EXISTS (SELECT 1 FROM extractions e
                                WHERE e.document_id = ' . $prefix . 'id
                                  AND (e.supplier_name_raw LIKE ?
                                       OR e.invoice_number LIKE ?
                                       OR e.document_title LIKE ?)))';

                // One per placeholder above: supplier, filename, and the three
                // inside the EXISTS.
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * The supplier names that actually appear on documents.
     *
     * A list to pick from rather than a name to spell: "Acme Supplies Ltd" and
     * "Acme Supplies Limited" are two different filters and only one of them
     * finds anything.
     *
     * @return array<int,string>
     */
    public static function supplierNames(): array
    {
        return array_column(Database::select(
            "SELECT DISTINCT supplier_raw
               FROM documents
              WHERE supplier_raw IS NOT NULL AND supplier_raw <> ''
              ORDER BY supplier_raw
              LIMIT 500"
        ), 'supplier_raw');
    }

    /** A wildcard the user typed is a literal, not a search over everything. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $value);
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Documents that have sat in one place longer than they should have.
     *
     * Two thresholds, because there are two kinds of waiting. A document in
     * `extracting` is waiting on a machine and should move in minutes; one in
     * `needs_review` is waiting on a person and may legitimately sit over a
     * weekend. One number covering both would either cry wolf about the second
     * or say nothing about the first — and a dashboard that cries wolf is a
     * dashboard nobody reads.
     *
     * `failed` is deliberately absent: it has its own count, and a document
     * that has stopped is not stuck, it is finished badly.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function stuck(int $pipelineMinutes = 30, int $reviewDays = 7, int $limit = 20): array
    {
        // `existing_invoice` is a machine wait now that the linking stage
        // consumes it: a document sitting in it for half an hour means the
        // queue is not running, exactly as one sitting in `extracted` does. It
        // was absent from both lists while nothing ran that status, because
        // every document there was waiting by design.
        //
        // `needs_link` is the human half of the same flow, and belongs beside
        // `needs_review` for the same reason: it is waiting on a person who is
        // entitled to a weekend.
        $machine = [
            self::RECEIVED, self::OCR_PENDING, self::OCR_DONE,
            self::EXTRACTING, self::EXTRACTED, self::MATCHING, self::EXISTING_INVOICE,
        ];
        // `possible_duplicate` is a human wait too. Nothing runs it, by design
        // — the machine has already compared what it can, and what is left is
        // the judgement it refused to make.
        $human = [self::NEEDS_REVIEW, self::READY_TO_SUBMIT, self::NEEDS_LINK, self::POSSIBLE_DUPLICATE];

        return Database::select(
            'SELECT *,
                    TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS waiting_minutes
               FROM documents
              WHERE (status IN (' . self::placeholders($machine) . ')
                     AND updated_at < (NOW() - INTERVAL ? MINUTE))
                 OR (status IN (' . self::placeholders($human) . ')
                     AND updated_at < (NOW() - INTERVAL ? DAY))
              ORDER BY updated_at
              LIMIT ' . max(1, min(100, $limit)),
            [...$machine, max(1, $pipelineMinutes), ...$human, max(1, $reviewDays)]
        );
    }
}
