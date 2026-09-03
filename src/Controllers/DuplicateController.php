<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\ClearbooksInvoice;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentPage;
use App\Models\Extraction;
use App\Models\OcrResult;
use App\Services\DuplicateMatcher;
use App\Services\IngestStage;
use App\Services\MatchStage;
use Throwable;

/**
 * The duplicate queue: New Invoice documents that may already be in Clear Books.
 *
 * A document arrives here having run the whole pipeline and resolved — or
 * failed to resolve — its entities exactly like any other new invoice. What
 * stopped it is the gate at the end of the matching stage: what was extracted
 * from it looks like a purchase document Clear Books already holds. It carries
 * **no handwritten Clearbooks Number**, which is why nothing short-circuited it
 * onto the Existing Invoice route; the annotation is missing, not the record.
 *
 * **The screen is a side-by-side and nothing else.** InvoGrid's reading of the
 * scan on one side, the Clear Books record on the other, the four things that
 * were compared between them, and the PDF itself underneath. That is the whole
 * design: the machine cannot tell these apart — if it could, the document would
 * not be here — so the only useful thing to build is the view that lets a
 * person tell them apart in ten seconds.
 *
 * **Nothing auto-resolves from here**, exactly as on the Existing Invoice
 * queue, and there are two answers rather than three:
 *
 *  - **It is the same document.** Delete it. Irreversible, needs a reason, and
 *    the reason outlives the document in the activity log.
 *  - **It is genuinely new.** The decision is stamped on the document and the
 *    matching stage is re-run, which takes a different exit because the gate
 *    reads that stamp. The document lands in the ordinary review queue or at
 *    ready-to-submit, keeping everything it already had.
 *
 * There is deliberately no third "link the scan to that record instead". It
 * would be a second implementation of the Existing Invoice route sitting on a
 * screen that is about a different question, and the path already exists
 * without one: push the document on, and the document page's reset control
 * offers `existing_invoice` from where it lands.
 */
final class DuplicateController extends Controller
{
    private const PER_PAGE = 25;

    /** The queue. */
    public function index(): void
    {
        $page  = max(1, (int) Request::query('page', 1));
        $total = Document::countWithStatuses([Document::POSSIBLE_DUPLICATE]);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);

        $this->view('duplicates/index', [
            'pageTitle' => 'Possible duplicates',

            // A queue is a table read on a monitor, not a column of prose:
            // see the layout for what `wide` does to the content column.
            'wide'       => true,
            'rows'      => Document::duplicateQueue(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'synced'    => ClearbooksInvoice::summary(),
        ]);
    }

    /** One document, beside every Clear Books record it might already be. */
    public function show(string $id): void
    {
        [$document, $extraction] = $this->load($id);
        $documentId = (int) $document['id'];

        /*
         * The comparison is re-run when the page is opened rather than read
         * back off the event the stage recorded — the same rule the Existing
         * Invoice screen holds to about its lookup, and for the same reason.
         * The invoice sync runs on a schedule, so the record that made this
         * document stop may have been edited, or deleted from Clear Books, or
         * joined by a second one, since. An hour-old opinion is exactly what
         * would have somebody deleting a document against a record that no
         * longer says what the event says it says.
         */
        $result = DuplicateMatcher::against($document, $extraction);

        $pdf = $document['pdf_path'] === null
            ? null
            : IngestStage::absolutePath((string) $document['pdf_path']);

        $this->view('duplicates/show', [
            'pageTitle'  => 'Possible duplicate #' . $documentId,
            'wide'       => true,
            'document'   => $document,
            'extraction' => $extraction,
            'ocr'        => OcrResult::latest($documentId),
            'candidates' => $result['candidates'],
            'plausible'  => $result['plausible'],
            'comparable' => DuplicateMatcher::comparable($extraction),
            'events'     => DocumentEvent::forDocument($documentId),

            // The rendered pages, so this screen shows the scan the same
            // way the review screen does: images first, PDF on request.
            'pages'      => DocumentPage::forDocument($documentId),
            'hasPdf'     => $pdf !== null,
            'synced'     => ClearbooksInvoice::summary(),
        ]);
    }

    /**
     * Confirm the document is genuinely new, and push it on.
     *
     * Two steps in one gesture, and the order matters: the stamp goes on
     * **before** the re-match, because the re-match is what reads it. Reversed,
     * the stage would run the duplicate check again, find the same records and
     * put the document straight back where it came from.
     *
     * Nothing is re-read or re-extracted. This document already has its
     * transcription, its extraction and its entity matches — the gate stopped
     * it at the *end* of the matching stage, after all of that had been done
     * and written down. So the route through is `MatchStage::recheck()`, which
     * re-runs the one implementation of "where does this document go now" and
     * takes a different exit for a different reason. A second copy of that
     * decision living on this screen would disagree with the stage eventually,
     * and invisibly.
     */
    public function notDuplicate(string $id): void
    {
        [$document] = $this->load($id);
        $documentId = (int) $document['id'];

        $reason = trim((string) Request::post('reason', ''));

        Document::clearDuplicate($documentId, Auth::id());

        try {
            $status = MatchStage::recheck($documentId);
        } catch (Throwable $e) {
            Flash::error('Nothing was moved. ' . $e->getMessage());
            Response::redirect('/duplicates/' . $documentId);
        }

        /*
         * A reason is offered here and required on the delete, which is the
         * asymmetry the two actions deserve. Confirming a document is new sends
         * it on to be reviewed by somebody who will see it again; deleting one
         * is the last time anybody sees it at all.
         */
        AuditLog::record('document.duplicate_cleared', $documentId, sprintf(
            '%s confirmed this is genuinely new rather than a copy of something already in Clear '
            . 'Books. It kept everything already extracted and is now %s.%s',
            Auth::displayName(),
            strtolower(Document::label($status)),
            $reason === '' ? '' : ' Reason: ' . mb_substr($reason, 0, 900)
        ));

        DocumentEvent::record($documentId, 'dedup', DocumentEvent::SUCCEEDED, sprintf(
            'Confirmed as genuinely new by %s.%s',
            Auth::displayName(),
            $reason === '' ? '' : ' ' . mb_substr($reason, 0, 500)
        ));

        Flash::success(sprintf(
            'Recorded as genuinely new, and it will not be stopped for this again. It kept everything '
            . 'already extracted, and is %s.',
            $status === Document::READY_TO_SUBMIT ? 'ready to submit' : 'in the review queue'
        ));

        Response::redirect(
            in_array($status, [Document::NEEDS_REVIEW, Document::READY_TO_SUBMIT], true)
                ? '/review/' . $documentId
                : '/documents/' . $documentId
        );
    }

    /**
     * It is the same invoice. Delete the InvoGrid document.
     *
     * The same action the Existing Invoice queue offers, reached from the other
     * queue and for the commonest reason there is: this is a second copy of
     * something already filed.
     *
     * The reason is required for the same reason it is there, and the audit row
     * is written **before** the delete — `audit_log.document_id` is
     * `ON DELETE SET NULL`, so the row survives with its link nulled, which is
     * why the document's own number and filename go into the text where a null
     * column cannot take them away. What is added here is the Clear Books
     * record it was judged a duplicate *of*: six months later the question is
     * not only "what happened to that scan" but "and which invoice is it, then".
     */
    public function delete(string $id): void
    {
        [$document, $extraction] = $this->load($id);
        $documentId = (int) $document['id'];

        $reason = trim((string) Request::post('reason', ''));

        if (mb_strlen($reason) < 3) {
            Flash::error('Say why it is being deleted — the document is about to stop existing, and this '
                . 'is the only thing that will be left of it.');
            Response::redirect('/duplicates/' . $documentId);
        }

        AuditLog::record('document.deleted', $documentId, sprintf(
            'Document #%d (%s, arrived %s, %s, %s) was deleted by %s as a duplicate of what Clear Books '
            . 'already holds. Candidates at the time: %s. Reason: %s',
            $documentId,
            $document['original_filename'] ?? 'no filename',
            format_datetime((string) ($document['ingested_at'] ?? $document['created_at'])),
            $extraction['invoice_number'] === null
                ? 'no supplier reference'
                : 'reference ' . $extraction['invoice_number'],
            format_money($extraction['gross_amount'] ?? null, $extraction['currency'] ?? null),
            Auth::displayName(),
            $this->named($document, $extraction),
            mb_substr($reason, 0, 900)
        ));

        try {
            $removed = Document::delete($documentId);
        } catch (Throwable $e) {
            Flash::error('Nothing was deleted. ' . $e->getMessage());
            Response::redirect('/duplicates/' . $documentId);
        }

        Flash::success(sprintf(
            'Document #%d is gone, along with %d file%s. The activity log keeps the record of it, and '
            . 'names the Clear Books document it duplicated.',
            $documentId,
            $removed['files'],
            $removed['files'] === 1 ? '' : 's'
        ));

        Response::redirect('/duplicates');
    }

    // --- Plumbing -----------------------------------------------------------

    /**
     * The document and its extraction, or somewhere else entirely.
     *
     * The status is checked here rather than only in the template, because both
     * actions below change something and a stale tab is a real way to reach
     * one. A document that has since been pushed on, deleted or ignored must
     * not be actionable from a page that predates it.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function load(string $id): array
    {
        $document = Document::find((int) $id);

        if ($document === null) {
            $this->notFound('No such document.');
        }

        if ((string) $document['status'] !== Document::POSSIBLE_DUPLICATE) {
            Flash::info(sprintf(
                'Document #%d is %s, not waiting on a duplicate decision.',
                (int) $document['id'],
                strtolower(Document::label((string) $document['status']))
            ));

            Response::redirect('/documents/' . (int) $document['id']);
        }

        $extraction = Extraction::latest((int) $document['id']);

        if ($extraction === null) {
            // Not reachable through the pipeline — the gate runs on an
            // extraction and cannot fire without one — but the row can be
            // deleted from underneath a document by hand.
            $this->notFound(
                'That document has nothing extracted, so there is nothing to compare against Clear Books.'
            );
        }

        return [$document, $extraction];
    }

    /**
     * The Clear Books records this document is being deleted against, named.
     *
     * Re-run rather than read off the event, so the log records what the person
     * pressing the button was actually looking at.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $extraction
     */
    private function named(array $document, array $extraction): string
    {
        $plausible = DuplicateMatcher::against($document, $extraction)['plausible'];

        if ($plausible === []) {
            return 'none by the time it was deleted';
        }

        return implode(', ', array_map(
            static fn (array $c): string => (string) (
                $c['invoice']['document_number'] ?? $c['invoice']['clearbooks_id']
            ),
            $plausible
        ));
    }
}
