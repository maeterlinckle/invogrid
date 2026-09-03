<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentPage;
use App\Models\DocumentType;
use App\Models\EntityMatch;
use App\Models\Extraction;
use App\Models\OcrResult;
use App\Models\PipelineJob;
use App\Models\Submission;
use App\Services\FieldIssues;
use App\Services\IngestStage;
use App\Services\Pipeline;
use App\Services\SubmitStage;
use Throwable;

/**
 * The raw document list and one document's detail.
 *
 * This is the plumbing view, not the review queue — that arrives in a later
 * stage with the PDF beside the extracted data. What this is for is watching
 * the pipeline while the rest of it is being built: what came in, where it got
 * to, and what it said when it broke.
 */
final class DocumentController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): void
    {
        $filters = [
            'status'        => (string) Request::query('status', ''),
            'doc_type'      => (string) Request::query('doc_type', ''),
            'supplier' => (string) Request::query('supplier', ''),
            'from'          => (string) Request::query('from', ''),
            'to'            => (string) Request::query('to', ''),
            'q'             => (string) Request::query('q', ''),
        ];

        $page   = max(1, (int) Request::query('page', 1));
        $total  = Document::countMatching($filters);
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = min($page, $pages);

        $this->view('documents/index', [
            'pageTitle'  => 'Documents',

            // A queue is a table read on a monitor, not a column of prose:
            // see the layout for what `wide` does to the content column.
            'wide'       => true,
            'documents'  => Document::paginate($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'filters'    => $filters,
            'filtered'   => array_filter($filters, static fn (string $v): bool => trim($v) !== '') !== [],
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'counts'     => Document::countsByStatus(),
            'queue'      => PipelineJob::countsByStatus(),

            // The supplier names actually on file, so the commonest filter is a
            // list to pick from rather than a name to spell correctly.
            'supplierNames' => Document::supplierNames(),
            'docTypes'       => DocumentType::all(),
        ]);
    }

    /**
     * The one-page summary, for paper.
     *
     * Everything the pipeline concluded plus what Clear Books did with it, on
     * its own layout rather than the ordinary one with the chrome hidden —
     * hiding the navigation still ships it, and a printed record that quietly
     * breaks when the menu changes is one nobody notices until a supplier
     * queries an invoice.
     *
     * `documents.view`, the same as the screen it summarises: it contains
     * nothing the document page does not.
     */
    public function printable(string $id): void
    {
        $document = Document::find((int) $id);

        if ($document === null) {
            $this->notFound('No such document.');
        }

        $documentId = (int) $document['id'];
        $extraction = Extraction::latest($documentId);

        if ($extraction === null) {
            Flash::info('There is nothing to print yet — this document has not been read.');
            Response::redirect('/documents/' . $documentId);
        }

        $this->view('documents/print', [
            'pageTitle'  => 'Summary — document #' . (int) $document['id'],
            'backHref'   => '/documents/' . $documentId,
            'document'   => $document,
            'extraction' => $extraction,
            'lines'        => array_values(Extraction::decode($extraction, 'line_items')),
            'customValues' => Extraction::decode($extraction, 'custom_field_values'),
            'customFields' => CustomField::all(),
            'matches'      => EntityMatch::forExtraction((int) $extraction['id']),
            'submitted'    => Submission::successful($documentId),
            'reviewNotes'  => Extraction::reviewNotes($extraction),
        ], 'layouts/print');
    }

    public function show(string $id): void
    {
        $document = Document::find((int) $id);

        if ($document === null) {
            $this->notFound('No such document.');
        }

        $extraction = Extraction::latest((int) $document['id']);

        $pdf = $document['pdf_path'] === null
            ? null
            : IngestStage::absolutePath((string) $document['pdf_path']);

        $matches = $extraction === null
            ? []
            : EntityMatch::forExtraction((int) $extraction['id']);

        $this->view('documents/show', [
            'pageTitle' => 'Document #' . (int) $document['id'],
            'wide'      => true,
            'document'  => $document,
            'events'    => DocumentEvent::forDocument((int) $document['id']),
            'jobs'      => PipelineJob::forDocument((int) $document['id']),
            'audit'     => AuditLog::forDocument((int) $document['id']),
            'pages'     => DocumentPage::forDocument((int) $document['id']),
            'ocr'        => OcrResult::latest((int) $document['id']),
            'ocrRuns'    => OcrResult::forDocument((int) $document['id']),
            'extraction' => $extraction,

            // The matching stage's conclusions, which supersede the extraction's
            // own guess about the supplier: the deterministic name pass resolves
            // plenty of the ones the model could not place.
            'matches'    => $matches,

            // The same per-field attribution the review screen uses. This page
            // cannot be edited, so the marks are all it offers — but "which of
            // these values was the doubtful one" is the same question on both
            // screens and must not have two answers.
            'issues'     => $extraction === null
                ? null
                : FieldIssues::build($extraction, $matches, CustomField::extracted()),

            // What went to Clear Books, if anything did. The "Open in Clear
            // Books" action is the only way to set a project code, so it has
            // to be reachable from the record rather than only from the queue.
            'submissions' => Submission::forDocument((int) $document['id']),
            'submitted'   => Submission::successful((int) $document['id']),

            // Whether the PDF is actually on disk, as distinct from whether the
            // database thinks it is. A restore without the storage directory
            // leaves rows pointing at files that are not there, and saying so
            // is more use than a broken download link.
            'pdfBytes'  => $pdf === null ? null : filesize($pdf),
        ]);
    }

    /**
     * Serve the stored source PDF.
     *
     * Streamed from outside the document root — nothing under storage/ is
     * web-reachable — and inline rather than as a download, so the review screen
     * can put it in an <object> beside the extracted data.
     */
    public function pdf(string $id): void
    {
        $document = Document::find((int) $id);

        if ($document === null || $document['pdf_path'] === null) {
            $this->notFound('That document has no stored PDF.');
        }

        $path = IngestStage::absolutePath((string) $document['pdf_path']);

        if ($path === null) {
            $this->notFound('The stored PDF is missing from disk. Retry the document to fetch it again.');
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($path));
        // The document's own number, and the name it arrived under when there
        // is one — that is the half somebody recognises in a downloads folder.
        $original = trim((string) ($document['original_filename'] ?? ''));
        $stem     = $original === ''
            ? ''
            : '-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME));

        $filename = 'invogrid-' . (int) $document['id'] . mb_substr($stem, 0, 60) . '.pdf';

        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');

        if (Request::method() !== 'HEAD') {
            readfile($path);
        }

        exit;
    }

    /**
     * Serve one rendered page image.
     *
     * Same reasoning as the PDF: the file lives outside the document root, so
     * it is read and sent rather than linked. Cached hard because a page image
     * only changes when the document is re-rendered, and a re-render replaces
     * the row this route is reached through.
     */
    public function page(string $id, string $page): void
    {
        $row = DocumentPage::find((int) $id, (int) $page);

        if ($row === null) {
            $this->notFound('No such page.');
        }

        $path = IngestStage::absolutePath((string) $row['image_path']);

        if ($path === null) {
            $this->notFound('That page image is missing from disk. Re-run the document from Reading pages.');
        }

        $size = @getimagesize($path);
        $mime = $size === false ? 'application/octet-stream' : (string) $size['mime'];

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');

        if (Request::method() !== 'HEAD') {
            readfile($path);
        }

        exit;
    }

    /**
     * Put a document back to the head of the stage that failed and queue it.
     *
     * Deliberately not "start again from the beginning": re-downloading a PDF
     * because a later stage failed wastes time and bandwidth, and the retry
     * status comes from whichever stage actually broke.
     */
    public function retry(string $id): void
    {
        $document = Document::find((int) $id);

        if ($document === null) {
            $this->notFound('No such document.');
        }

        $documentId = (int) $document['id'];
        $from       = (string) $document['status'];

        // An explicit target, from the "reset to" control, or the natural one.
        $requested = (string) Request::post('to', '');
        $target    = in_array($requested, Document::STATUSES, true)
            ? $requested
            : Document::retryStatusFor($document['failed_stage'] === null ? null : (string) $document['failed_stage']);

        try {
            Document::transitionTo($documentId, $target);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/documents/' . $documentId);
        }

        /*
         * Sending a document to `existing_invoice` is a person saying "this is
         * a scan of something already in Clear Books", so the route has to
         * follow — it is what the linking stage reads, and what the matching
         * stage will read if this document is ever re-matched. That is the
         * whole reason `needs_review → existing_invoice` and
         * `ready_to_submit → existing_invoice` are legal: the Clearbooks Number
         * was read off handwriting on a scan, and somebody holding the page is
         * better placed to judge it than the model was.
         *
         * The reverse gesture is not here. Moving a document *off* the Existing
         * Invoice flow is the queue's "treat it as a new invoice", which flips
         * the route and re-runs the matching stage so the document lands where
         * that stage decides rather than wherever a dropdown was set to.
         *
         * Every other target leaves the route alone. A retry from `extracting`
         * is not a statement about which flow this is on.
         */
        if ($target === Document::EXISTING_INVOICE) {
            Document::setRoute($documentId, Document::ROUTE_EXISTING);
        }

        // Old jobs are cleared first, or enqueue() would sit beside a failed
        // row and the history would read as two attempts at once.
        PipelineJob::clearFinished($documentId);
        $queued = Pipeline::advance($documentId, $target);

        DocumentEvent::record($documentId, 'retry', DocumentEvent::SUCCEEDED, $from . ' → ' . $target);
        AuditLog::record('document.retry', $documentId, 'Reset from ' . Document::label($from) . ' to ' . Document::label($target));

        Flash::success(
            $queued === null
                ? 'Moved to ' . Document::label($target) . '. Nothing runs that stage yet, so it will wait there.'
                : 'Queued. It will be picked up within a minute.'
        );

        Response::redirect('/documents/' . $documentId);
    }

    /**
     * Send an already-submitted document to Clear Books again.
     *
     * The escape hatch, and it is deliberately awkward to reach: admin only, on
     * no ordinary path, and behind a confirmation that says what it does. It
     * exists because a submission can genuinely go wrong in a way only a second
     * one fixes — Clear Books accepted a record and somebody deleted it there,
     * most often.
     *
     * What it does **not** do is withdraw the first record. InvoGrid has no
     * business deleting from somebody's ledger, so this leaves two and expects
     * a person to tidy up the one they no longer want. That is stated on the
     * button, because an escape hatch whose consequences are a surprise is a
     * trap.
     */
    public function resubmit(string $id): void
    {
        $document = Document::find((int) $id);

        if ($document === null) {
            $this->notFound('No such document.');
        }

        $documentId = (int) $document['id'];
        $previous   = Submission::successful($documentId);

        if ($previous === null) {
            Flash::error('That document has never been submitted, so there is nothing to resubmit.');
            Response::redirect('/documents/' . $documentId);
        }

        AuditLog::record('document.resubmit_started', $documentId, sprintf(
            '%s is resubmitting a document already in Clear Books as %s %s. Reason given: %s',
            Auth::displayName(),
            $previous['clearbooks_type'],
            $previous['clearbooks_id'],
            trim((string) Request::post('reason', '')) === ''
                ? 'none'
                : mb_substr(trim((string) Request::post('reason', '')), 0, 500)
        ));

        try {
            $result = SubmitStage::submit($documentId, true);
        } catch (Throwable $e) {
            Flash::error('Clear Books did not accept it. ' . $e->getMessage());
            Response::redirect('/documents/' . $documentId);
        }

        Flash::warning(
            $result['message'] . ' The earlier record ' . $previous['clearbooks_id']
            . ' is still in Clear Books and has to be removed there if it is not wanted.'
        );

        Response::redirect('/documents/' . $documentId);
    }

    /**
     * Take a document out of the pipeline.
     *
     * For the ones that are not ours to process — a delivery note that came in
     * on the same scan run, a duplicate. Reversible: `ignored` goes back to
     * `received`.
     */
    public function ignore(string $id): void
    {
        $document = Document::find((int) $id);

        if ($document === null) {
            $this->notFound('No such document.');
        }

        $documentId = (int) $document['id'];

        try {
            Document::transitionTo($documentId, Document::IGNORED);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/documents/' . $documentId);
        }

        AuditLog::record('document.ignore', $documentId, 'Marked as not for processing');
        DocumentEvent::record($documentId, 'ignore', DocumentEvent::SKIPPED, 'Ignored by ' . Auth::displayName());

        Flash::success('Document ignored. Nothing further will happen to it.');
        Response::redirect('/documents/' . $documentId);
    }
}
