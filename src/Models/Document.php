<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;
use RuntimeException;

/**
 * A Paperless document making its way through the pipeline.
 *
 * The status constants and the transition map are the state machine itself.
 * They live here rather than being written out at each call site so that adding
 * a stage later is one edit, and so that an impossible transition is caught
 * rather than quietly stored.
 */
final class Document
{
    public const RECEIVED        = 'received';
    public const OCR_PENDING     = 'ocr_pending';
    public const OCR_DONE        = 'ocr_done';
    public const EXTRACTING      = 'extracting';
    public const EXTRACTED       = 'extracted';
    public const MATCHING        = 'matching';
    public const NEEDS_REVIEW    = 'needs_review';
    public const READY_TO_SUBMIT = 'ready_to_submit';
    public const SUBMITTED       = 'submitted';
    public const FAILED          = 'failed';
    public const IGNORED         = 'ignored';

    /**
     * Every status, in pipeline order. The two terminal-ish states come last
     * because that is how the dashboard reads them out.
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
        self::NEEDS_REVIEW,
        self::READY_TO_SUBMIT,
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
     * @var array<string,array<int,string>>
     */
    public const TRANSITIONS = [
        self::RECEIVED        => [self::OCR_PENDING, self::FAILED, self::IGNORED],
        self::OCR_PENDING     => [self::OCR_DONE, self::FAILED, self::IGNORED],
        self::OCR_DONE        => [self::EXTRACTING, self::FAILED, self::IGNORED],
        self::EXTRACTING      => [self::EXTRACTED, self::FAILED, self::IGNORED],
        self::EXTRACTED       => [self::MATCHING, self::FAILED, self::IGNORED],
        self::MATCHING        => [self::NEEDS_REVIEW, self::READY_TO_SUBMIT, self::FAILED, self::IGNORED],
        self::NEEDS_REVIEW    => [self::READY_TO_SUBMIT, self::MATCHING, self::FAILED, self::IGNORED],
        self::READY_TO_SUBMIT => [self::SUBMITTED, self::NEEDS_REVIEW, self::FAILED, self::IGNORED],
        self::SUBMITTED       => [self::IGNORED],

        // A retry puts the document back at the head of the stage that failed,
        // so every working state is a legitimate destination from here.
        self::FAILED          => [
            self::RECEIVED, self::OCR_PENDING, self::OCR_DONE, self::EXTRACTING,
            self::EXTRACTED, self::MATCHING, self::NEEDS_REVIEW,
            self::READY_TO_SUBMIT, self::IGNORED,
        ],
        self::IGNORED         => [self::RECEIVED],
    ];

    /** Human labels, for the dashboard and the queue. */
    public const LABELS = [
        self::RECEIVED        => 'Received',
        self::OCR_PENDING     => 'Reading pages',
        self::OCR_DONE        => 'Read',
        self::EXTRACTING      => 'Extracting',
        self::EXTRACTED       => 'Extracted',
        self::MATCHING        => 'Matching',
        self::NEEDS_REVIEW    => 'Needs review',
        self::READY_TO_SUBMIT => 'Ready to submit',
        self::SUBMITTED       => 'Submitted',
        self::FAILED          => 'Failed',
        self::IGNORED         => 'Ignored',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
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

    /** @return array<string,mixed>|null */
    public static function findByPaperlessId(int $paperlessId): ?array
    {
        return Database::selectOne('SELECT * FROM documents WHERE paperless_doc_id = ?', [$paperlessId]);
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
     * Register a Paperless document, or return the one already registered.
     *
     * The unique key on `paperless_doc_id` is what actually guarantees this:
     * two webhook deliveries arriving at once would both pass a "does it exist"
     * check, and only the database can settle that race. The duplicate-key
     * error is caught and turned back into the existing row.
     *
     * @return array{document:array<string,mixed>,created:bool}
     */
    public static function register(int $paperlessDocId): array
    {
        $existing = self::findByPaperlessId($paperlessDocId);

        if ($existing !== null) {
            return ['document' => $existing, 'created' => false];
        }

        try {
            $id = Database::insert('documents', [
                'paperless_doc_id' => $paperlessDocId,
                'status'           => self::RECEIVED,
            ]);
        } catch (PDOException $e) {
            // 23000 is the integrity-constraint class; the unique index on
            // paperless_doc_id is the only one this insert can violate.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $row = self::findByPaperlessId($paperlessDocId);

            if ($row === null) {
                throw $e;
            }

            return ['document' => $row, 'created' => false];
        }

        $row = self::find($id);

        if ($row === null) {
            throw new RuntimeException('Document ' . $id . ' vanished immediately after being created.');
        }

        return ['document' => $row, 'created' => true];
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

        // Only overwritten when the extraction actually found a name: what
        // Paperless already believed is better than nothing.
        if ($supplierName !== null && trim($supplierName) !== '') {
            $fields['correspondent_raw'] = mb_substr(trim($supplierName), 0, 255);
        }

        if ($fields !== []) {
            Database::update('documents', $fields, $id);
        }
    }

    /**
     * The supplier the matching stage settled on, copied onto the document.
     *
     * `correspondent_matched_supplier_id` holds a **Clear Books** remote id, not
     * a row in this database — Clear Books owns those identifiers. Nulling it
     * when nothing matched is deliberate: a stale id left behind from an earlier
     * pass is exactly the kind of thing a submission would use without noticing.
     */
    public static function setMatchedSupplier(int $id, ?string $clearbooksId, ?string $name = null): void
    {
        $fields = ['correspondent_matched_supplier_id' => $clearbooksId];

        // The name on file beats whatever was read off the letterhead, but
        // only when there is one — an unmatched supplier keeps what the
        // extraction read, which is all anybody has to go on.
        if ($name !== null && trim($name) !== '') {
            $fields['correspondent_raw'] = mb_substr(trim($name), 0, 255);
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
                    e.paperless_title,
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

        $correspondent = trim((string) ($filters['correspondent'] ?? ''));
        if ($correspondent !== '') {
            $conditions[] = $prefix . 'correspondent_raw LIKE ?';
            $params[]     = '%' . self::escapeLike($correspondent) . '%';
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
            // A number is almost always somebody pasting a Paperless id; a word
            // is a supplier or an invoice number they are looking for.
            if (ctype_digit($search)) {
                $conditions[] = '(' . $prefix . 'paperless_doc_id = ? OR ' . $prefix . 'id = ?)';
                $params[]     = (int) $search;
                $params[]     = (int) $search;
            } else {
                $like = '%' . self::escapeLike($search) . '%';

                /*
                 * EXISTS rather than a join: a document has one extraction per
                 * run, and joining would return the same document once per run
                 * — so re-reading a document would make it appear twice in a
                 * list and three times after the next retry.
                 */
                $conditions[] = '(' . $prefix . 'correspondent_raw LIKE ?
                    OR EXISTS (SELECT 1 FROM extractions e
                                WHERE e.document_id = ' . $prefix . 'id
                                  AND (e.supplier_name_raw LIKE ?
                                       OR e.invoice_number LIKE ?
                                       OR e.paperless_title LIKE ?)))';

                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * The correspondents that actually appear on documents.
     *
     * A list to pick from rather than a name to spell: "Acme Supplies Ltd" and
     * "Acme Supplies Limited" are two different filters and only one of them
     * finds anything.
     *
     * @return array<int,string>
     */
    public static function correspondents(): array
    {
        return array_column(Database::select(
            "SELECT DISTINCT correspondent_raw
               FROM documents
              WHERE correspondent_raw IS NOT NULL AND correspondent_raw <> ''
              ORDER BY correspondent_raw
              LIMIT 500"
        ), 'correspondent_raw');
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
        $machine = [
            self::RECEIVED, self::OCR_PENDING, self::OCR_DONE,
            self::EXTRACTING, self::EXTRACTED, self::MATCHING,
        ];
        $human = [self::NEEDS_REVIEW, self::READY_TO_SUBMIT];

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
