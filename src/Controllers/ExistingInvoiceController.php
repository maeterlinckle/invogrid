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
use App\Models\Submission;
use App\Services\IngestStage;
use App\Services\InvoiceMatcher;
use App\Services\LinkStage;
use App\Services\MatchStage;
use Throwable;

/**
 * The Existing Invoice queue.
 *
 * A document arrives here fully extracted, having run the same pipeline as
 * every other, and stopped at the last step: the handwritten Clearbooks Number
 * did not settle on exactly one Clear Books record whose invoice date and gross
 * total match the extraction exactly. No such number, more than one, or a
 * checksum that did not hold.
 *
 * **Nothing auto-resolves from here.** The stage has had its go; what is left
 * is a judgement, and there are exactly three answers to it:
 *
 *  - **Link it.** The number field arrives holding whatever was read off the
 *    page, because a misread digit is the commonest reason to be here and
 *    correcting one character is the commonest fix. The lookup and the checksum
 *    run again, and a checksum that still does not hold is *shown* rather than
 *    enforced — a person holding the scan is the better authority, which is the
 *    same rule the review screen holds to about the extraction's numbers.
 *  - **Treat it as a new invoice.** The number was somebody else's reference, a
 *    stock code, a purchase order. Nothing is re-read: the route is flipped and
 *    the matching stage re-run, so the document keeps everything it has and
 *    lands in the ordinary review queue.
 *  - **Delete it.** A duplicate scan, a second copy of something already filed.
 *    Irreversible, needs a reason, and the reason outlives the document.
 *
 * Why not a fourth "look it up again": that is the first action with the field
 * left alone. The commonest cause of a number matching nothing is a record
 * entered in Clear Books since the last sync, and pressing Link without editing
 * anything runs exactly the lookup the stage ran, against a table that has
 * moved on.
 */
final class ExistingInvoiceController extends Controller
{
    private const PER_PAGE = 25;

    /** The queue. */
    public function index(): void
    {
        $page  = max(1, (int) Request::query('page', 1));
        $total = Document::countWithStatuses([Document::NEEDS_LINK]);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);

        $this->view('existing/index', [
            'pageTitle' => 'Existing invoices',

            // A queue is a table read on a monitor, not a column of prose:
            // see the layout for what `wide` does to the content column.
            'wide'       => true,
            'rows'      => Document::linkQueue(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,

            // What is still on its way through the lookup, so an empty queue is
            // distinguishable from a queue nothing has reached yet.
            'looking'   => Document::countWithStatuses([Document::EXISTING_INVOICE]),
            'synced'    => ClearbooksInvoice::summary(),
        ]);
    }

    /** One document: the scan, the number, and what the lookup makes of it. */
    public function show(string $id): void
    {
        $document   = $this->load($id);
        $documentId = (int) $document['id'];

        $ocr        = OcrResult::latest($documentId);
        $extraction = Extraction::latest($documentId);
        $number     = $ocr === null ? null : OcrResult::clearbooksNumber($ocr);

        /*
         * The lookup is re-run for the screen rather than read off the event
         * the stage recorded.
         *
         * The two can legitimately differ, and when they do the fresher one is
         * the one worth showing: the invoice sync runs on a schedule, so a
         * record entered in Clear Books after the document was read appears
         * here the moment somebody opens the page. An event saying "matches
         * nothing" that was true an hour ago is exactly the thing that would
         * send somebody off to retype a number that was already right.
         */
        $lookup = $number === null ? null : InvoiceMatcher::lookup($number);
        $check  = null;

        if ($lookup !== null && $lookup['outcome'] === InvoiceMatcher::MATCHED && $extraction !== null) {
            $check = InvoiceMatcher::check($lookup['invoice'], $extraction);
        }

        $pdf = $document['pdf_path'] === null
            ? null
            : IngestStage::absolutePath((string) $document['pdf_path']);

        $this->view('existing/show', [
            'pageTitle'  => 'Existing invoice #' . $documentId,
            'wide'       => true,
            'document'   => $document,
            'ocr'        => $ocr,
            'extraction' => $extraction,
            'number'     => $number,
            'lookup'     => $lookup,
            'check'      => $check,
            'events'     => DocumentEvent::forDocument($documentId),

            // The rendered pages, so this screen shows the scan the same
            // way the review screen does: images first, PDF on request.
            'pages'      => DocumentPage::forDocument($documentId),
            'hasPdf'     => $pdf !== null,
            'synced'     => ClearbooksInvoice::summary(),
        ]);
    }

    /**
     * Link this document to a Clear Books record, by number.
     *
     * The number is taken from the form rather than from the OCR result even
     * when it has not been edited, because the two are the same thing when it
     * has not — and reading the form is what makes the corrected case work
     * without a second action.
     *
     * `ocr_results.clearbooks_number` is **not** rewritten with a corrected
     * value. That column is the record of what a model read off a page, the
     * same as an extraction is; overwriting it would destroy the evidence that
     * the reading was wrong, which is the only way anybody would ever notice
     * the prompt needed work. Where the correction lives is the submission's
     * `clearbooksNumber`, the audit row and the event.
     */
    public function link(string $id): void
    {
        $document   = $this->load($id);
        $documentId = (int) $document['id'];

        $number = trim(ltrim(trim((string) Request::post('clearbooks_number', '')), '#'));

        if (!OcrResult::isUsableNumber($number)) {
            Flash::error($number === ''
                ? 'Type the Clear Books number this document belongs to.'
                : '"' . $number . '" is not a Clear Books number — it has to be digits only.');
            Response::redirect('/existing/' . $documentId);
        }

        $extraction = Extraction::latest($documentId);

        if ($extraction === null) {
            Flash::error('This document has nothing extracted, so there is no date or total to check a '
                . 'Clear Books record against. Send it down the New Invoice route to have it read properly.');
            Response::redirect('/existing/' . $documentId);
        }

        /*
         * `$overridable` is the whole difference between this and the stage.
         *
         * The checksum is exact and unforgiving on purpose, and the stage
         * refuses anything it does not pass, because nobody has looked. Here
         * somebody has: they are holding the scan, the record's date and total
         * are on screen beside what was extracted, and they typed the number. A
         * checksum that overruled them would leave the queue with no way out
         * for the case it exists to handle — a record keyed in on a different
         * day, or rounded when it was entered.
         *
         * That is the trade the exactness buys: the machine never guesses, and
         * a person always can. What the person overrode is recorded on the
         * submission, so the decision is not invisible afterwards.
         *
         * The lookup itself is *not* overridable. A number matching nothing, or
         * matching two records, is not a judgement call — there is no record to
         * link to, or no way to tell which.
         */
        try {
            $status = LinkStage::attempt($documentId, $number, $extraction, 'by hand', true);
        } catch (Throwable $e) {
            Flash::error('Nothing was linked. ' . $e->getMessage());
            Response::redirect('/existing/' . $documentId);
        }

        if ($status !== Document::SUBMITTED) {
            // `attempt()` has already recorded why on the document; the queue
            // page will show it. Saying it again here saves a click.
            $event = DocumentEvent::forDocument($documentId)[0] ?? null;

            Flash::warning('That number did not resolve to a single Clear Books record, so nothing was linked. '
                . ($event === null ? '' : (string) $event['message']));
            Response::redirect('/existing/' . $documentId);
        }

        $submission = Submission::successful($documentId);
        $ocr        = OcrResult::latest($documentId);

        Flash::success(sprintf(
            'Linked to Clear Books %s, and the PDF is on the record.%s',
            $submission === null ? 'record' : (string) $submission['clearbooks_id'],
            $ocr !== null && $number === OcrResult::clearbooksNumber($ocr)
                ? ''
                : ' The number read off the page is kept as it was read — the correction is on the record.'
        ));

        Response::redirect('/documents/' . $documentId);
    }

    /**
     * Send this document down the New Invoice route instead.
     *
     * The number was not a Clear Books reference after all — a stock code, a
     * purchase order, somebody else's number — so this is an ordinary bill to
     * post.
     *
     * **Nothing is re-read or re-extracted.** Both flows run the identical
     * pipeline, so this document already has its transcription, its extraction
     * and its entity matches; the only thing that was different about it was
     * where the matching stage sent it at the end. So the route is flipped and
     * the matching stage is re-run through `MatchStage::recheck()`, which takes
     * the other exit and lands the document in the ordinary review queue with
     * everything it already had. Re-deciding through the one implementation is
     * the point: a second copy of "where does this document go now" would
     * disagree with the stage eventually, and invisibly.
     */
    public function pushToNew(string $id): void
    {
        $document   = $this->load($id);
        $documentId = (int) $document['id'];
        $number     = $this->numberOf($documentId);

        // Before the re-match, because the re-match is what reads it.
        Document::setRoute($documentId, Document::ROUTE_NEW);

        try {
            $status = MatchStage::recheck($documentId);
        } catch (Throwable $e) {
            // Put the route back: a failed re-match must not leave a document
            // claiming to be a new invoice while it sits in the linking queue.
            Document::setRoute($documentId, Document::ROUTE_EXISTING);

            Flash::error('Nothing was moved. ' . $e->getMessage());
            Response::redirect('/existing/' . $documentId);
        }

        AuditLog::record('document.route_changed', $documentId, sprintf(
            '%s moved this onto the New Invoice route: the Clearbooks Number %s is not a Clear Books '
            . 'reference. It keeps everything already extracted and is now %s.',
            Auth::displayName(),
            $number,
            strtolower(Document::label($status))
        ));

        DocumentEvent::record($documentId, 'route', DocumentEvent::SUCCEEDED,
            'Moved to the New Invoice flow by ' . Auth::displayName() . '.');

        Flash::success(sprintf(
            'Moved onto the New Invoice route. It kept everything already extracted, and is %s.',
            $status === Document::READY_TO_SUBMIT
                ? 'ready to submit'
                : 'in the review queue'
        ));

        Response::redirect(
            in_array($status, [Document::NEEDS_REVIEW, Document::READY_TO_SUBMIT], true)
                ? '/review/' . $documentId
                : '/documents/' . $documentId
        );
    }

    /**
     * Delete the document outright.
     *
     * The only irreversible thing on this screen, and the reason is required
     * for the same reason the review screen's ignore action requires one: six
     * months later somebody will ask what happened to a scan they remember
     * uploading, and "deleted by nick" is not an answer.
     *
     * The audit row is written **before** the delete. `audit_log.document_id`
     * is `ON DELETE SET NULL`, so the row survives with its link nulled — which
     * is why the document's own number and filename go into the text, where a
     * null column cannot take them away.
     */
    public function delete(string $id): void
    {
        $document   = $this->load($id);
        $documentId = (int) $document['id'];

        $reason = trim((string) Request::post('reason', ''));

        if (mb_strlen($reason) < 3) {
            Flash::error('Say why it is being deleted — the document is about to stop existing, and this is '
                . 'the only thing that will be left of it.');
            Response::redirect('/existing/' . $documentId);
        }

        AuditLog::record('document.deleted', $documentId, sprintf(
            'Document #%d (%s, arrived %s, Clearbooks Number %s) was deleted by %s. Reason: %s',
            $documentId,
            $document['original_filename'] ?? 'no filename',
            format_datetime((string) ($document['ingested_at'] ?? $document['created_at'])),
            $this->numberOf($documentId),
            Auth::displayName(),
            mb_substr($reason, 0, 900)
        ));

        try {
            $removed = Document::delete($documentId);
        } catch (Throwable $e) {
            Flash::error('Nothing was deleted. ' . $e->getMessage());
            Response::redirect('/existing/' . $documentId);
        }

        Flash::success(sprintf(
            'Document #%d is gone, along with %d file%s. The activity log keeps the record of it.',
            $documentId,
            $removed['files'],
            $removed['files'] === 1 ? '' : 's'
        ));

        Response::redirect('/existing');
    }

    // --- Plumbing -----------------------------------------------------------

    /**
     * The document, or a 404 — and it has to actually be in this queue.
     *
     * The status is checked here rather than only in the template, because
     * every action below changes something and a stale tab is a real way to
     * reach one. A document that has since been linked, deleted or pushed the
     * other way must not be actionable from a page that predates it.
     *
     * @return array<string,mixed>
     */
    private function load(string $id): array
    {
        $document = Document::find((int) $id);

        if ($document === null) {
            $this->notFound('No such document.');
        }

        if ((string) $document['status'] !== Document::NEEDS_LINK) {
            Flash::info(sprintf(
                'Document #%d is %s, not waiting to be linked.',
                (int) $document['id'],
                strtolower(Document::label((string) $document['status']))
            ));

            Response::redirect('/documents/' . (int) $document['id']);
        }

        return $document;
    }

    /** The number read off the page, for a sentence in the log. */
    private function numberOf(int $documentId): string
    {
        $ocr    = OcrResult::latest($documentId);
        $number = $ocr === null ? null : OcrResult::clearbooksNumber($ocr);

        return $number ?? 'none';
    }
}
