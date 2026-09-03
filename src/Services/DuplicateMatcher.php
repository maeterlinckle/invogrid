<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClearbooksInvoice;

/**
 * Is this new invoice one Clear Books already holds?
 *
 * The New Invoice route's counterpart to `InvoiceMatcher`, and the difference
 * between the two is the whole reason this class exists. `InvoiceMatcher` is
 * asked about a document somebody wrote a Clearbooks Number on: there is a key,
 * the lookup either finds one record or it does not, and the date and total are
 * a **checksum** on a hit that is already almost certain. Nothing here has a
 * key. A document is on this route precisely because nobody annotated it — and
 * it may still be an invoice that was entered in Clear Books by hand months
 * ago, or scanned once before under a different image.
 *
 * So the question is a different shape, and it is answered differently:
 *
 *  - **the comparisons are identical.** The same day, the same pence with the
 *    sign dropped — `InvoiceMatcher::day()` and `InvoiceMatcher::pence()`
 *    themselves, not a second spelling of them. There are no tolerances here
 *    either, and a value missing on either side is not an agreement;
 *  - **no single one of them decides.** Four signals are compared and counted,
 *    because a document with no key has to be recognised by the shape of what
 *    it says rather than by a number that points at an answer;
 *  - **the outcome is never an action.** `InvoiceMatcher` clears a document to
 *    have its PDF attached with nobody looking. This one only ever says "a
 *    person should look at these two side by side". Deleting a document and
 *    posting a bill are both irreversible, and no count of agreeing fields is
 *    worth doing either unasked.
 *
 * Nothing here writes anything or calls Clear Books. It reads the local copy of
 * the purchase documents `InvoiceSync` keeps (§31) and the extraction the
 * pipeline produced. `MatchStage` acts on the answer, and
 * `DuplicateController` shows it.
 */
final class DuplicateMatcher
{
    /** What one signal concluded. The same three words the checksum uses. */
    public const AGREED    = InvoiceMatcher::AGREED;
    public const DISAGREED = InvoiceMatcher::DISAGREED;
    public const MISSING   = InvoiceMatcher::MISSING;

    /**
     * How many candidates are ever offered to a person.
     *
     * Larger than `InvoiceMatcher::SHORTLIST`, and for the opposite reason.
     * There, more than one answer meant refusing to guess between them: the
     * shortlist was an explanation for a refusal. Here every candidate is
     * shown to somebody who will judge them, and a monthly invoice for the
     * same amount genuinely does produce a handful of near neighbours worth
     * putting in front of them.
     */
    private const SHORTLIST = 8;

    /**
     * Every Clear Books record this document might already be, worst excluded.
     *
     * Ordered by how much agrees, so the record a person should look at first
     * is first. A document that plausibly duplicates two records is a real
     * shape — a supplier who invoices the same amount monthly, and an
     * extraction that misread the date — and the queue shows all of them
     * rather than picking.
     *
     * @param array<string,mixed> $document   The document row, for its matched supplier
     * @param array<string,mixed> $extraction The document's own extraction
     * @return array{
     *     candidates:array<int,array<string,mixed>>,
     *     plausible:array<int,array<string,mixed>>,
     *     compared:int,
     *     summary:string
     * }
     */
    public static function against(array $document, array $extraction): array
    {
        $rows = ClearbooksInvoice::findPossibleDuplicates(
            self::string($extraction['invoice_number'] ?? null),
            self::string($extraction['gross_amount'] ?? null),
            self::SHORTLIST * 3
        );

        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = self::score($document, $extraction, $row);
        }

        /*
         * Most agreement first, and the tie broken on the date rather than left
         * to whatever order the database returned. Two candidates agreeing on
         * the same three things is common — the same supplier invoicing the
         * same amount in two months — and the nearer date is the one worth
         * reading first.
         */
        usort($candidates, static function (array $a, array $b): int {
            return [$b['agreed'], (string) ($b['invoice']['document_date'] ?? '')]
               <=> [$a['agreed'], (string) ($a['invoice']['document_date'] ?? '')];
        });

        $candidates = array_slice($candidates, 0, self::SHORTLIST);

        $plausible = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => $c['plausible']
        ));

        return [
            'candidates' => $candidates,
            'plausible'  => $plausible,
            'compared'   => count($rows),
            'summary'    => self::summary($plausible),
        ];
    }

    /**
     * Does the extraction have enough on it to be asked this question at all?
     *
     * The candidate set is narrowed on the supplier's reference and the gross
     * total, so a document carrying neither can never produce one. Asked
     * explicitly rather than left to fall out of an empty result, because the
     * two are different facts and the queue says so: "nothing in Clear Books
     * looks like this" and "there was nothing to compare" are not the same
     * reassurance.
     *
     * @param array<string,mixed> $extraction
     */
    public static function comparable(array $extraction): bool
    {
        return InvoiceMatcher::reference($extraction['invoice_number'] ?? null) !== ''
            || is_numeric($extraction['gross_amount'] ?? null);
    }

    // --- The judgement ------------------------------------------------------

    /**
     * Four signals, and the rule that turns them into "somebody should look".
     *
     * **The rule: at least two signals agree, and at least one of the two is
     * the gross total or the supplier's reference.**
     *
     * Both halves are load-bearing.
     *
     * *Two, rather than one*, because no single signal is evidence on its own.
     * A business pays £49.99 to the same supplier every month; a reference of
     * "1" or "INV001" belongs to half the small traders in the country. One
     * agreement is a coincidence that would stop something every day, and a
     * queue that cries wolf is a queue that gets cleared without being read —
     * which is a worse outcome than not having built it.
     *
     * *One of them a money figure or a reference*, because the other two agree
     * by themselves all the time. The supplier agrees for every invoice from a
     * regular supplier; the date agrees for everything that arrives in the same
     * post. Supplier-and-date together would stop a weekly delivery note every
     * single week and would not once be right.
     *
     * A genuine duplicate normally agrees on **all four** — it is literally the
     * same invoice — so two is already generous, and the slack is deliberately
     * spent in the direction of catching the case where the extraction misread
     * one field. The cost of a false positive is ten seconds on a comparison
     * screen. The cost of a false negative is the same purchase in somebody's
     * accounts twice.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $extraction
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private static function score(array $document, array $extraction, array $invoice): array
    {
        $signals = [
            'supplier'  => self::checkSupplier($document, $invoice),
            'reference' => self::checkReference($extraction, $invoice),
            'date'      => self::checkDate($extraction, $invoice),
            'gross'     => self::checkGross($extraction, $invoice),
        ];

        $agreed = array_keys(array_filter(
            $signals,
            static fn (array $s): bool => $s['outcome'] === self::AGREED
        ));

        $anchored = in_array('gross', $agreed, true) || in_array('reference', $agreed, true);

        return [
            'invoice'   => $invoice,
            'signals'   => array_values($signals),
            'agreed'    => count($agreed),
            'anchors'   => $agreed,
            'plausible' => count($agreed) >= 2 && $anchored,
            'summary'   => self::describe($signals),
        ];
    }

    /**
     * The supplier: InvoGrid's resolved id against Clear Books' own.
     *
     * `documents.matched_supplier_id` rather than the name off the letterhead,
     * which is why this check runs at the *end* of the matching stage and not
     * after extraction: until the supplier has been resolved against the cached
     * list there is nothing here but two strings that were typed by different
     * people. An unresolved supplier is `missing`, not `disagreed` — it says
     * nothing either way, and counting it as a disagreement would quietly make
     * every unmatched supplier's document un-flaggable.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private static function checkSupplier(array $document, array $invoice): array
    {
        $ours   = self::string($document['matched_supplier_id'] ?? null);
        $theirs = self::string($invoice['supplier_id'] ?? null);

        $name = static fn (?string $id, ?string $label): string => $label ?? ($id ?? '—');

        if ($ours === null || $theirs === null) {
            return self::signal(
                'supplier',
                'Supplier',
                $name($theirs, self::string($invoice['supplier_name'] ?? null)),
                $name($ours, self::string($document['supplier_raw'] ?? null)),
                self::MISSING,
                $ours === null
                    ? 'This document has no resolved Clear Books supplier, so the two cannot be compared.'
                    : 'The Clear Books record names no supplier.'
            );
        }

        return self::signal(
            'supplier',
            'Supplier',
            $name($theirs, self::string($invoice['supplier_name'] ?? null)),
            $name($ours, self::string($document['supplier_raw'] ?? null)),
            $ours === $theirs ? self::AGREED : self::DISAGREED,
            $ours === $theirs
                ? 'The same Clear Books supplier.'
                : 'A different supplier, which on its own is close to conclusive.'
        );
    }

    /**
     * The supplier's own reference — their invoice number, on both sides.
     *
     * The single strongest signal there is, because it is the one value the
     * supplier chose rather than anything either system derived. Compared
     * through `InvoiceMatcher::reference()`, which folds case and separators
     * and nothing else.
     *
     * @param array<string,mixed> $extraction
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private static function checkReference(array $extraction, array $invoice): array
    {
        $ours   = InvoiceMatcher::reference($extraction['invoice_number'] ?? null);
        $theirs = InvoiceMatcher::reference($invoice['reference'] ?? null);

        $shown = static fn (mixed $raw): string => self::string($raw) ?? '—';

        if ($ours === '' || $theirs === '') {
            return self::signal(
                'reference',
                'Their reference',
                $shown($invoice['reference'] ?? null),
                $shown($extraction['invoice_number'] ?? null),
                self::MISSING,
                $ours === ''
                    ? 'No supplier reference was read off this document.'
                    : 'The Clear Books record carries no reference, which is common — it is an '
                      . 'optional field and is often left empty when a bill is keyed in.'
            );
        }

        return self::signal(
            'reference',
            'Their reference',
            $shown($invoice['reference'] ?? null),
            $shown($extraction['invoice_number'] ?? null),
            $ours === $theirs ? self::AGREED : self::DISAGREED,
            $ours === $theirs
                ? 'The same reference, allowing for case and punctuation.'
                : 'Two different references.'
        );
    }

    /**
     * The invoice date, the same day, no tolerance — `InvoiceMatcher::day()`.
     *
     * @param array<string,mixed> $extraction
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private static function checkDate(array $extraction, array $invoice): array
    {
        $theirs = InvoiceMatcher::day($invoice['document_date'] ?? null);
        $ours   = InvoiceMatcher::day($extraction['invoice_date'] ?? null);

        if ($theirs === null || $ours === null) {
            return self::signal(
                'date',
                'Invoice date',
                $theirs === null ? '—' : format_date($theirs),
                $ours === null ? '—' : format_date($ours),
                self::MISSING,
                $ours === null
                    ? 'Nothing was extracted as the invoice date.'
                    : 'The Clear Books record has no date.'
            );
        }

        return self::signal(
            'date',
            'Invoice date',
            format_date($theirs),
            format_date($ours),
            $theirs === $ours ? self::AGREED : self::DISAGREED,
            $theirs === $ours
                ? 'The same day.'
                : 'A different day — though a bill keyed in later is often dated the day it was entered.'
        );
    }

    /**
     * The gross total, to the penny and unsigned — `InvoiceMatcher::pence()`.
     *
     * The sign is dropped for the reason §33 gives: the sync keeps Clear Books'
     * own sign because it distinguishes a credit note from a purchase refund,
     * and a page never prints one.
     *
     * @param array<string,mixed> $extraction
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private static function checkGross(array $extraction, array $invoice): array
    {
        $theirs = $invoice['gross_amount'] ?? null;
        $ours   = $extraction['gross_amount'] ?? null;

        if (!is_numeric($theirs) || !is_numeric($ours)) {
            return self::signal(
                'gross',
                'Gross total',
                is_numeric($theirs) ? format_money($theirs) : '—',
                is_numeric($ours) ? format_money($ours) : '—',
                self::MISSING,
                is_numeric($ours)
                    ? 'The Clear Books record has no total.'
                    : 'No gross total was extracted from this document.'
            );
        }

        $same = InvoiceMatcher::pence($theirs) === InvoiceMatcher::pence($ours);

        return self::signal(
            'gross',
            'Gross total',
            format_money($theirs),
            format_money($ours),
            $same ? self::AGREED : self::DISAGREED,
            $same
                ? 'The same figure, to the penny.'
                : 'A different figure.'
        );
    }

    // --- Wording ------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function signal(
        string $key,
        string $label,
        string $recorded,
        string $extracted,
        string $outcome,
        string $note,
    ): array {
        return [
            'key'       => $key,
            'label'     => $label,
            'recorded'  => $recorded,
            'extracted' => $extracted,
            'outcome'   => $outcome,
            'note'      => $note,
        ];
    }

    /**
     * What agreed on one candidate, as a phrase.
     *
     * The wording is its own map rather than the column labels lower-cased,
     * because a label is a table heading and a heading does not read as a
     * noun in a sentence: "Their reference" is right above a column and gives
     * "the their reference agrees" in prose.
     *
     * @param array<string,array<string,mixed>> $signals
     */
    private static function describe(array $signals): string
    {
        $wording = [
            'supplier'  => 'supplier',
            'reference' => "supplier's reference",
            'date'      => 'invoice date',
            'gross'     => 'gross total',
        ];

        $agreed = [];

        foreach ($signals as $key => $signal) {
            if ($signal['outcome'] === self::AGREED) {
                $agreed[] = $wording[$key] ?? strtolower((string) $signal['label']);
            }
        }

        if ($agreed === []) {
            return 'Nothing about this record agrees with the document.';
        }

        // Named individually up to three, because which ones agreed is the
        // useful part. All four is the shape a genuine duplicate has, and
        // listing them reads as a paragraph rather than a verdict.
        if (count($agreed) === count($signals)) {
            return 'Every value compared agrees.';
        }

        return 'The ' . implode(' and the ', $agreed) . ' ' . (count($agreed) === 1 ? 'agrees' : 'agree') . '.';
    }

    /**
     * One sentence naming why a document stopped, for the event and the queue.
     *
     * Names the records rather than counting them. "This may already be in
     * Clear Books" sends somebody off to work out which one; naming PUR0080421
     * lets them settle it without opening anything.
     *
     * @param array<int,array<string,mixed>> $plausible
     */
    private static function summary(array $plausible): string
    {
        if ($plausible === []) {
            return 'Nothing in Clear Books looks like this document.';
        }

        // The candidate's own sentence goes inside the brackets without its full
        // stop, which would otherwise leave "… agree.)" mid-sentence.
        $named = array_map(
            static fn (array $c): string => (string) (
                $c['invoice']['document_number'] ?? $c['invoice']['clearbooks_id']
            ) . ' (' . lcfirst(rtrim((string) $c['summary'], '.')) . ')',
            $plausible
        );

        return count($plausible) === 1
            ? 'This may already be in Clear Books as ' . $named[0]
            : sprintf(
                'This may already be in Clear Books as one of %d purchase documents: %s',
                count($plausible),
                implode('; ', $named)
            );
    }

    private static function string(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
