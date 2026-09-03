<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\ClearbooksCache;
use App\Models\ClearbooksInvoice;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentType;
use App\Models\Extraction;
use App\Models\OcrResult;
use App\Models\Setting;
use App\Models\Submission;
use RuntimeException;

/**
 * The Existing Invoice route's last step: match a scan to the Clear Books
 * record it belongs to, instead of creating one.
 *
 * A document reaches here having run the whole pipeline — ingest, ocr, extract,
 * match — exactly as a new invoice does. That is the point: a scan of an
 * existing invoice is still a document somebody will search for and report on,
 * and it has the same supplier, dates, line items and custom fields as any
 * other. What differs is only what happens now.
 *
 * `MatchStage` sent it here because `documents.route` says the page carries a
 * handwritten Clearbooks Number, which is a reference to an invoice somebody
 * already entered in Clear Books. So:
 *
 *  1. **Look the number up** against the synced copy of Clear Books' purchase
 *     documents. Exactly one record, or the document waits for a person.
 *  2. **Check the extraction confirms it** — the invoice date and the gross
 *     total, both exactly. See `InvoiceMatcher::check()`.
 *  3. **Link it**: attach the PDF, record the Clear Books id against the
 *     document, and fill in the fields that only exist once a document has
 *     reached Clear Books.
 *
 * Anything short of all three sends the document to `needs_link` with the
 * reason attached, and **nothing auto-resolves from there**. The three things a
 * person may do about it are on the Existing Invoice queue.
 *
 * ### Nothing in Clear Books is changed
 *
 * The record was entered by a person and is not InvoGrid's to edit. The **only**
 * call this makes is the attachment, which adds a file and alters no field. The
 * extracted data goes into InvoGrid's own columns, where it would have gone
 * anyway; a difference between what the page says and what the ledger says is
 * something for a person to settle in Clear Books, not for this to overwrite.
 *
 * ### Where the ordering differs from SubmitStage, deliberately
 *
 * `SubmitStage` records the submission *before* attaching the PDF, because the
 * irreversible act there is creating a record in somebody's accounts and a
 * crash between the two would leave a bill InvoGrid thinks it never sent. Here
 * the attachment **is** the act. So the order is inverted: attach first, and
 * only claim the link once the file is actually on the record. A crash between
 * the two leaves an attachment and no local record, and the retry attaches
 * again under the same name — untidy, and far better than a document marked
 * linked whose evidence never arrived anywhere.
 */
final class LinkStage
{
    /**
     * The pipeline entry point.
     *
     * @param array<string,mixed> $document
     * @return string The status the document moves to
     */
    public function run(array $document): string
    {
        $id         = (int) $document['id'];
        $ocr        = OcrResult::latest($id);
        $extraction = Extraction::latest($id);

        if ($extraction === null) {
            // Only reachable by a person moving a document here by hand from
            // before it was extracted. Recoverable: the queue's "treat it as a
            // new invoice" puts it back through the pipeline.
            return self::wait($id, 'This document has nothing extracted, so there is no invoice date or '
                . 'total to check a Clear Books record against.');
        }

        $number = $ocr === null ? null : OcrResult::clearbooksNumber($ocr);

        /*
         * A document with no usable number can still be here: the document page
         * lets somebody move one onto this flow by hand, which is the right
         * answer when the model missed a number a person can see. It belongs in
         * the queue, where they can type it.
         */
        if (!OcrResult::isUsableNumber($number)) {
            return self::wait($id, $number === null
                ? 'No Clearbooks Number was read off this page, so there is nothing to look up. '
                  . 'Enter one on the Existing Invoice queue.'
                : 'The Clearbooks Number came back as "' . $number . '", which is not digits only and so '
                  . 'cannot be a Clear Books reference.');
        }

        return self::attempt($id, (string) $number, $extraction, 'automatically');
    }

    /**
     * Look a number up, check it, and link if the checksum holds.
     *
     * Shared by the stage and by the queue's manual action, so a number typed
     * by a person goes through exactly the same lookup and the same checksum as
     * one read off the page. The only difference is what happens to a checksum
     * that does not hold — see `$overridable`.
     *
     * @param array<string,mixed> $extraction
     * @return string The status the document moves to
     */
    public static function attempt(
        int $documentId,
        string $number,
        array $extraction,
        string $how,
        bool $overridable = false,
    ): string {
        $lookup = InvoiceMatcher::lookup($number);

        if ($lookup['outcome'] === InvoiceMatcher::NONE) {
            return self::wait($documentId, sprintf(
                'Clearbooks Number %s does not match any purchase document in Clear Books. %s',
                $number,
                self::syncHint()
            ));
        }

        if ($lookup['outcome'] === InvoiceMatcher::AMBIGUOUS) {
            return self::wait($documentId, sprintf(
                'Clearbooks Number %s matches %s%d Clear Books records (%s%s), so InvoGrid will not guess '
                . 'between them.',
                $number,
                $lookup['truncated'] ? 'more than ' : '',
                count($lookup['candidates']),
                implode(', ', array_map(
                    static fn (array $row): string => (string) ($row['document_number'] ?? $row['clearbooks_id']),
                    $lookup['candidates']
                )),
                $lookup['truncated'] ? ' and others' : ''
            ));
        }

        /** @var array<string,mixed> $invoice */
        $invoice = $lookup['invoice'];
        $check   = InvoiceMatcher::check($invoice, $extraction);

        if (!$check['ok'] && !$overridable) {
            return self::wait($documentId, sprintf(
                'Clearbooks Number %s found %s %s, but the checksum does not hold. %s',
                $number,
                ClearbooksInvoice::label((string) $invoice['purchase_type']),
                (string) ($invoice['document_number'] ?? $invoice['clearbooks_id']),
                $check['summary']
            ));
        }

        self::link($documentId, $invoice, $number, $how, $check, $extraction);

        return Document::SUBMITTED;
    }

    /**
     * Attach the PDF to the matched record and record the link.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $check
     * @param array<string,mixed> $extraction
     */
    public static function link(
        int $documentId,
        array $invoice,
        string $number,
        string $how,
        array $check,
        array $extraction,
    ): void {
        $document = Document::find($documentId);

        if ($document === null) {
            throw new RuntimeException('No document ' . $documentId . '.');
        }

        $clearbooksId = (string) $invoice['clearbooks_id'];
        $resource     = ClearbooksInvoice::RESOURCES[(string) $invoice['purchase_type']] ?? 'bills';
        $reference    = (string) ($invoice['document_number'] ?? $clearbooksId);

        DocumentEvent::record($documentId, 'link', DocumentEvent::STARTED, sprintf(
            'Clearbooks Number %s is %s %s in Clear Books. %s',
            $number,
            ClearbooksInvoice::label((string) $invoice['purchase_type']),
            $reference,
            $check['summary'] ?? ''
        ));

        // Attached before anything is claimed. The docblock on this class says
        // why the order is the opposite of SubmitStage's. It is also the *only*
        // call made against Clear Books here — nothing on the record is edited.
        $attached = self::attach($document, $resource, $clearbooksId);

        $url = ClearBooksClient::documentUrl($resource, (int) $clearbooksId);

        Submission::record($documentId, $resource, $clearbooksId, $url, Submission::SUCCESS, [
            /*
             * What makes this row a link rather than a submission.
             *
             * The two share a table because everything downstream — the "Open
             * in Clear Books" action, the document list's join, the idempotency
             * check that stops a document reaching Clear Books twice — wants
             * exactly the same three facts about both. What differs is that
             * nothing was created here, and a row that could not say so would
             * eventually be read as a bill this application posted.
             */
            'linked'           => true,
            'clearbooksNumber' => $number,
            'matched'          => $invoice['document_number'] ?? null,
            'purchaseType'     => $invoice['purchase_type'],
            'documentDate'     => $invoice['document_date'] ?? null,
            'reference'        => $invoice['reference'] ?? null,
            'grossAmount'      => $invoice['gross_amount'] ?? null,
            'attachment'       => $attached,
            'check'            => $check,
            'how'              => $how,
        ]);

        self::recordAgainstRecord($documentId, $invoice, $extraction, $clearbooksId);

        Document::transitionTo($documentId, Document::SUBMITTED);

        /*
         * "by Nick" only when there is a Nick.
         *
         * The queue's action runs in a request and has a signed-in user; the
         * stage runs from cron and has none, and `Auth::displayName()` answers
         * "Guest" for that. A log line reading "automatically by Guest" invents
         * a person who was not there, in the one table whose whole job is
         * saying who did what.
         */
        $by = Auth::id() === null ? $how : $how . ' by ' . Auth::displayName();

        AuditLog::record('document.linked', $documentId, sprintf(
            'Linked to the Clear Books %s %s (Clearbooks Number %s, %s), %s.%s',
            ClearbooksInvoice::label((string) $invoice['purchase_type']),
            $reference,
            $number,
            $invoice['gross_amount'] === null ? 'no total' : format_money($invoice['gross_amount']),
            $by,
            $attached['ok'] ? ' The PDF is attached.' : ' ' . $attached['message']
        ));

        DocumentEvent::record($documentId, 'link', DocumentEvent::SUCCEEDED, sprintf(
            'Linked to %s %s. %s',
            ClearbooksInvoice::label((string) $invoice['purchase_type']),
            $reference,
            $attached['ok'] ? 'The PDF is attached to it.' : $attached['message']
        ));
    }

    /**
     * Put the document into the queue, with the reason on the record.
     *
     * The reason is the whole point. A document that lands in a queue saying
     * only "could not be linked" makes the person working it repeat the lookup
     * by hand to find out what happened.
     */
    private static function wait(int $documentId, string $why): string
    {
        DocumentEvent::record($documentId, 'link', DocumentEvent::SKIPPED, $why);

        return Document::NEEDS_LINK;
    }

    /**
     * Attach the source PDF to the matched record.
     *
     * Throws rather than warning, unlike `SubmitStage::attach()`. There the
     * record had just been created and a missing attachment was a blemish on
     * something that had otherwise worked; here the attachment is the entire
     * outcome, and a "linked" document with nothing on the Clear Books record
     * would be a lie the queue has no way of noticing.
     *
     * The one exception is `clearbooks_attach_pdf` being switched off, which is
     * a deliberate configuration rather than a failure. The link is still worth
     * recording — it is what puts the Clear Books reference on the document and
     * takes it out of the queue.
     *
     * @param array<string,mixed> $document
     * @return array{ok:bool,message:string,name:?string}
     */
    private static function attach(array $document, string $resource, string $clearbooksId): array
    {
        if (!Setting::bool('clearbooks_attach_pdf', true)) {
            return [
                'ok'      => false,
                'message' => 'Attaching PDFs is switched off, so only the reference was recorded.',
                'name'    => null,
            ];
        }

        if ($document['pdf_path'] === null) {
            throw new RuntimeException('This document has no stored PDF, so there is nothing to attach.');
        }

        $path = IngestStage::absolutePath((string) $document['pdf_path']);

        if ($path === null) {
            throw new RuntimeException(
                'The stored PDF is missing from disk. Retry this document from Received to fetch it again.'
            );
        }

        // The same naming as a submitted document's attachment, so the two look
        // alike in Clear Books: the InvoGrid number first, because that is what
        // leads back here, and the original filename after it.
        $original = trim((string) ($document['original_filename'] ?? ''));
        $stem     = $original === ''
            ? ''
            : '-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME));

        $name = 'invogrid-' . (int) $document['id'] . mb_substr($stem, 0, 60) . '.pdf';

        (new ClearBooksClient())->attachToPurchase($resource, (int) $clearbooksId, $name, $path);

        return ['ok' => true, 'message' => 'Attached as ' . $name . '.', 'name' => $name];
    }

    /**
     * Write the Clear Books record's identity into InvoGrid's own columns.
     *
     * The prompt's phrase for this is "all extracted data is saved in the
     * correct places on the local database", and most of that has already
     * happened: the extraction wrote the header, the lines and the custom
     * fields, and the matching stage wrote `entity_matches`. What is left is
     * the part only this stage knows — which Clear Books record this is.
     *
     * Three things, and each goes where the same fact goes for a submitted
     * document, so nothing downstream needs a special case for a linked one:
     *
     *  - the **Clear Books id and document number**, into the two
     *    `submission`-source custom fields, through the same method
     *    `SubmitStage` uses. Those fields exist precisely for a value that is
     *    produced by reaching Clear Books rather than read off a page;
     *  - the **supplier**, from the record's `supplierId`. This supersedes what
     *    the matching stage concluded, deliberately: a guess from a letterhead
     *    loses to the ledger's own answer, and it resolves a document whose
     *    supplier the fallback could not place;
     *  - the **document type**, from the endpoint the record came back on.
     *    Clear Books put it in `purchases/creditNotes` or it did not, which is
     *    a fact rather than a classification — and it is why this flow never
     *    asks the credit-note/refund question §24 exists for. Nothing is being
     *    posted, so the sign that distinction protects is never used.
     *
     * **The project code is not pushed anywhere**, because there is nowhere to
     * push it: Clear Books has no projects endpoint and no field for one on a
     * purchase document (§19). It stays on the OCR result and in the custom
     * field values, and "Open in Clear Books" is how a person sets it — exactly
     * as it is for a submitted document.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $extraction
     */
    private static function recordAgainstRecord(
        int $documentId,
        array $invoice,
        array $extraction,
        string $clearbooksId,
    ): void {
        SubmitStage::recordProducedFields($extraction, $clearbooksId, [
            'formattedDocumentNumber' => $invoice['document_number'] ?? null,
        ]);

        $supplierId = $invoice['supplier_id'] === null ? null : (string) $invoice['supplier_id'];

        if ($supplierId !== null) {
            $cached = ClearbooksCache::find(ClearbooksCache::SUPPLIER, $supplierId);

            Document::setMatchedSupplier(
                $documentId,
                $supplierId,
                $cached === null ? null : (string) $cached['name']
            );
        }

        $type = DocumentType::forResource(
            ClearbooksInvoice::RESOURCES[(string) $invoice['purchase_type']] ?? 'bills'
        );

        if ($type !== null) {
            Document::setExtractionSummary($documentId, (string) $type['type_key'], null);
        }
    }

    /** Whether the sync is likely to be the reason a number found nothing. */
    private static function syncHint(): string
    {
        $summary = ClearbooksInvoice::summary();

        if ($summary['total'] === 0) {
            return 'Nothing has been synced from Clear Books yet, which is very likely the reason — '
                . 'run the invoice sync on the Clear Books screen.';
        }

        return sprintf(
            'The local copy holds %d record(s), last confirmed %s. A record entered in Clear Books since '
            . 'then will not be there yet.',
            $summary['total'],
            $summary['syncedAt'] === null ? 'never' : format_datetime($summary['syncedAt'])
        );
    }
}
