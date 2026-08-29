<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClearbooksCache;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentType;
use App\Models\Extraction;
use App\Models\OcrResult;
use App\Models\PromptTemplate;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmFactory;
use RuntimeException;

/**
 * Turn one transcription into structured fields, in three focused calls.
 *
 * Three rather than one, following the split the n8n flow arrived at: a header
 * call, a supplier-match call and a line-items call. They are independent, they
 * each get a prompt short enough to reason about, and a model that is confused
 * about VAT treatment does not therefore get the invoice date wrong.
 *
 * All three read the same transcription. None of them sees the page images
 * again — that reading has been done and paid for, and sending the pages to
 * three more calls would triple the cost of every document for nothing.
 *
 * **If any one call fails to parse, the whole stage fails.** A document with
 * two thirds of its fields and a silent gap in the middle is worse than a
 * document that plainly stopped: the gap gets noticed at the point somebody has
 * already approved the rest.
 */
final class ExtractStage
{
    /**
     * @param array<string,mixed> $document
     * @return string The status the document moves to
     */
    public function run(array $document): string
    {
        $id  = (int) $document['id'];
        $ocr = OcrResult::latest($id);

        if ($ocr === null) {
            throw new RuntimeException(
                'This document has no transcription to extract from. Retry it from Reading pages.'
            );
        }

        $ocrText = OcrResult::text($ocr);

        if (trim($ocrText) === '') {
            throw new RuntimeException('The stored transcription is empty. Retry this document from Reading pages.');
        }

        $client    = LlmFactory::forStage('extraction');
        $variables = $this->variables($ocrText);
        $notes     = $this->listWarnings();

        DocumentEvent::record($id, 'extract', DocumentEvent::STARTED, sprintf(
            'Three calls to %s (%s) off a %s-character transcription.',
            $client->provider(),
            $client->model(),
            number_format(mb_strlen($ocrText))
        ));

        // Sequential rather than concurrent. They are independent and could run
        // in parallel, but PHP without an event loop would mean forking or curl
        // multi-handles, and the whole stage is a handful of seconds against a
        // queue that runs every minute. Not worth the machinery.
        $header   = $this->call($client, 'extract_header', $variables, ['paperlessTitle', 'dateInvoice', 'dateDue']);
        $supplier = $this->call($client, 'extract_supplier', $variables, ['supplierMatched']);
        $lines    = $this->call($client, 'extract_lines', $variables, ['documentType', 'lineItems']);

        $custom = $this->customFields($client, $variables, $ocr);

        // Every call reports what it was unsure of. Merged into one list, each
        // note saying which call raised it, because "uncertain account code" and
        // "ambiguous due date" want different people looking at them.
        foreach ([
            'Header'        => $header,
            'Supplier'      => $supplier,
            'Line items'    => $lines,
            'Custom fields' => $custom,
        ] as $label => $result) {
            foreach ((array) ($result['reviewNotes'] ?? []) as $note) {
                if (is_string($note) && trim($note) !== '') {
                    $notes[] = $label . ': ' . trim($note);
                }
            }
        }

        // An unmatched supplier is deliberately **not** flagged here.
        //
        // It was, until the matching stage existed: an unresolved entity is a
        // reason for review, and this was the only stage that knew about one.
        // But this call only ever consults the cached list, and the
        // deterministic name pass that runs next resolves a good proportion of
        // what it leaves open — "ACME SUPPLIES LTD." against "Acme Supplies
        // Limited" and the like. A note raised here would then be *false* and
        // would hold the document in review for good, because nothing later
        // has the standing to withdraw an earlier stage's judgement.
        //
        // The supplier's own `entity_matches` row is the record now, and
        // MatchStage raises the note if it is still unresolved after its pass.

        $lineItems = $this->normaliseLines($lines['lineItems'] ?? [], $notes);
        $totals    = $this->totals($lineItems);
        $docType   = $this->documentType($lines['documentType'] ?? null, $notes);

        $extractionId = Extraction::create($id, [
            'ocr_result_id'       => (int) $ocr['id'],
            'doc_type'            => $docType,

            // Why it said so, in one sentence quoting what decided it. Shown
            // beside the confirmation rather than in the review notes: it is
            // not a flag, it is the evidence for a judgement a person is
            // about to agree or overturn.
            'doc_type_reason'     => $this->str($lines['documentTypeReason'] ?? null, 500),

            'paperless_title'     => $this->str($header['paperlessTitle'] ?? null, 255),
            'cb_summary'          => $this->str($header['cbSummary'] ?? null, 255),
            'invoice_number'      => $this->str($header['reference'] ?? null, 100),
            'invoice_date'        => $this->date($header['dateInvoice'] ?? null),
            'due_date'            => $this->date($header['dateDue'] ?? null),
            'paid_date'           => $this->date($header['datePaid'] ?? null),
            'currency'            => $this->currency($header['currency'] ?? null),

            'supplier_name_raw'   => $this->supplierName($supplier),
            'supplier_match'      => $supplier,

            'net_amount'          => $totals['net'],
            'vat_amount'          => $totals['vat'],
            'gross_amount'        => $totals['gross'],

            'vat_treatment'       => is_array($lines['vatTreatment'] ?? null) ? $lines['vatTreatment'] : null,
            'line_items'          => $lineItems,
            'custom_field_values' => $custom['values'] ?? [],
            'review_notes'        => $notes,
            'needs_review'        => $notes !== [],

            'llm_provider'        => $client->provider(),
            'llm_model'           => $client->model(),
            'prompt_template_id'  => $this->promptId('extract_header'),
        ]);

        // The document's own copy of the supplier guess, so the list page can
        // show who it is from without reading the extraction.
        Document::setExtractionSummary($id, $docType, $this->supplierName($supplier));

        DocumentEvent::record($id, 'extract', DocumentEvent::SUCCEEDED, sprintf(
            'Extraction #%d: %s, %d line%s, %s. %s',
            $extractionId,
            DocumentType::label($docType),
            count($lineItems),
            count($lineItems) === 1 ? '' : 's',
            $totals['net'] === null ? 'no total' : 'net ' . number_format($totals['net'], 2),
            $notes === [] ? 'Nothing flagged.' : count($notes) . ' thing(s) flagged for review.'
        ));

        return Document::EXTRACTED;
    }

    /**
     * The values every extraction prompt can draw on.
     *
     * All of them are offered to every call — a prompt takes what it names and
     * ignores the rest, which means somebody editing one can add
     * `{{ accountCodes }}` to the header prompt without a code change.
     *
     * @return array<string,string>
     */
    private function variables(string $ocrText): array
    {
        return [
            'ocrText'       => $ocrText,
            'today'         => date('Y-m-d'),
            'suppliers'     => PromptRenderer::encodeList(ClearbooksCache::forPrompt(ClearbooksCache::SUPPLIER)),
            'accountCodes'  => PromptRenderer::encodeList(ClearbooksCache::forPrompt(ClearbooksCache::ACCOUNT_CODE)),
            'vatRates'      => PromptRenderer::encodeList(ClearbooksCache::forPrompt(ClearbooksCache::VAT_RATE)),
            'vatTreatments' => PromptRenderer::encodeList(ClearbooksCache::forPrompt(ClearbooksCache::VAT_TREATMENT)),
            'customFields'  => PromptRenderer::encodeList(CustomField::forPrompt()),
        ];
    }

    /**
     * Say plainly when a reference list is empty.
     *
     * The prompts tell the model to pick only from the list it is given. Given
     * an empty one it has no honest answer, so the result is a guess that looks
     * like a match. Better for a person to be told the cache has not been
     * filled than to wonder why every account code is wrong.
     *
     * @return array<int,string>
     */
    private function listWarnings(): array
    {
        $notes = [];

        foreach ([
            ClearbooksCache::SUPPLIER      => 'suppliers',
            ClearbooksCache::ACCOUNT_CODE  => 'account codes',
            ClearbooksCache::VAT_RATE      => 'VAT rates',
            ClearbooksCache::VAT_TREATMENT => 'VAT treatments',
        ] as $entityType => $label) {
            if (ClearbooksCache::count($entityType) === 0) {
                $notes[] = 'Setup: the cached ' . $label . ' list is empty, so nothing could be matched against it.';
            }
        }

        return $notes;
    }

    /**
     * Render one prompt, call the model, and insist on the JSON it asked for.
     *
     * Strict on purpose. A response that will not parse, or that is missing a
     * key the prompt specified, stops the stage — it is never coerced into
     * whatever fields happen to be present. The error carries the start of what
     * did come back, because "the model returned prose" and "the model returned
     * JSON with a different shape" need different fixes and the message should
     * say which happened.
     *
     * @param array<string,string> $variables
     * @param array<int,string>    $required
     * @return array<string,mixed>
     */
    private function call(LlmClient $client, string $key, array $variables, array $required): array
    {
        $prompt = PromptTemplate::active($key);

        if ($prompt === null) {
            throw new RuntimeException(
                'No active "' . $key . '" prompt. Run the migrations, or activate one in Settings.'
            );
        }

        // A prompt naming a variable nothing provides throws here, before a
        // request is made — rather than sending a literal `{{ suppliers }}` to
        // a model that will answer confidently about nothing.
        $rendered = PromptRenderer::render((string) $prompt['content'], $variables);

        /*
         * Which of the four calls this was.
         *
         * The client knows the provider, the model and the status; only this
         * knows that the failure was the supplier call rather than the header
         * one. "Extraction failed" against four LLM calls is the difference
         * between a diagnosis and a shrug, so the label is added on the way
         * back up rather than threaded down through every client method.
         */
        try {
            $response = $client->complete($rendered);
        } catch (LlmException $e) {
            throw $e->during($key);
        }

        $decoded = $response->json();

        // Context for both of the shapes below: the model answered, so the
        // provider, the model and the call are all known, and the answer itself
        // is the thing worth looking at.
        $context = [
            'call'     => $key,
            'provider' => $response->provider,
            'model'    => $response->model,
            'answered' => mb_substr(trim($response->text), 0, 400),
            'took ms'  => $response->durationMs,
        ];

        if ($decoded === null) {
            throw new LlmException(sprintf(
                'The %s call did not return JSON. It began: %s',
                $key,
                $this->snippet($response->text)
            ), false, null, $context);
        }

        $missing = array_values(array_filter(
            $required,
            static fn (string $field): bool => !array_key_exists($field, $decoded)
        ));

        if ($missing !== []) {
            throw new LlmException(sprintf(
                'The %s call returned JSON without %s. It returned: %s',
                $key,
                implode(' or ', $missing),
                implode(', ', array_keys($decoded)) ?: 'nothing'
            ), false, null, $context);
        }

        return $decoded;
    }

    /**
     * Resolve the custom fields, cheapest route first.
     *
     * 1. The OCR call already reported the annotation fields it was asked for.
     *    Where a custom field lines up with one of those, the answer is already
     *    in hand and costs nothing.
     * 2. Failing that, the `### Notes` section at the end of the transcription
     *    states them in a fixed form, so a read of that text settles most of
     *    the rest.
     * 3. Only what is still unresolved goes to a model, and only then.
     *
     * On the ordinary document steps 1 and 2 answer everything and there is no
     * fourth call at all.
     *
     * @param array<string,string> $variables
     * @param array<string,mixed>  $ocr
     * @return array{values:array<string,mixed>,reviewNotes:array<int,string>}
     */
    private function customFields(LlmClient $client, array $variables, array $ocr): array
    {
        $fields = CustomField::extracted();

        if ($fields === []) {
            return ['values' => [], 'reviewNotes' => []];
        }

        $structured = OcrResult::structured($ocr) ?? [];
        $notesBlock = $this->notesSection(OcrResult::text($ocr));

        $values     = [];
        $unresolved = [];

        foreach ($fields as $field) {
            $key   = (string) $field['field_key'];
            $type  = (string) $field['data_type'];
            $value = null;

            // 1. Straight from the OCR call. `clearbooks_number` and
            //    `clearbooksNumber` are the same name once punctuation and case
            //    stop counting, which is what lets an operator name a field
            //    however reads best without breaking the link.
            foreach ($structured as $ocrKey => $ocrValue) {
                if (self::sameKey((string) $ocrKey, $key)) {
                    $value = CustomField::coerce($type, $ocrValue);
                    break;
                }
            }

            // 2. The ### Notes section, which states them by label.
            if ($value === null && $notesBlock !== '') {
                $value = CustomField::coerce($type, $this->fromNotes($notesBlock, (string) $field['label']));
            }

            if ($value === null) {
                $unresolved[] = $field;
            }

            $values[$key] = $value;
        }

        if ($unresolved === []) {
            return ['values' => $values, 'reviewNotes' => []];
        }

        // 3. Ask, but only about what is left.
        $variables['customFields'] = PromptRenderer::encodeList(array_map(
            static function (array $field): array {
                $entry = [
                    'key'   => (string) $field['field_key'],
                    'label' => (string) $field['label'],
                    'type'  => (string) $field['data_type'],
                ];

                if ($field['prompt_hint'] !== null && trim((string) $field['prompt_hint']) !== '') {
                    $entry['hint'] = trim((string) $field['prompt_hint']);
                }

                return $entry;
            },
            $unresolved
        ));

        $answer = $this->call($client, 'extract_custom_fields', $variables, ['values']);

        foreach ($unresolved as $field) {
            $key   = (string) $field['field_key'];
            $given = $answer['values'][$key] ?? null;

            $values[$key] = CustomField::coerce((string) $field['data_type'], $given);
        }

        $notes = [];
        foreach ((array) ($answer['reviewNotes'] ?? []) as $note) {
            if (is_string($note) && trim($note) !== '') {
                $notes[] = trim($note);
            }
        }

        return ['values' => $values, 'reviewNotes' => $notes];
    }

    /** The `### Notes` block at the end of a transcription, or ''. */
    private function notesSection(string $ocrText): string
    {
        $at = strrpos($ocrText, '### Notes');

        return $at === false ? '' : substr($ocrText, $at);
    }

    /**
     * A labelled value out of the notes block.
     *
     * The section states them as `Clearbooks Number: 80421`, with "Not found"
     * where there is none — which has to read as absent rather than as the
     * literal string.
     */
    private function fromNotes(string $notes, string $label): ?string
    {
        $pattern = '/^\s*(?:[-*]\s*)?(?:\*\*)?' . preg_quote($label, '/') . '(?:\*\*)?\s*:\s*(.+)$/mi';

        if (preg_match($pattern, $notes, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1], " \t\r\n*_`");

        if ($value === '' || strcasecmp($value, 'not found') === 0 || strcasecmp($value, 'none') === 0) {
            return null;
        }

        return $value;
    }

    /**
     * Are these the same name, ignoring case and punctuation?
     *
     * `clearbooks_number`, `clearbooksNumber` and `Clearbooks Number` all reduce
     * to the same thing.
     */
    private static function sameKey(string $a, string $b): bool
    {
        $reduce = static fn (string $s): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $s));

        return $reduce($a) !== '' && $reduce($a) === $reduce($b);
    }

    /**
     * Coerce the line items into the shape the rest of the application expects.
     *
     * The prompt says every field is required and no field may be null. This
     * does not trust that: a line that arrives short is repaired where it can be
     * and flagged where it cannot, because the alternative is a null landing in
     * a Clear Books submission three stages later.
     *
     * @param mixed              $raw
     * @param array<int,string>  $notes
     * @return array<int,array<string,mixed>>
     */
    private function normaliseLines(mixed $raw, array &$notes): array
    {
        if (!is_array($raw) || $raw === []) {
            $notes[] = 'Line items: none were found on this document.';

            return [];
        }

        $lines = [];

        foreach (array_values($raw) as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            $number      = $index + 1;
            $quantity    = is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : null;
            $unitPrice   = is_numeric($line['unitPrice'] ?? null) ? (float) $line['unitPrice'] : null;
            $lineTotal   = is_numeric($line['lineTotal'] ?? null) ? (float) $line['lineTotal'] : null;
            $description = trim((string) ($line['description'] ?? ''));

            if ($description === '') {
                $notes[] = 'Line ' . $number . ': no description.';
            }

            // The prompt tells the model to prefer the printed line total and
            // reconcile the unit price against it. Same rule here, applied to
            // whatever actually arrived.
            if ($lineTotal === null && $quantity !== null && $unitPrice !== null) {
                $lineTotal = round($quantity * $unitPrice, 2);
            } elseif ($lineTotal !== null && $quantity !== null && $unitPrice === null && $quantity != 0.0) {
                $unitPrice = round($lineTotal / $quantity, 4);
            }

            if ($lineTotal !== null && $quantity !== null && $unitPrice !== null) {
                $expected = round($quantity * $unitPrice, 2);

                // A penny either way is rounding; more than that is a real
                // disagreement worth a person's eye.
                if (abs($expected - $lineTotal) > 0.01) {
                    $notes[] = sprintf(
                        'Line %d: %s x %s comes to %s, but the line total says %s.',
                        $number,
                        rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'),
                        number_format($unitPrice, 2),
                        number_format($expected, 2),
                        number_format($lineTotal, 2)
                    );
                }
            }

            if (($line['accountCode'] ?? null) === null || $line['accountCode'] === '') {
                $notes[] = 'Line ' . $number . ': no account code was chosen.';
            }

            if (($line['vatRateKey'] ?? null) === null || $line['vatRateKey'] === '') {
                $notes[] = 'Line ' . $number . ': no VAT rate was chosen.';
            }

            $lines[] = [
                'description' => $description,
                'quantity'    => $quantity,
                'unitPrice'   => $unitPrice,
                'lineTotal'   => $lineTotal,
                'accountCode' => $line['accountCode'] ?? null,
                'vatRateKey'  => $line['vatRateKey'] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * Net, VAT and gross from the line items.
     *
     * The arithmetic lives on the model, because the review screen and the
     * entity picker need exactly the same answer and three copies of it drifted
     * once already. What the rules are, and why, is documented there.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array{net:?float,vat:?float,gross:?float}
     */
    private function totals(array $lines): array
    {
        return Extraction::totalsFromLines($lines);
    }

    /**
     * Map the model's document type onto a `document_types.type_key`.
     *
     * The prompt says `"bill"` or `"creditNote"`; the table holds `bill` and
     * `credit_note`. Rather than keep a translation table that has to be edited
     * every time a type is added, both sides are reduced to letters and digits
     * and compared — so a new type is still just a row.
     *
     * @param array<int,string> $notes
     */
    private function documentType(mixed $given, array &$notes): ?string
    {
        $given = is_string($given) ? trim($given) : '';

        if ($given === '') {
            $notes[] = 'Document type: none was returned; left unclassified.';

            return null;
        }

        foreach (DocumentType::keys() as $typeKey) {
            if (self::sameKey($given, $typeKey)) {
                return $typeKey;
            }
        }

        $notes[] = 'Document type: the model said "' . $given . '", which is not a type InvoGrid knows about.';

        return null;
    }

    /** The supplier's name, matched or not. */
    private function supplierName(array $supplier): ?string
    {
        foreach (['matchedName', 'name'] as $key) {
            $value = $supplier[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 255);
            }
        }

        // A matched supplier leaves `name` null by design — the name is the one
        // already on file, which the matching stage will fill in from the cache.
        $cbId = $supplier['cbId'] ?? null;

        if (is_scalar($cbId) && (string) $cbId !== '') {
            $cached = ClearbooksCache::find(ClearbooksCache::SUPPLIER, (string) $cbId);

            if ($cached !== null) {
                return (string) $cached['name'];
            }
        }

        return null;
    }

    private function promptId(string $key): ?int
    {
        $prompt = PromptTemplate::active($key);

        return $prompt === null ? null : (int) $prompt['id'];
    }

    private function str(mixed $value, int $limit): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /** A date the database will accept, or null. */
    private function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));

        if ($date !== false && $date->format('Y-m-d') === trim($value)) {
            return trim($value);
        }

        // The prompt asks for YYYY-MM-DD; anything else is still worth one
        // attempt at understanding before it is dropped.
        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * An ISO 4217 code, or null for sterling.
     *
     * The prompt returns null for GBP by design, so "GBP" arriving explicitly is
     * treated the same way rather than stored as an exception to the rule.
     */
    private function currency(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $code = strtoupper(trim($value));

        if ($code === '' || $code === 'GBP' || !preg_match('/^[A-Z]{3}$/', $code)) {
            return null;
        }

        return $code;
    }

    private function snippet(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $text === '' ? '(nothing at all)' : '"' . str_limit($text, 200) . '"';
    }
}
