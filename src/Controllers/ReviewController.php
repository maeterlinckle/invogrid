<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\ClearbooksCache;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentType;
use App\Models\EntityMatch;
use App\Models\Extraction;
use App\Models\OcrResult;
use App\Models\DocumentPage;
use App\Models\Submission;
use App\Services\EntityCreator;
use App\Services\FieldIssues;
use App\Services\IngestStage;
use App\Services\SubmitStage;
use Throwable;

/**
 * The review queue: what a person actually uses this application for.
 *
 * Everything before this is machinery. A reviewer opens a document, reads the
 * scan beside what was extracted from it, corrects what is wrong, resolves what
 * could not be matched, and submits it — or says it is not a purchase document
 * at all.
 *
 * Two rules shape the whole screen:
 *
 *  - **Every extracted value is editable.** A reviewer who can see that the due
 *    date is wrong but can only accept or reject the document is worse off than
 *    one with no machine at all, because now they have to go somewhere else to
 *    fix it. The model's reading is a first draft.
 *  - **Nothing is created in Clear Books without a person pressing a button on
 *    a form they have looked at.** The form is pre-filled from the extraction;
 *    what gets created is what was confirmed.
 */
final class ReviewController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * The queue.
     *
     * Documents needing a person, and — deliberately in the same list —
     * documents that are ready to submit. Splitting them across two screens
     * would mean a reviewer who has just resolved something has to go and find
     * it again somewhere else to finish the job.
     */
    public function index(): void
    {
        $filter = (string) Request::query('show', 'open');
        $status = match ($filter) {
            'ready'  => [Document::READY_TO_SUBMIT],
            'review' => [Document::NEEDS_REVIEW],
            default  => [Document::NEEDS_REVIEW, Document::READY_TO_SUBMIT],
        };

        $page  = max(1, (int) Request::query('page', 1));
        $total = Document::countWithStatuses($status);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);

        $this->view('review/index', [
            'pageTitle' => 'Review queue',

            // A queue is a table read on a monitor, not a column of prose:
            // see the layout for what `wide` does to the content column.
            'wide'      => true,
            'rows'      => Document::queue($status, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'filter'    => $filter,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'counts'    => [
                Document::NEEDS_REVIEW    => Document::countWithStatuses([Document::NEEDS_REVIEW]),
                Document::READY_TO_SUBMIT => Document::countWithStatuses([Document::READY_TO_SUBMIT]),
            ],
        ]);
    }

    /** The scan on one side, the record on the other. */
    public function show(string $id): void
    {
        [$document, $extraction] = $this->load($id);

        $pdf = $document['pdf_path'] === null
            ? null
            : IngestStage::absolutePath((string) $document['pdf_path']);

        $matches      = EntityMatch::forExtraction((int) $extraction['id']);
        $customFields = CustomField::extracted();

        $this->view('review/show', [
            'pageTitle'     => 'Review #' . (int) $document['id'],
            'wide'          => true,
            'document'      => $document,
            'extraction'    => $extraction,
            'matches'       => $matches,
            'unresolved'    => EntityMatch::unresolved((int) $extraction['id']),
            'notes'         => Extraction::reviewNotes($extraction),

            /*
             * Which field is each of those notes actually about?
             *
             * The screen used to answer that with one card at the top saying
             * "4 things to check" above forty inputs, leaving the reviewer to
             * work out which four boxes were meant — the part of the job the
             * machine can do. `FieldIssues` pins each note, each unresolved
             * match and each uncertain reading to the input it belongs to, and
             * hands back the handful that name no field rather than guessing.
             */
            'issues'        => FieldIssues::build($extraction, $matches, $customFields),

            /*
             * The rendered pages: what the scan pane shows by default.
             *
             * They are already on disk — every document is rendered to one
             * image per page before a model is shown it — and they are the very
             * images the extraction was worked out from. The PDF is a button
             * underneath them rather than the thing embedded.
             */
            'pages'         => DocumentPage::forDocument((int) $document['id']),
            'lines'         => array_values(Extraction::decode($extraction, 'line_items')),
            'supplierMatch' => Extraction::decode($extraction, 'supplier_match'),
            'treatment'     => Extraction::decode($extraction, 'vat_treatment'),
            'customValues'  => Extraction::decode($extraction, 'custom_field_values'),
            'customFields'  => $customFields,
            'docTypes'      => DocumentType::all(),

            // The credit-note / refund decision: which types are on offer,
            // whether anybody has agreed yet, and what this supplier usually
            // does — so the likeliest answer arrives already selected.
            'needsAgreement' => DocumentType::requiresConfirmation($extraction['doc_type'] ?? null)
                && !Extraction::typeConfirmed($extraction),
            'confirmable'    => DocumentType::all(),
            'supplierRoute'  => ClearbooksCache::defaultCreditRoute(
                $document['matched_supplier_id'] === null
                    ? null
                    : (string) $document['matched_supplier_id']
            ),
            'suppliers'     => ClearbooksCache::all(ClearbooksCache::SUPPLIER),
            'accountCodes'  => ClearbooksCache::all(ClearbooksCache::ACCOUNT_CODE),
            'vatRates'      => ClearbooksCache::all(ClearbooksCache::VAT_RATE),
            'vatTreatments' => ClearbooksCache::all(ClearbooksCache::VAT_TREATMENT),
            'ocr'           => OcrResult::latest((int) $document['id']),
            'submission'    => Submission::latest((int) $document['id']),
            'hasPdf'        => $pdf !== null,
        ]);
    }

    /**
     * Save a reviewer's corrections.
     *
     * Followed immediately by a re-match, because an edit is very often *the*
     * resolution — a reviewer who fixes a mistyped account code should see it
     * turn green now, not after the next cron tick.
     */
    public function save(string $id): void
    {
        [$document, $extraction] = $this->load($id);
        $documentId = (int) $document['id'];

        try {
            $changed = Extraction::updateFields((int) $extraction['id'], $this->submitted($extraction), Auth::id());
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/review/' . $documentId);
        }

        if ($changed === []) {
            Flash::info('Nothing was changed.');
            Response::redirect('/review/' . $documentId);
        }

        // What changed, not just that something did: "edited" in an audit trail
        // answers no question anybody actually asks.
        AuditLog::record(
            'review.edited',
            $documentId,
            Auth::displayName() . ' edited ' . implode(', ', array_map(
                static fn (string $column): string => str_replace('_', ' ', $column),
                $changed
            )) . '.'
        );

        DocumentEvent::record($documentId, 'review', DocumentEvent::SUCCEEDED, 'Edited by ' . Auth::displayName() . '.');

        $status = $this->recheck($documentId);

        Flash::success(
            'Saved. ' . ($status === Document::READY_TO_SUBMIT
                ? 'Everything now resolves — it is ready to submit.'
                : 'Still waiting on something; see below.')
        );

        Response::redirect('/review/' . $documentId);
    }

    /**
     * Create the missing entity in Clear Books, from the confirmed form.
     *
     * The only route in this application that writes a new record into somebody
     * else's accounts, and it is a POST from a form a person filled in.
     */
    public function createEntity(string $id, string $matchId): void
    {
        [$document] = $this->load($id);
        $documentId = (int) $document['id'];

        try {
            $result = EntityCreator::supplier($documentId, (int) $matchId, Request::all());
        } catch (Throwable $e) {
            Flash::error('Nothing was created. ' . $e->getMessage());
            Response::redirect('/review/' . $documentId);
        }

        Flash::success(sprintf(
            'Created "%s" in Clear Books (id %s). %s',
            $result['name'],
            $result['cbId'],
            $result['status'] === Document::READY_TO_SUBMIT
                ? 'This document is now ready to submit.'
                : 'Something else on this document still needs resolving.'
        ));

        Response::redirect('/review/' . $documentId);
    }

    /** Resolve an entity to something already on file. */
    public function pickEntity(string $id, string $matchId): void
    {
        [$document] = $this->load($id);
        $documentId = (int) $document['id'];

        try {
            $result = EntityCreator::pick($documentId, (int) $matchId, (string) Request::post('remote_id', ''));
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/review/' . $documentId);
        }

        Flash::success('Set to "' . $result['name'] . '". ' . ($result['status'] === Document::READY_TO_SUBMIT
            ? 'This document is now ready to submit.'
            : 'Something else on this document still needs resolving.'));

        Response::redirect('/review/' . $documentId);
    }

    /**
     * Agree what kind of document this is.
     *
     * The one decision on this screen that is not about matching. A credit note
     * gives Junction an amount to set against an invoice and no money moves; a
     * purchase refund is money that has actually come back. Clear Books records
     * them completely differently — opposite signs, different endpoints — and
     * the page very often does not settle which it is, because the arrangement
     * was made on the telephone.
     *
     * Optionally records the answer as this supplier's usual route, so the next
     * one arrives with the right box already selected. That is a convenience,
     * not an assumption: the choice is still made by a person every time.
     */
    public function confirmType(string $id): void
    {
        [$document, $extraction] = $this->load($id);
        $documentId = (int) $document['id'];

        $chosen = (string) Request::post('doc_type', '');

        if (!in_array($chosen, DocumentType::keys(false), true)) {
            Flash::error('That is not a document type InvoGrid knows about.');
            Response::redirect('/review/' . $documentId);
        }

        $was = (string) ($extraction['doc_type'] ?? '');

        Extraction::confirmType((int) $extraction['id'], $chosen, Auth::id());

        AuditLog::record('review.type_confirmed', $documentId, sprintf(
            '%s confirmed this is %s%s.',
            Auth::displayName(),
            DocumentType::label($chosen),
            $was === '' || $was === $chosen ? '' : ', changed from ' . DocumentType::label($was)
        ));

        // "Always do this for this supplier" — recorded against the cached
        // supplier, and only when one is actually resolved.
        if (Request::boolean('remember_for_supplier')) {
            $supplier = EntityMatch::supplier((int) $extraction['id']);
            $cached   = $supplier === null || $supplier['matched_id'] === null
                ? null
                : ClearbooksCache::find(ClearbooksCache::SUPPLIER, (string) $supplier['matched_id']);

            if ($cached === null) {
                Flash::warning('The supplier is not resolved yet, so this could not be remembered against them.');
            } else {
                ClearbooksCache::setDefaultCreditRoute((int) $cached['id'], $chosen);

                AuditLog::record('review.supplier_route_set', $documentId, sprintf(
                    '%s set "%s" to default to %s for credit documents.',
                    Auth::displayName(),
                    $cached['name'],
                    DocumentType::label($chosen)
                ));
            }
        }

        $status = $this->recheck($documentId);

        Flash::success('Recorded as ' . DocumentType::label($chosen) . '. ' . ($status === Document::READY_TO_SUBMIT
            ? 'This document is now ready to submit.'
            : 'Something else on this document still needs resolving.'));

        Response::redirect('/review/' . $documentId);
    }

    /**
     * Take a document out of the queue, with a reason.
     *
     * The reason is required, and that is the point of this action existing
     * separately from the one on the document page. A duplicate, a delivery
     * note that came in on the same scan run, a statement rather than an
     * invoice — six months later somebody will ask why this one was skipped,
     * and "ignored by nick" is not an answer.
     */
    public function ignore(string $id): void
    {
        [$document] = $this->load($id);
        $documentId = (int) $document['id'];
        $reason     = trim((string) Request::post('reason', ''));

        if (mb_strlen($reason) < 3) {
            Flash::error('Say why it is being skipped — that is the whole value of the record.');
            Response::redirect('/review/' . $documentId);
        }

        try {
            Document::transitionTo($documentId, Document::IGNORED);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/review/' . $documentId);
        }

        AuditLog::record(
            'review.ignored',
            $documentId,
            Auth::displayName() . ' skipped this document: ' . mb_substr($reason, 0, 900)
        );

        DocumentEvent::record($documentId, 'review', DocumentEvent::SKIPPED, $reason);

        Flash::success('Skipped, and the reason is on the record. It can be put back from the document page.');
        Response::redirect('/review');
    }

    /**
     * Submit to Clear Books.
     *
     * Guarded three ways, because a bill submitted twice is a real problem in
     * somebody's accounts: the status must be `ready_to_submit`, every entity
     * must resolve, and `SubmitStage` itself refuses a document that already
     * has a successful submission.
     */
    public function submit(string $id): void
    {
        [$document, $extraction] = $this->load($id);
        $documentId = (int) $document['id'];

        if ((string) $document['status'] !== Document::READY_TO_SUBMIT) {
            Flash::error('That document is ' . Document::label((string) $document['status'])
                . ', not ready to submit. Nothing was sent.');
            Response::redirect('/review/' . $documentId);
        }

        if (EntityMatch::unresolved((int) $extraction['id']) !== []) {
            Flash::error('Something on this document still does not resolve. Nothing was sent.');
            Response::redirect('/review/' . $documentId);
        }

        try {
            $result = SubmitStage::submit($documentId);
        } catch (Throwable $e) {
            Flash::error('Clear Books did not accept it. ' . $e->getMessage());
            Response::redirect('/review/' . $documentId);
        }

        Flash::success($result['message']);
        Response::redirect('/documents/' . $documentId);
    }

    // --- Plumbing -----------------------------------------------------------

    /**
     * The document and its extraction, or a 404.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function load(string $id): array
    {
        $document = Document::find((int) $id);

        if ($document === null) {
            $this->notFound('No such document.');
        }

        $extraction = Extraction::latest((int) $document['id']);

        if ($extraction === null) {
            $this->notFound(
                'That document has not been through extraction yet, so there is nothing to review.'
            );
        }

        return [$document, $extraction];
    }

    /**
     * The posted form, shaped into the columns the extraction holds.
     *
     * Everything is read explicitly. `Request::all()` handed straight to the
     * model would let a form field named after any column rewrite it, and two
     * of those columns are the record of what a model said.
     *
     * @param array<string,mixed> $extraction
     * @return array<string,mixed>
     */
    private function submitted(array $extraction): array
    {
        $fields = [
            'doc_type'          => $this->docType(Request::post('doc_type', '')),
            'document_title'   => $this->text(Request::post('document_title'), 255),
            'cb_summary'        => $this->text(Request::post('cb_summary'), 255),
            'supplier_name_raw' => $this->text(Request::post('supplier_name_raw'), 255),
            'invoice_number'    => $this->text(Request::post('invoice_number'), 100),
            'invoice_date'      => $this->date(Request::post('invoice_date')),
            'due_date'          => $this->date(Request::post('due_date')),
            'paid_date'         => $this->date(Request::post('paid_date')),
            'currency'          => $this->currency(Request::post('currency')),
            'line_items'        => $this->lines(),
        ];

        // Totals are recomputed from the lines unless the reviewer overrode
        // them: a line edited without the total following is the single most
        // likely way for this screen to send a wrong number to Clear Books.
        $fields += $this->totals($fields['line_items']);

        $treatmentKey = $this->text(Request::post('vat_treatment'), 64);

        if ($treatmentKey !== null) {
            $cached = ClearbooksCache::find(ClearbooksCache::VAT_TREATMENT, $treatmentKey);

            $fields['vat_treatment'] = [
                'key'  => $treatmentKey,
                'name' => $cached === null ? $treatmentKey : (string) $cached['name'],
            ];
        }

        $custom = Extraction::decode($extraction, 'custom_field_values');

        foreach (CustomField::extracted() as $field) {
            $key = (string) $field['field_key'];

            if (Request::post('custom_' . $key) !== null) {
                $custom[$key] = CustomField::coerce((string) $field['data_type'], Request::post('custom_' . $key));
            }
        }

        $fields['custom_field_values'] = $custom;

        return $fields;
    }

    /**
     * The line items as posted.
     *
     * Parallel arrays rather than nested names, because a table of inputs is
     * what the form actually is and `description[]` survives a row being
     * removed in a way `lines[3][description]` does not.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lines(): array
    {
        $descriptions = (array) Request::post('line_description', []);
        $lines        = [];

        foreach (array_values($descriptions) as $index => $description) {
            $description = trim((string) $description);
            $accountCode = $this->text($this->at('line_account_code', $index), 32);
            $vatRateKey  = $this->text($this->at('line_vat_rate', $index), 32);
            $quantity    = $this->number($this->at('line_quantity', $index));
            $unitPrice   = $this->number($this->at('line_unit_price', $index));
            $lineTotal   = $this->number($this->at('line_total', $index));

            // A row emptied out is a row removed. Deleting a line is a thing a
            // reviewer needs to do — a scan that picked up the remittance slip
            // as a line item is common — and clearing it is the obvious gesture.
            if ($description === '' && $lineTotal === null && $unitPrice === null) {
                continue;
            }

            $lines[] = [
                'description' => $description,
                'quantity'    => $quantity,
                'unitPrice'   => $unitPrice,
                'lineTotal'   => $lineTotal ?? ($quantity !== null && $unitPrice !== null
                    ? round($quantity * $unitPrice, 2)
                    : null),
                'accountCode' => $accountCode === null ? null : (ctype_digit($accountCode) ? (int) $accountCode : $accountCode),
                'vatRateKey'  => $vatRateKey,
            ];
        }

        return $lines;
    }

    /**
     * Net, VAT and gross — the reviewer's if given, otherwise from the lines.
     *
     * A typed-in net wins outright. Somebody who has looked at the scan and
     * disagrees with the arithmetic is the better authority: a rounding
     * settlement, an early-payment discount applied to the total rather than to
     * a line, a line the scan lost altogether.
     *
     * The arithmetic itself is on the model, because the extraction stage and
     * the entity picker need exactly the same answer — three copies of it had
     * already drifted apart once.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,float|null>
     */
    private function totals(array $lines): array
    {
        $given = [
            'net_amount'   => $this->number(Request::post('net_amount')),
            'vat_amount'   => $this->number(Request::post('vat_amount')),
            'gross_amount' => $this->number(Request::post('gross_amount')),
        ];

        if ($given['net_amount'] !== null) {
            return $given;
        }

        $totals = Extraction::totalsFromLines($lines);

        return [
            'net_amount' => $totals['net'],

            // Where the lines cannot price the VAT — a rate not in the cache —
            // whatever the reviewer typed is kept rather than blanked out.
            'vat_amount'   => $totals['vat'] ?? $given['vat_amount'],
            'gross_amount' => $totals['gross'] ?? $given['gross_amount'],
        ];
    }

    private function at(string $field, int $index): mixed
    {
        $values = (array) Request::post($field, []);

        return array_values($values)[$index] ?? null;
    }

    private function docType(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, DocumentType::keys(false), true) ? $value : null;
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function number(mixed $value): ?float
    {
        if (!is_scalar($value)) {
            return null;
        }

        // Somebody will paste "1,234.56" out of a spreadsheet, and a bare cast
        // would silently make that 1.
        $value = str_replace([',', ' ', '£'], '', trim((string) $value));

        return $value === '' || !is_numeric($value) ? null : (float) $value;
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime(trim($value));

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function currency(mixed $value): ?string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';

        return $code === '' || $code === 'GBP' || preg_match('/^[A-Z]{3}$/', $code) !== 1 ? null : $code;
    }

    /** Re-match, without letting a failure there lose the edit that was saved. */
    private function recheck(int $documentId): string
    {
        try {
            return \App\Services\MatchStage::recheck($documentId);
        } catch (Throwable $e) {
            Flash::error('The edit was saved, but re-checking it failed: ' . $e->getMessage());

            $document = Document::find($documentId);

            return $document === null ? Document::NEEDS_REVIEW : (string) $document['status'];
        }
    }
}
