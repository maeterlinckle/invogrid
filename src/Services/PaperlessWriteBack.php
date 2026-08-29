<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClearbooksCache;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EntityMatch;
use App\Models\Extraction;
use App\Models\OcrResult;
use App\Models\Setting;
use Throwable;

/**
 * Make the Paperless document agree with what went into Clear Books.
 *
 * Paperless is the archive; Clear Books is the ledger. Once a document is in
 * both, somebody searching the archive should be able to find it by supplier,
 * by the words on the page, and by the Clear Books number — none of which is
 * true of a freshly scanned PDF called `scan_0412.pdf` filed under nothing.
 *
 * Six writes, and **each one is attempted independently**. A tag id that has
 * been deleted in Paperless must not cost the document its title, its
 * correspondent and its searchable text. Every failure is collected and
 * reported; none of them throws, because by the time this runs the Clear Books
 * record already exists and the submission has already been recorded.
 *
 * Nothing here is a guess. The correspondent comes from
 * `clearbooks_cache.paperless_correspondent_id`, the document type from
 * `document_types.paperless_document_type_id`, and each custom field from its
 * own `paperless_field_id` — all of them nullable, and an unset one means the
 * write is skipped and said so, never that an id is invented.
 */
final class PaperlessWriteBack
{
    /**
     * @param array<string,mixed> $extraction
     * @param array<string,mixed> $clearbooksResponse
     * @return array<int,string> Warnings; empty means everything landed
     */
    public static function run(int $documentId, array $extraction, string $clearbooksId, array $clearbooksResponse = []): array
    {
        $document = Document::find($documentId);

        if ($document === null) {
            return ['The document row disappeared, so Paperless was not updated.'];
        }

        if (!PaperlessClient::isConfigured()) {
            return ['Paperless is not configured, so nothing was written back to it.'];
        }

        $paperlessId = (int) $document['paperless_doc_id'];
        $warnings    = [];

        try {
            $paperless = new PaperlessClient();
        } catch (Throwable $e) {
            return ['Paperless could not be reached, so nothing was written back: ' . $e->getMessage()];
        }

        // --- Everything that is one PATCH ----------------------------------

        $fields = [];

        $title = self::str($extraction['paperless_title'] ?? null, 128);
        if ($title !== null) {
            $fields['title'] = $title;
        }

        $correspondentId = self::correspondentId($extraction);
        if ($correspondentId !== null) {
            $fields['correspondent'] = $correspondentId;
        } else {
            $warnings[] = 'The supplier has no linked Paperless correspondent, so the document was left unfiled.';
        }

        $documentTypeId = self::documentTypeId($extraction);
        if ($documentTypeId !== null) {
            $fields['document_type'] = $documentTypeId;
        }

        // Deliberately replaces whatever Paperless's own OCR produced. On a
        // scanned invoice the LLM reading is better, and it is the only version
        // that carries the handwritten annotations — which is exactly what
        // somebody searching the archive six months later is looking for.
        // Switchable, because overwriting somebody's search index is a decision
        // an operator is entitled to make differently.
        if (Setting::bool('paperless_replace_content', true)) {
            $ocr  = OcrResult::latest($documentId);
            $text = $ocr === null ? '' : OcrResult::text($ocr);

            if (trim($text) !== '') {
                $fields['content'] = $text;
            }
        }

        $tags = self::tags($paperless, $paperlessId, $warnings);
        if ($tags !== null) {
            $fields['tags'] = $tags;
        }

        if ($fields !== []) {
            try {
                $paperless->updateDocument($paperlessId, $fields);
            } catch (Throwable $e) {
                $warnings[] = 'Paperless would not accept the update: ' . $e->getMessage();
            }
        }

        // --- Custom fields, which are their own merge ----------------------

        $values = self::customFieldValues($extraction, $clearbooksId, $clearbooksResponse, $warnings);

        if ($values !== []) {
            try {
                // Merged rather than replaced: a PATCH of `custom_fields`
                // replaces the whole list, so writing the Clear Books id
                // naively would wipe every field somebody had set by hand.
                $paperless->setCustomFields($paperlessId, $values);
            } catch (Throwable $e) {
                $warnings[] = 'The Paperless custom fields could not be set: ' . $e->getMessage();
            }
        }

        // --- The note ------------------------------------------------------

        try {
            $paperless->addNote($paperlessId, self::note($extraction, $clearbooksId, $clearbooksResponse));
        } catch (Throwable $e) {
            $warnings[] = 'The Paperless note could not be added: ' . $e->getMessage();
        }

        AuditLog::record(
            'paperless.written_back',
            $documentId,
            $warnings === []
                ? 'Paperless document ' . $paperlessId . ' updated to match Clear Books ' . $clearbooksId . '.'
                : 'Paperless document ' . $paperlessId . ' partly updated. ' . implode(' ', $warnings)
        );

        return $warnings;
    }

    /**
     * The Paperless correspondent for the matched supplier.
     *
     * Read from the cache row, which is where the sync keeps the link. Never
     * from what the extraction call claimed: a model that reported a
     * `paperlessId` reported a number it saw in a list, and filing a document
     * under the wrong correspondent is quiet and annoying to undo.
     */
    private static function correspondentId(array $extraction): ?int
    {
        $supplier = EntityMatch::supplier((int) $extraction['id']);

        if ($supplier === null || $supplier['matched_id'] === null) {
            return null;
        }

        $cached = ClearbooksCache::find(ClearbooksCache::SUPPLIER, (string) $supplier['matched_id']);

        return $cached === null || $cached['paperless_correspondent_id'] === null
            ? null
            : (int) $cached['paperless_correspondent_id'];
    }

    /** The Paperless document type paired with this InvoGrid type, if any. */
    private static function documentTypeId(array $extraction): ?int
    {
        $key = $extraction['doc_type'] ?? null;

        if (!is_string($key) || $key === '') {
            return null;
        }

        $type = DocumentType::find($key);

        return $type === null || $type['paperless_document_type_id'] === null
            ? null
            : (int) $type['paperless_document_type_id'];
    }

    /**
     * The tag list to write, with the "processed" tag added.
     *
     * Returns null when there is nothing to do, so the caller leaves `tags` out
     * of the PATCH entirely — sending the existing list back unchanged would be
     * a write that could only go wrong.
     *
     * @param array<int,string> $warnings
     * @return array<int,int>|null
     */
    private static function tags(PaperlessClient $paperless, int $paperlessId, array &$warnings): ?array
    {
        $tagId = (int) Setting::int('paperless_processed_tag_id', 0);

        if ($tagId <= 0) {
            return null;
        }

        try {
            $existing = $paperless->document($paperlessId)['tags'] ?? [];
        } catch (Throwable $e) {
            $warnings[] = 'The processed tag could not be added: ' . $e->getMessage();

            return null;
        }

        $tags = array_values(array_unique(array_map('intval', is_array($existing) ? $existing : [])));

        if (in_array($tagId, $tags, true)) {
            return null;
        }

        $tags[] = $tagId;

        return $tags;
    }

    /**
     * Paperless field id => value, for everything that has somewhere to go.
     *
     * Two kinds of field end up here. The ones read off the page keep whatever
     * the reviewer left them as; the two the submission produces — the Clear
     * Books id and the number Clear Books assigned — are filled in now, because
     * neither existed until a moment ago.
     *
     * @param array<int,string> $warnings
     * @return array<int,mixed>
     */
    private static function customFieldValues(
        array $extraction,
        string $clearbooksId,
        array $clearbooksResponse,
        array &$warnings,
    ): array {
        $extracted = Extraction::decode($extraction, 'custom_field_values');

        $produced = [
            'clearbooks_bill_id'         => $clearbooksId,
            'clearbooks_document_number' => self::str(
                $clearbooksResponse['formattedDocumentNumber'] ?? $clearbooksResponse['documentNumber'] ?? null,
                64
            ),
        ];

        $values   = [];
        $unpaired = [];

        foreach (CustomField::active() as $field) {
            $key      = (string) $field['field_key'];
            $isSource = (string) $field['source'] === CustomField::SUBMISSION;
            $value    = $isSource ? ($produced[$key] ?? null) : ($extracted[$key] ?? null);

            if ($value === null || $value === '') {
                continue;
            }

            if ($field['paperless_field_id'] === null) {
                $unpaired[] = (string) $field['label'];
                continue;
            }

            $values[(int) $field['paperless_field_id']] = CustomField::coerce((string) $field['data_type'], $value);
        }

        if ($unpaired !== []) {
            // Worth saying once rather than silently dropping: an operator who
            // set up a field and never sees it in Paperless has no way to guess
            // that the pairing is what is missing.
            $warnings[] = 'Not paired with a Paperless field, so not written back: ' . implode(', ', $unpaired) . '.';
        }

        return $values;
    }

    /**
     * The note left on the Paperless document.
     *
     * What a person opening the archive wants to know: that it went to Clear
     * Books, as what, for how much, and against whom.
     */
    private static function note(array $extraction, string $clearbooksId, array $clearbooksResponse): string
    {
        $number = self::str(
            $clearbooksResponse['formattedDocumentNumber'] ?? $clearbooksResponse['documentNumber'] ?? null,
            64
        );

        return sprintf(
            '[InvoGrid] Submitted to Clear Books as %s %s%s. Supplier: %s. Total: %s.%s',
            strtolower(DocumentType::label($extraction['doc_type'] ?? null)),
            $clearbooksId,
            $number === null ? '' : ' (' . $number . ')',
            $extraction['supplier_name_raw'] ?? 'not recorded',
            $extraction['gross_amount'] === null
                ? (($extraction['net_amount'] ?? null) === null
                    ? 'not recorded'
                    : format_money($extraction['net_amount'], $extraction['currency']) . ' net')
                : format_money($extraction['gross_amount'], $extraction['currency']),
            $extraction['invoice_number'] === null ? '' : ' Their reference: ' . $extraction['invoice_number'] . '.'
        );
    }

    private static function str(mixed $value, int $limit): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
