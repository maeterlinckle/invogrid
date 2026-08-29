<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\ClearbooksCache;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentType;
use App\Models\EntityMatch;
use App\Models\Extraction;
use App\Models\Setting;
use App\Models\Submission;
use RuntimeException;
use Throwable;

/**
 * Send a resolved document to Clear Books, then make Paperless agree with it.
 *
 * The only irreversible thing InvoGrid does. Everything before it can be
 * re-run; this creates a record in somebody's accounts that has to be deleted
 * by hand in Clear Books if it is wrong.
 *
 * **The order below is the important part**, and it is arranged around one
 * question: if the process dies here, what is true?
 *
 *  1. Refuse outright if this document already has a successful submission.
 *  2. Build the payload and check it is complete — *before* any call.
 *  3. Create the record in Clear Books.
 *  4. Write the `submissions` row and move the document to `submitted`.
 *  5. Attach the PDF.
 *  6. Write back to Paperless.
 *
 * Four comes before five and six deliberately. A crash between three and four
 * would leave a bill in the accounts that InvoGrid thinks it never sent — and
 * the next person to press submit would create a second one. A crash after four
 * leaves a document that is correctly marked submitted with an attachment or a
 * Paperless update missing, which is visible, harmless and fixable. Of the two
 * failure modes only one costs somebody a payment run.
 *
 * For the same reason five and six do not throw. The record exists; refusing to
 * record that because a tag could not be written would be choosing the worse
 * failure on purpose.
 */
final class SubmitStage
{
    /**
     * @return array{message:string,submissionId:int,clearbooksId:string,url:?string,warnings:array<int,string>}
     */
    public static function submit(int $documentId, bool $force = false): array
    {
        $document = Document::find($documentId);

        if ($document === null) {
            throw new RuntimeException('No such document.');
        }

        $existing = Submission::successful($documentId);

        if ($existing !== null && !$force) {
            // Not an error the caller should have to interpret: it names the
            // record that already exists, which is what somebody who pressed
            // submit twice needs to see.
            throw new RuntimeException(sprintf(
                'This document is already in Clear Books as %s %s, submitted %s. Nothing was sent.',
                $existing['clearbooks_type'],
                $existing['clearbooks_id'],
                $existing['submitted_at']
            ));
        }

        $extraction = Extraction::latest($documentId);

        if ($extraction === null) {
            throw new RuntimeException('This document has nothing extracted to submit.');
        }

        $unresolved = EntityMatch::unresolved((int) $extraction['id']);

        if ($unresolved !== []) {
            throw new RuntimeException(
                count($unresolved) . ' thing(s) on this document do not resolve against Clear Books.'
            );
        }

        $type = self::documentType($extraction);

        // Checked here as well as on the screen, because a hidden button is not
        // a guarantee. A credit note posted as a refund puts a movement of money
        // into the accounts that never happened, and the opposite leaves a real
        // refund unreconciled — neither is caught by anything downstream.
        if ((int) $type['requires_confirmation'] === 1 && !Extraction::typeConfirmed($extraction)) {
            throw new RuntimeException(sprintf(
                'Nobody has confirmed that this is %s rather than one of the alternatives. '
                . 'Clear Books records a credit note and a refund completely differently, so that '
                . 'has to be agreed before it is sent.',
                strtolower((string) $type['label'])
            ));
        }

        $resource = (string) $type['clearbooks_resource'];
        $payload  = self::payload($document, $extraction, $type);

        DocumentEvent::record($documentId, 'submit', DocumentEvent::STARTED, sprintf(
            'Sending %d line(s) to %s as %s.',
            count($payload['lineItems']),
            $resource,
            Auth::displayName()
        ));

        $client = new ClearBooksClient();

        try {
            $created = $client->createPurchase($resource, $payload);
        } catch (Throwable $e) {
            // Recorded before it is re-thrown: what was sent and what came back
            // is exactly what somebody debugging a rejection needs, and a
            // failure that leaves no trace is the one this table exists for.
            Submission::record($documentId, $resource, null, null, Submission::FAILED, [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);

            DocumentEvent::record($documentId, 'submit', DocumentEvent::FAILED, $e->getMessage());
            AuditLog::record('document.submit_failed', $documentId, 'Clear Books rejected it: ' . $e->getMessage());

            throw $e;
        }

        $clearbooksId = (string) ($created['id'] ?? '');

        if ($clearbooksId === '') {
            Submission::record($documentId, $resource, null, null, Submission::FAILED, [
                'error'    => 'Clear Books did not return an id.',
                'response' => $created,
            ]);

            throw new RuntimeException(
                'Clear Books accepted the document but did not say what id it gave it, so InvoGrid '
                . 'cannot link to it or attach the PDF. Check Clear Books before submitting again.'
            );
        }

        $url = ClearBooksClient::documentUrl($resource, (int) $clearbooksId);

        $submissionId = Submission::record(
            $documentId,
            $resource,
            $clearbooksId,
            $url,
            Submission::SUCCESS,
            ['response' => $created, 'payload' => $payload]
        );

        Document::transitionTo($documentId, Document::SUBMITTED);

        AuditLog::record('document.submitted', $documentId, sprintf(
            '%s created in Clear Books as %s, %s, supplier %s. Submitted by %s.',
            DocumentType::label((string) $type['type_key']),
            $clearbooksId,
            $extraction['gross_amount'] === null
                ? 'no total'
                : format_money($extraction['gross_amount'], $extraction['currency']),
            $extraction['supplier_name_raw'] ?? 'unknown',
            Auth::displayName()
        ));

        // --- Everything past here is best effort ----------------------------

        $warnings = [];

        if (Setting::bool('clearbooks_attach_pdf', true)) {
            $attached = self::attach($client, $document, $resource, (int) $clearbooksId);

            if ($attached !== null) {
                $warnings[] = $attached;
            }
        }

        $writeBack = PaperlessWriteBack::run($documentId, $extraction, $clearbooksId, $created);
        $warnings  = array_merge($warnings, $writeBack);

        DocumentEvent::record($documentId, 'submit', DocumentEvent::SUCCEEDED, sprintf(
            'Created %s %s in Clear Books.%s',
            $resource,
            $clearbooksId,
            $warnings === [] ? '' : ' ' . count($warnings) . ' follow-up step(s) did not complete.'
        ));

        return [
            'message' => sprintf(
                'Submitted. Clear Books %s %s created.%s',
                $resource,
                $clearbooksId,
                $warnings === [] ? ' Paperless is up to date.' : ' ' . implode(' ', $warnings)
            ),
            'submissionId' => $submissionId,
            'clearbooksId' => $clearbooksId,
            'url'          => $url,
            'warnings'     => $warnings,
        ];
    }

    /**
     * Build the Clear Books payload from the current values.
     *
     * "Current" is the point: these are the reviewer's numbers, not the model's.
     * By the time anything reaches here an extraction may have been edited
     * several times, and reading the columns is what makes those edits the
     * thing that gets submitted.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $extraction
     * @param array<string,mixed> $type
     * @return array<string,mixed>
     */
    private static function payload(array $document, array $extraction, array $type): array
    {
        $supplier = EntityMatch::supplier((int) $extraction['id']);

        if ($supplier === null || $supplier['matched_id'] === null) {
            throw new RuntimeException('This document has no resolved Clear Books supplier.');
        }

        $treatment = Extraction::decode($extraction, 'vat_treatment');
        $key       = (string) ($treatment['key'] ?? '');

        if ($key === '') {
            throw new RuntimeException('This document has no VAT treatment, which Clear Books requires.');
        }

        if ($extraction['invoice_date'] === null) {
            throw new RuntimeException('This document has no invoice date, which Clear Books requires.');
        }

        $payload = [
            'date'         => (string) $extraction['invoice_date'],
            'supplierId'   => (int) $supplier['matched_id'],
            'vatTreatment' => $key,
            'lineItems'    => self::lineItems($extraction, $type),
        ];

        // Only `bills` carries a due date in the API — a purchase credit note
        // has no dateDue field and sending one is a 400.
        //
        // A purchase refund posts to the bills endpoint too, where the field is
        // structurally valid, but it is still left off: a refund is money that
        // has already moved, and a due date on one would show in Clear Books as
        // an outstanding payable that nobody owes. The test is the sign rather
        // than the type key, so a new type gets the right answer without this
        // line being edited.
        $moneyOwed = str_contains((string) $type['clearbooks_resource'], 'bills')
            && (int) ($type['amount_sign'] ?? 1) > 0;

        if ($extraction['due_date'] !== null && $moneyOwed) {
            $payload['dateDue'] = (string) $extraction['due_date'];
        }

        foreach ([
            'reference'   => $extraction['invoice_number'] ?? null,
            'description' => $extraction['cb_summary'] ?? $extraction['paperless_title'] ?? null,
        ] as $field => $value) {
            if (is_string($value) && trim($value) !== '') {
                $payload[$field] = trim($value);
            }
        }

        // Omitted for sterling: the API uses the account's home currency when
        // there is no `currency`, and naming it explicitly would drag an
        // exchange rate into a document that does not have one.
        if (is_string($extraction['currency'] ?? null) && $extraction['currency'] !== '') {
            $payload['currency'] = strtoupper((string) $extraction['currency']);
        }

        return $payload;
    }

    /**
     * The line items, in Clear Books' shape.
     *
     * **`document_types.amount_sign` is applied here**, and the rule it carries
     * is Clear Books' own rather than anything InvoGrid decided:
     *
     *  - a **bill** is positive — money spent;
     *  - a **credit note** is *also* positive at the point of creation. Clear
     *    Books inverts it internally, because a credit note is an amount
     *    available to set against an invoice rather than a movement of money.
     *    Sending it negative inverts an inversion and puts the amount back
     *    where it started;
     *  - a **purchase refund** is negative — an ordinary purchase document
     *    carrying money that actually came back.
     *
     * It stays a column, so a correction is an `UPDATE` rather than a
     * deployment. It was `-1` for a credit note until somebody who knows Clear
     * Books said otherwise, which is exactly why.
     *
     * @param array<string,mixed> $extraction
     * @param array<string,mixed> $type
     * @return array<int,array<string,mixed>>
     */
    private static function lineItems(array $extraction, array $type): array
    {
        $sign  = (int) ($type['amount_sign'] ?? 1) < 0 ? -1 : 1;
        $lines = [];

        foreach (array_values(Extraction::decode($extraction, 'line_items')) as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            $number      = $index + 1;
            $description = trim((string) ($line['description'] ?? ''));
            $accountCode = $line['accountCode'] ?? null;
            $vatRateKey  = $line['vatRateKey'] ?? null;
            $quantity    = is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : 1.0;
            $unitPrice   = is_numeric($line['unitPrice'] ?? null) ? (float) $line['unitPrice'] : null;

            if ($unitPrice === null && is_numeric($line['lineTotal'] ?? null) && $quantity != 0.0) {
                $unitPrice = round((float) $line['lineTotal'] / $quantity, 4);
            }

            // The API declares all five required. Failing here, before the call,
            // gives a message naming the line; failing at Clear Books gives a
            // validation error naming an array index.
            foreach ([
                'a description' => $description !== '',
                'an account code' => $accountCode !== null && $accountCode !== '',
                'a VAT rate' => is_string($vatRateKey) && $vatRateKey !== '',
                'a unit price' => $unitPrice !== null,
            ] as $what => $present) {
                if (!$present) {
                    throw new RuntimeException('Line ' . $number . ' has no ' . $what . '.');
                }
            }

            $lines[] = [
                'description' => $description,
                'unitPrice'   => round($unitPrice * $sign, 4),
                'quantity'    => $quantity,
                'accountCode' => (int) $accountCode,
                'vatRateKey'  => (string) $vatRateKey,
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('This document has no line items, and Clear Books requires at least one.');
        }

        return $lines;
    }

    /**
     * Attach the source PDF to the record just created.
     *
     * Returns a warning rather than throwing: the record exists, and the
     * accounts having the evidence attached is worth a lot but not worth
     * pretending the submission failed.
     */
    private static function attach(
        ClearBooksClient $client,
        array $document,
        string $resource,
        int $clearbooksId,
    ): ?string {
        if ($document['pdf_path'] === null) {
            return 'The PDF is not stored locally, so nothing was attached.';
        }

        $path = IngestStage::absolutePath((string) $document['pdf_path']);

        if ($path === null) {
            return 'The stored PDF is missing from disk, so nothing was attached.';
        }

        // Named for the Paperless document rather than the temporary path, so
        // somebody looking at the attachment in Clear Books can find the scan.
        $name = 'paperless-' . (int) $document['paperless_doc_id'] . '.pdf';

        try {
            $client->attachToPurchase($resource, $clearbooksId, $name, $path);
        } catch (Throwable $e) {
            AuditLog::record(
                'clearbooks.attachment_failed',
                (int) $document['id'],
                'Could not attach the PDF to ' . $resource . ' ' . $clearbooksId . ': ' . $e->getMessage()
            );

            return 'The PDF could not be attached: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * The document type to submit as.
     *
     * @param array<string,mixed> $extraction
     * @return array<string,mixed>
     */
    private static function documentType(array $extraction): array
    {
        $key = $extraction['doc_type'] ?? null;

        if (!is_string($key) || $key === '') {
            throw new RuntimeException(
                'This document has not been classified as a bill or a credit note, so InvoGrid does '
                . 'not know what to create. Set the type on the review screen.'
            );
        }

        $type = DocumentType::find($key);

        if ($type === null) {
            throw new RuntimeException('"' . $key . '" is not a document type InvoGrid knows about.');
        }

        if (trim((string) ($type['clearbooks_resource'] ?? '')) === '') {
            throw new RuntimeException(
                'The "' . $type['label'] . '" document type has no Clear Books resource configured.'
            );
        }

        return $type;
    }

    /**
     * Is this document one a person may submit right now?
     *
     * Used by the screens to decide whether to offer the button at all — the
     * checks that matter are re-made in submit(), because a page can be stale
     * and a button being hidden is not a guarantee.
     */
    public static function isSubmittable(array $document, ?array $extraction): bool
    {
        return (string) $document['status'] === Document::READY_TO_SUBMIT
            && $extraction !== null
            && Submission::successful((int) $document['id']) === null
            && EntityMatch::unresolved((int) $extraction['id']) === []
            && (!DocumentType::requiresConfirmation($extraction['doc_type'] ?? null)
                || Extraction::typeConfirmed($extraction))
            && ClearBooksClient::isConnected();
    }

    /** Whether the cached VAT rate list can price this document. Used for a hint only. */
    public static function vatKnown(array $extraction): bool
    {
        foreach (Extraction::decode($extraction, 'line_items') as $line) {
            $key = is_array($line) ? ($line['vatRateKey'] ?? null) : null;

            if (!is_string($key) || ClearbooksCache::vatPercentage($key) === null) {
                return false;
            }
        }

        return true;
    }
}
