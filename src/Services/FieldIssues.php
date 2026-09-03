<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EntityMatch;
use App\Models\Extraction;

/**
 * Which *field* is each thing wrong with?
 *
 * The review screen used to answer that with one card at the top of the page
 * saying "4 things to check", followed by four sentences, followed by forty
 * inputs. A reviewer then read the four sentences, scrolled down, and hunted
 * for the boxes they were about — which is the part of the job the machine
 * should have done. This class does it: it takes the three signals that mean
 * "look at this" and pins each one to the input it is about, so the form can
 * mark that input and nothing else.
 *
 * The three signals, in descending order of how much they can be trusted:
 *
 *  - **An unresolved `entity_matches` row.** Structural, not textual: the row
 *    names its entity type and, for a line item, its line index. There is no
 *    guessing involved and it is always the authoritative version, so a review
 *    note saying the same thing is dropped rather than shown twice.
 *  - **A confidence below 1.0** — on a resolved match (the deterministic name
 *    pass scores 0.9, because it ignores case, punctuation and Ltd/Limited),
 *    or in `extractions.confidence`, which is keyed by column name and is
 *    empty today because no prompt returns it yet. The column exists and is
 *    documented as per-field; reading it here means the day a prompt starts
 *    filling it in, the screen already shows it.
 *  - **A review note**, which is prose and therefore the only part of this
 *    that is a guess. See {@see self::place()} for how far the guess goes.
 *
 * **A note that cannot be placed is not thrown away.** It goes to
 * {@see self::unplaced()} and the screen lists it where the old banner was.
 * Attributing "Header: this document is unusual" to the invoice date would be
 * worse than useless: a reviewer would correct a date that was never wrong and
 * trust the next indicator less.
 *
 * Field keys are the extraction's own column names — `invoice_date`,
 * `gross_amount`, `supplier_name_raw` — plus `custom_<field_key>` for a custom
 * field, and `line.<index>.<column>` for a cell in the line-item table. Using
 * the column names means a template asks for an issue with the same string it
 * uses for the input's `name`, and the two cannot drift apart silently.
 */
final class FieldIssues
{
    /** Something that stands between this document and a submission. */
    public const DANGER = 'danger';

    /** Something worth a look, which may well turn out to be right. */
    public const WARN = 'warn';

    /** Below this, a match is reported as not certain. */
    private const CERTAIN = 1.0;

    /**
     * Below this, a per-field confidence score is reported.
     *
     * 0.8 rather than 1.0 because a score is a different kind of number from a
     * match's: the OCR prompt's own instruction (migration 003) is to drop
     * below 0.8 when a reading is a guess, so that is the line the model was
     * told about.
     */
    private const SCORE_FLOOR = 0.8;

    /**
     * Phrases that name a field, most specific first.
     *
     * Deliberately short and deliberately incomplete. Every entry here is one
     * a note would have to go out of its way to mean something else by —
     * "due date" is the due date. The generic ones that would catch more notes
     * ("date", "amount", "vat" on their own) are **not** here, because the
     * cost of a wrong indicator is a reviewer editing a field that was right,
     * and an unplaced note is still shown.
     *
     * @var array<string,string>
     */
    private const PHRASES = [
        'due date'                    => 'due_date',
        'payment date'                => 'paid_date',
        'date paid'                   => 'paid_date',
        'paid date'                   => 'paid_date',
        'invoice date'                => 'invoice_date',
        'document date'               => 'invoice_date',
        'date of issue'               => 'invoice_date',
        'issue date'                  => 'invoice_date',
        'tax point'                   => 'invoice_date',
        'invoice number'              => 'invoice_number',
        'document number'             => 'invoice_number',
        'their reference'             => 'invoice_number',
        'reference'                   => 'invoice_number',
        'currency'                    => 'currency',
        'vat treatment'               => 'vat_treatment',
        'vat amount'                  => 'vat_amount',
        'vat total'                   => 'vat_amount',
        'gross'                       => 'gross_amount',
        'subtotal'                    => 'net_amount',
        'sub-total'                   => 'net_amount',
        'net'                         => 'net_amount',
        'clear books description'     => 'cb_summary',
        'document type'               => 'doc_type',
        'credit note'                 => 'doc_type',
        'refund'                      => 'doc_type',
        'supplier'                    => 'supplier_name_raw',
        'vendor'                      => 'supplier_name_raw',
        'issuer'                      => 'supplier_name_raw',
        'title'                       => 'document_title',
    ];

    /** @var array<string,array<int,array{tone:string,text:string}>> */
    private array $byField = [];

    /** @var array<int,array{tone:string,text:string}> */
    private array $unplaced = [];

    private function __construct()
    {
    }

    /**
     * Work out what is wrong with which field.
     *
     * @param array<string,mixed>             $extraction
     * @param array<int,array<string,mixed>>  $matches      Every row, resolved or not
     * @param array<int,array<string,mixed>>  $customFields As configured, for placing a custom-field note
     */
    public static function build(array $extraction, array $matches, array $customFields = []): self
    {
        $issues = new self();

        $issues->fromMatches($matches);
        $issues->fromScores($extraction);
        $issues->fromNotes(Extraction::reviewNotes($extraction), $customFields);

        return $issues;
    }

    // --- Asking ------------------------------------------------------------

    /**
     * Everything wrong with one field, worst first.
     *
     * @return array<int,array{tone:string,text:string}>
     */
    public function on(string $key): array
    {
        return $this->byField[$key] ?? [];
    }

    /** Everything wrong with one cell of the line-item table. */
    public function onLine(int $index, string $column): array
    {
        return $this->on('line.' . $index . '.' . $column);
    }

    /** The worst tone against a field, or null when there is nothing wrong with it. */
    public function tone(string $key): ?string
    {
        foreach ($this->on($key) as $issue) {
            if ($issue['tone'] === self::DANGER) {
                return self::DANGER;
            }
        }

        return $this->on($key) === [] ? null : self::WARN;
    }

    /**
     * The notes that name no field.
     *
     * @return array<int,array{tone:string,text:string}>
     */
    public function unplaced(): array
    {
        return $this->unplaced;
    }

    /** How many fields carry something. */
    public function fieldCount(): int
    {
        return count($this->byField);
    }

    /** How many things there are in all, placed and not. */
    public function count(): int
    {
        return array_sum(array_map('count', $this->byField)) + count($this->unplaced);
    }

    /** Is there anything at all? */
    public function any(): bool
    {
        return $this->byField !== [] || $this->unplaced !== [];
    }

    // --- Building ----------------------------------------------------------

    /**
     * The structural signal: what did not resolve, and what resolved unsurely.
     *
     * @param array<int,array<string,mixed>> $matches
     */
    private function fromMatches(array $matches): void
    {
        foreach ($matches as $row) {
            $type  = (string) $row['entity_type'];
            $line  = $row['line_index'] === null ? null : (int) $row['line_index'];
            $key   = $this->matchKey($type, $line);
            $state = (string) $row['status'];

            if (in_array($state, [EntityMatch::UNMATCHED, EntityMatch::REJECTED], true)) {
                $this->add($key, self::DANGER, $row['note'] === null || trim((string) $row['note']) === ''
                    ? 'Nothing on file in Clear Books matched "' . (string) $row['raw_value'] . '".'
                    : (string) $row['note']);

                continue;
            }

            // Resolved, but not certainly. The looser name pass ignores word
            // boundaries, and if a wrong match is ever made this is where it
            // comes from — so it is said on the field rather than only in a
            // table further down the page.
            if ($row['confidence'] !== null && (float) $row['confidence'] < self::CERTAIN) {
                $this->add($key, self::WARN, sprintf(
                    'Matched to "%s" on the name rather than exactly — worth confirming it is the right one.',
                    (string) ($row['matched_name'] ?? $row['matched_id'] ?? 'a Clear Books record')
                ));
            }
        }
    }

    /**
     * `extractions.confidence`: a score per column, where a prompt returns one.
     *
     * Nothing writes this column today — the three extraction prompts return
     * values and review notes, not scores. It is read anyway because the
     * column is defined as exactly this (migration 001, "per-field confidence,
     * keyed the same way as the columns above"), and a screen that ignores a
     * column until somebody remembers to wire it up is a screen that stays
     * wrong for a release.
     *
     * @param array<string,mixed> $extraction
     */
    private function fromScores(array $extraction): void
    {
        foreach (Extraction::decode($extraction, 'confidence') as $column => $score) {
            if (!is_string($column) || !is_numeric($score) || (float) $score >= self::SCORE_FLOOR) {
                continue;
            }

            $this->add((string) $column, self::WARN, sprintf(
                'The reading of this was scored %s out of 1 — below the point the prompt is told to flag.',
                rtrim(rtrim(number_format((float) $score, 2), '0'), '.')
            ));
        }
    }

    /**
     * The prose signal.
     *
     * @param array<int,string>              $notes
     * @param array<int,array<string,mixed>> $customFields
     */
    private function fromNotes(array $notes, array $customFields): void
    {
        foreach ($notes as $note) {
            $key = $this->place($note, $customFields);

            if ($key === null) {
                $this->unplaced[] = ['tone' => self::WARN, 'text' => $note];

                continue;
            }

            // A "Matching:" note repeats an `entity_matches` row that has
            // already been placed on this very field, in the row's own words.
            // Two indicators saying the same thing on one input reads as two
            // separate problems.
            if ($this->fromMatching($note) && $this->on($key) !== []) {
                continue;
            }

            $this->add($key, self::WARN, $note);
        }
    }

    /**
     * Which field is this note about?
     *
     * Four passes, narrowest first. Each of the first three keys off a prefix
     * the *pipeline* writes, so it is a parse rather than a guess; only the
     * last one reads a sentence a model wrote.
     *
     * @param array<int,array<string,mixed>> $customFields
     */
    private function place(string $note, array $customFields): ?string
    {
        // 1. The matching stage: "Matching: Account code on line 2: …".
        if (preg_match(
            '/^Matching:\s*(Supplier|Account code|VAT rate|VAT treatment)(?:\s+on line\s+(\d+))?:/i',
            $note,
            $m
        ) === 1) {
            return $this->matchKey(
                match (strtolower($m[1])) {
                    'supplier'       => EntityMatch::SUPPLIER,
                    'account code'   => EntityMatch::ACCOUNT_CODE,
                    'vat rate'       => EntityMatch::VAT_RATE,
                    default          => EntityMatch::VAT_TREATMENT,
                },
                ($m[2] ?? '') === '' ? null : (int) $m[2] - 1
            );
        }

        // 2. A line the extraction stage raised: "Line 3: no account code…".
        //    The note counts from 1 and the form's rows from 0.
        if (preg_match('/^Line\s+(\d+):\s*(.*)$/i', $note, $m) === 1) {
            return 'line.' . ((int) $m[1] - 1) . '.' . $this->lineColumn($m[2]);
        }

        // 3. The remaining prefixes the pipeline writes itself.
        if (str_starts_with($note, 'Document type:')) {
            return 'doc_type';
        }

        if (str_starts_with($note, 'Line items:')) {
            return 'lines';
        }

        // "Setup: the cached VAT rate list is empty" is about the installation,
        // not about a box on this document, and belongs where it can say so.
        if (str_starts_with($note, 'Setup:')) {
            return null;
        }

        if (str_starts_with($note, 'Custom fields:')) {
            return $this->customField($note, $customFields);
        }

        // 4. Prose. "Supplier: …" is the supplier call's own prefix and settles
        //    it; anything else is read for a phrase that names one field.
        if (str_starts_with($note, 'Supplier:')) {
            return 'supplier_name_raw';
        }

        return $this->phrase($note);
    }

    /**
     * Is this note the prose form of an `entity_matches` row?
     *
     * The same pattern as pass 1 of {@see self::place()}, and deliberately not
     * a test of the "Matching: " prefix alone: that stage also writes notes
     * with no row behind them — a cached supplier id that has gone stale, a
     * credit document waiting to be agreed — and those say something the rows
     * do not.
     */
    private function fromMatching(string $note): bool
    {
        return preg_match(
            '/^Matching:\s*(?:Supplier|Account code|VAT rate|VAT treatment)(?:\s+on line\s+\d+)?:/i',
            $note
        ) === 1;
    }

    /** The key for an entity type, with its line where it has one. */
    private function matchKey(string $entityType, ?int $lineIndex): string
    {
        if ($entityType === EntityMatch::SUPPLIER) {
            return 'supplier_name_raw';
        }

        if ($entityType === EntityMatch::VAT_TREATMENT) {
            return 'vat_treatment';
        }

        $column = $entityType === EntityMatch::ACCOUNT_CODE ? 'account_code' : 'vat_rate';

        // An account code with no line index belongs to no row, so it is put
        // on the table rather than silently onto row 1.
        return $lineIndex === null ? 'lines' : 'line.' . $lineIndex . '.' . $column;
    }

    /** Which cell of a line is this about? */
    private function lineColumn(string $rest): string
    {
        $rest = strtolower($rest);

        return match (true) {
            str_contains($rest, 'account code') => 'account_code',
            str_contains($rest, 'vat rate')     => 'vat_rate',
            str_contains($rest, 'description')  => 'description',
            str_contains($rest, 'line total'),
            str_contains($rest, 'comes to')     => 'total',
            default                             => 'row',
        };
    }

    /**
     * Which custom field does this name?
     *
     * Longest label first, so a note about "Job number" is not claimed by a
     * field called "Job".
     *
     * @param array<int,array<string,mixed>> $customFields
     */
    private function customField(string $note, array $customFields): ?string
    {
        $labels = [];

        foreach ($customFields as $field) {
            $label = trim((string) ($field['label'] ?? ''));

            if ($label !== '') {
                $labels[$label] = 'custom_' . (string) $field['field_key'];
            }
        }

        uksort($labels, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($labels as $label => $key) {
            if (stripos($note, $label) !== false) {
                return $key;
            }
        }

        return null;
    }

    /** The last resort: a phrase in the sentence that names exactly one field. */
    private function phrase(string $note): ?string
    {
        $haystack = strtolower($note);

        foreach (self::PHRASES as $phrase => $key) {
            if (str_contains($haystack, $phrase)) {
                return $key;
            }
        }

        return null;
    }

    /** Record one issue, keeping the worst tone first and never repeating text. */
    private function add(string $key, string $tone, string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        foreach ($this->byField[$key] ?? [] as $existing) {
            if ($existing['text'] === $text) {
                return;
            }
        }

        $this->byField[$key][] = ['tone' => $tone, 'text' => $text];

        // Danger first, so a template that shows only the first one shows the
        // one that stops the document being submitted.
        usort(
            $this->byField[$key],
            static fn (array $a, array $b): int => ($b['tone'] === self::DANGER ? 1 : 0)
                <=> ($a['tone'] === self::DANGER ? 1 : 0)
        );
    }
}
