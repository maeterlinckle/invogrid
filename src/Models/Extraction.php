<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * What the extraction calls made of one transcription.
 *
 * Three focused calls write into one row: they are facets of a single reading
 * of a single document, and the review screen wants them together. Kept per run
 * rather than overwritten, so a re-extraction can be compared against the one
 * before it.
 *
 * Everything the model reported is stored as data — the JSON columns are real
 * structure, not a string somebody has to parse back out.
 */
final class Extraction
{
    /** @param array<string,mixed> $fields */
    public static function create(int $documentId, array $fields): int
    {
        $json = static fn (mixed $value): ?string => $value === null || $value === []
            ? null
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Database::insert('extractions', [
            'document_id'         => $documentId,
            'ocr_result_id'       => $fields['ocr_result_id'] ?? null,
            'doc_type'            => $fields['doc_type'] ?? null,
            'doc_type_reason'     => $fields['doc_type_reason'] ?? null,

            'document_title'     => $fields['document_title'] ?? null,
            'cb_summary'          => $fields['cb_summary'] ?? null,
            'supplier_name_raw'   => $fields['supplier_name_raw'] ?? null,
            'invoice_number'      => $fields['invoice_number'] ?? null,
            'invoice_date'        => $fields['invoice_date'] ?? null,
            'due_date'            => $fields['due_date'] ?? null,
            'paid_date'           => $fields['paid_date'] ?? null,

            'net_amount'          => $fields['net_amount'] ?? null,
            'vat_amount'          => $fields['vat_amount'] ?? null,
            'gross_amount'        => $fields['gross_amount'] ?? null,
            'currency'            => $fields['currency'] ?? null,

            'vat_treatment'       => $json($fields['vat_treatment'] ?? null),
            'supplier_match'      => $json($fields['supplier_match'] ?? null),
            'line_items'          => $json($fields['line_items'] ?? null),
            'custom_field_values' => $json($fields['custom_field_values'] ?? null),
            'confidence'          => $json($fields['confidence'] ?? null),
            'review_notes'        => $json($fields['review_notes'] ?? null),

            'needs_review'        => !empty($fields['needs_review']) ? 1 : 0,

            'llm_provider'        => $fields['llm_provider'] ?? null,
            'llm_model'           => $fields['llm_model'] ?? null,
            'prompt_template_id'  => $fields['prompt_template_id'] ?? null,
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function latest(int $documentId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM extractions WHERE document_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$documentId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forDocument(int $documentId): array
    {
        return Database::select(
            'SELECT * FROM extractions WHERE document_id = ? ORDER BY created_at DESC, id DESC',
            [$documentId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM extractions WHERE id = ?', [$id]);
    }

    /**
     * Decode one of the JSON columns.
     *
     * A single reader so no caller has to remember which columns are JSON, and
     * so a column that is null, empty or somehow unparseable comes back as the
     * same empty array everywhere rather than as three different failures.
     *
     * @param array<string,mixed> $row
     * @return array<mixed>
     */
    public static function decode(array $row, string $column): array
    {
        $value = $row[$column] ?? null;

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Apply a reviewer's edits.
     *
     * The one place a person's corrections reach an extraction, and deliberately
     * a **whitelist**: `$fields` comes from a form, and a POST naming
     * `llm_model` or `needs_review` must change neither. What a model said and
     * what the pipeline made of it are not a reviewer's to rewrite; the values
     * on the document are.
     *
     * `edited_at` and `edited_by` are stamped so the review screen can say the
     * record has been touched by hand — an extraction that no longer matches
     * what the model returned should not look like one that does.
     *
     * @param array<string,mixed> $fields
     * @return array<int,string> The columns that actually changed
     */
    public static function updateFields(int $id, array $fields, ?int $userId = null): array
    {
        $scalar = [
            'doc_type', 'document_title', 'cb_summary', 'supplier_name_raw',
            'invoice_number', 'invoice_date', 'due_date', 'paid_date',
            'net_amount', 'vat_amount', 'gross_amount', 'currency',
        ];

        $json = ['vat_treatment', 'supplier_match', 'line_items', 'custom_field_values'];

        $before = self::find($id);

        if ($before === null) {
            return [];
        }

        $update  = [];
        $changed = [];

        foreach ($scalar as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }

            $value = $fields[$column];
            $value = is_string($value) && trim($value) === '' ? null : $value;

            if ((string) ($before[$column] ?? '') !== (string) ($value ?? '')) {
                $changed[] = $column;
            }

            $update[$column] = $value;
        }

        foreach ($json as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }

            $encoded = $fields[$column] === null || $fields[$column] === []
                ? null
                : json_encode($fields[$column], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Compared as decoded structures rather than as strings: two
            // encodings of the same data differ by key order alone, and
            // reporting that as an edit would fill the audit log with noise.
            if (self::decode($before, $column) !== ($fields[$column] === null ? [] : $fields[$column])) {
                $changed[] = $column;
            }

            $update[$column] = $encoded;
        }

        if ($update === []) {
            return [];
        }

        if ($changed !== []) {
            $update['edited_at'] = date('Y-m-d H:i:s');
            $update['edited_by'] = $userId;
        }

        // Changing what kind of document this is withdraws any agreement to
        // what it was. Somebody who switches a credit note to a refund in the
        // ordinary Type box has not thereby confirmed the refund — they may
        // simply be correcting an obvious mistake on the way to looking at it
        // properly. `confirmType()` sets the type first and stamps afterwards,
        // so it is unaffected.
        if (in_array('doc_type', $changed, true)) {
            $update['doc_type_confirmed_at'] = null;
            $update['doc_type_confirmed_by'] = null;
        }

        Database::update('extractions', $update, $id);

        return $changed;
    }

    /**
     * Net, VAT and gross from the line items.
     *
     * The single implementation of this arithmetic. It was written three times
     * — once in the extraction stage, once in the review form, and not at all
     * on the path where a reviewer picks a VAT rate — and the gap in the third
     * was visible: a document resolved by picking a rate reached Clear Books
     * with the totals it had *before* the rate was known, so the submitted bill
     * said "£250.00 net" where it should have said "£300.00".
     *
     * Two rules, and they are the reason this cannot be a one-liner:
     *
     *  - **No sign is applied for any type.** That is a question about
     *    what the Clear Books API expects, and it is answered at submission
     *    from `document_types.amount_sign`.
     *  - **VAT and gross are null unless every line's rate is known** from the
     *    cache. A wrong VAT figure is worse than none: it looks right.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array{net:?float,vat:?float,gross:?float}
     */
    public static function totalsFromLines(array $lines): array
    {
        if ($lines === []) {
            return ['net' => null, 'vat' => null, 'gross' => null];
        }

        $net      = 0.0;
        $vat      = 0.0;
        $vatKnown = true;

        foreach ($lines as $line) {
            if (!is_array($line) || ($line['lineTotal'] ?? null) === null) {
                return ['net' => null, 'vat' => null, 'gross' => null];
            }

            $lineNet = (float) $line['lineTotal'];
            $net    += $lineNet;

            $rateKey = $line['vatRateKey'] ?? null;
            $percent = is_string($rateKey) && $rateKey !== ''
                ? ClearbooksCache::vatPercentage($rateKey)
                : null;

            if ($percent === null) {
                $vatKnown = false;
                continue;
            }

            $vat += $lineNet * ($percent / 100);
        }

        $net = round($net, 2);

        return $vatKnown
            ? ['net' => $net, 'vat' => round($vat, 2), 'gross' => round($net + $vat, 2)]
            : ['net' => $net, 'vat' => null, 'gross' => null];
    }

    /**
     * Recompute the stored totals from the stored lines.
     *
     * Called wherever a line changes outside the review form — picking a VAT
     * rate for a line is an edit to that line, and the totals have to follow it
     * or the document is submitted describing itself wrongly.
     */
    public static function refreshTotals(int $id, ?int $userId = null): void
    {
        $row = self::find($id);

        if ($row === null) {
            return;
        }

        $totals = self::totalsFromLines(array_values(self::decode($row, 'line_items')));

        self::updateFields($id, [
            'net_amount'   => $totals['net'],
            'vat_amount'   => $totals['vat'],
            'gross_amount' => $totals['gross'],
        ], $userId);
    }

    /** Has a person edited this reading since the model produced it? */
    public static function wasEdited(array $row): bool
    {
        return ($row['edited_at'] ?? null) !== null;
    }

    /**
     * Has a person agreed what kind of document this is?
     *
     * Deliberately separate from `wasEdited()`. A reviewer may correct a due
     * date without ever having considered whether the thing in front of them is
     * a credit note or a refund, and treating an edit as agreement to the
     * classification is exactly the shortcut that would put a refund into the
     * ledger the wrong way round.
     *
     * @param array<string,mixed> $row
     */
    public static function typeConfirmed(array $row): bool
    {
        return ($row['doc_type_confirmed_at'] ?? null) !== null;
    }

    /**
     * Record that a person agreed to the classification.
     *
     * Sets the type and the agreement together, in that order, because the
     * agreement is *to* that type — stamping first and setting after would
     * record consent to whatever was there before.
     */
    public static function confirmType(int $id, string $typeKey, ?int $userId = null): void
    {
        self::updateFields($id, ['doc_type' => $typeKey], $userId);

        Database::update('extractions', [
            'doc_type_confirmed_at' => date('Y-m-d H:i:s'),
            'doc_type_confirmed_by' => $userId,
        ], $id);
    }

    /**
     * Write back what the matching stage concluded.
     *
     * The only thing allowed to change an extraction after it is written, and
     * only these two columns: the reading itself is a record of what a model
     * said at a moment, and rewriting that would destroy the thing a
     * re-extraction is meant to be comparable against. What the *pipeline*
     * makes of it — what is still unresolved, and whether a person is needed —
     * is not part of that reading and does move.
     *
     * @param array<int,string> $notes The complete list, not an addition
     */
    public static function setMatchOutcome(int $id, array $notes, bool $needsReview): void
    {
        $notes = array_values(array_unique(array_filter(
            $notes,
            static fn (mixed $note): bool => is_string($note) && trim($note) !== ''
        )));

        Database::update('extractions', [
            'review_notes' => $notes === []
                ? null
                : json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'needs_review' => $needsReview ? 1 : 0,
        ], $id);
    }

    /**
     * The review notes, as a flat list of strings.
     *
     * @param array<string,mixed> $row
     * @return array<int,string>
     */
    public static function reviewNotes(array $row): array
    {
        $notes = [];

        foreach (self::decode($row, 'review_notes') as $note) {
            if (is_string($note) && trim($note) !== '') {
                $notes[] = $note;
            }
        }

        return $notes;
    }
}
