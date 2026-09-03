<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClearbooksInvoice;

/**
 * Which Clear Books record a handwritten Clearbooks Number refers to, and
 * whether the extraction confirms it.
 *
 * Two questions, deliberately separate, because they fail differently. The
 * lookup is arithmetic on a number and either finds one record or does not. The
 * check is a **checksum**: two values that must agree exactly, and if they do
 * not the document goes to a person.
 *
 * Nothing here writes anything or calls Clear Books. It reads the local copy of
 * the purchase documents that `InvoiceSync` keeps (§31) and the extraction the
 * pipeline produced, and returns an opinion. `LinkStage` acts on it.
 */
final class InvoiceMatcher
{
    /** `lookup()` outcomes. */
    public const MATCHED   = 'matched';
    public const NONE      = 'none';
    public const AMBIGUOUS = 'ambiguous';

    /** What one part of the checksum concluded. */
    public const AGREED    = 'agreed';
    public const DISAGREED = 'disagreed';
    public const MISSING   = 'missing';

    /**
     * A shortlist is never offered as an answer.
     *
     * Two records answering to the same number means picking one is a coin
     * toss, and the cost of losing is somebody else's invoice with this
     * document's PDF attached to it. The same rule the supplier matcher holds
     * to for an ambiguous name (§20), for the same reason.
     */
    private const SHORTLIST = 5;

    // --- The lookup ---------------------------------------------------------

    /**
     * Find the Clear Books purchase document a Clearbooks Number refers to.
     *
     * The number is written on the page in red pen and is digits only. Clear
     * Books' own `document_number` is `formattedDocumentNumber` — which may be
     * bare digits, and may equally be `PUR0080421`. So the comparison is made
     * twice: exactly as written first, then on the digits alone with leading
     * zeros dropped from both sides.
     *
     * **That second pass is a normalisation, not a tolerance.** It does not let
     * a near miss through: 80421 and 80422 stay two different numbers, and
     * nothing but an exact reading of the same digits matches. What it settles
     * is that Clear Books writes a prefix and a person writing on a page does
     * not — the same value, spelled two ways.
     *
     * @return array{outcome:string,invoice:?array<string,mixed>,candidates:array<int,array<string,mixed>>,truncated:bool,number:string,digits:string}
     */
    public static function lookup(string $number): array
    {
        $number = trim(ltrim(trim($number), '#'));
        $digits = ltrim(preg_replace('/\D+/', '', $number) ?? '', '0');

        $empty = [
            'outcome'    => self::NONE,
            'invoice'    => null,
            'candidates' => [],
            'truncated'  => false,
            'number'     => $number,
            'digits'     => $digits,
        ];

        if ($number === '') {
            return $empty;
        }

        // One more than the shortlist, so "there are more than these" is a fact
        // rather than a guess — a count that stopped at the limit would report
        // six matches as five.
        $candidates = ClearbooksInvoice::findByDocumentNumber($number, $digits, self::SHORTLIST + 1);

        if ($candidates === []) {
            return $empty;
        }

        if (count($candidates) > 1) {
            return [
                'outcome'    => self::AMBIGUOUS,
                'invoice'    => null,
                'candidates' => array_slice($candidates, 0, self::SHORTLIST),
                'truncated'  => count($candidates) > self::SHORTLIST,
                'number'     => $number,
                'digits'     => $digits,
            ];
        }

        return [
            'outcome'    => self::MATCHED,
            'invoice'    => $candidates[0],
            'candidates' => $candidates,
            'truncated'  => false,
            'number'     => $number,
            'digits'     => $digits,
        ];
    }

    // --- The checksum -------------------------------------------------------

    /**
     * Does the extraction confirm the record the number found?
     *
     * The Clearbooks Number is the key: a hit on it is already a high
     * probability of a match. This is the checksum on that hit, and it is
     * deliberately **exact — there are no tolerances anywhere in it**:
     *
     *  - **date** — `extractions.invoice_date` is the same day as the record's
     *    `document_date`;
     *  - **gross total** — `extractions.gross_amount` is the same figure as the
     *    record's `gross_amount`, to the penny.
     *
     * Both must agree, and then the link happens with nobody looking at it.
     * Anything else — either disagreeing, or either side missing the value
     * altogether — flags the document for manual review.
     *
     * **Why no tolerance**, when the records were typed in by hand and a date
     * or a rounded total genuinely does drift: because a tolerance is a licence
     * to attach a scan to the wrong invoice without anybody noticing. A hit on
     * the number whose date or total does not agree is exactly the shape a
     * misread digit takes, and it costs a person ten seconds to confirm it on
     * the queue. The cost of the other mistake is a document filed against
     * somebody else's invoice, found — if it is found — during an audit.
     *
     * The **absolute value** of the total is compared, and that is not a
     * tolerance either. The sync keeps Clear Books' sign because it is what
     * tells a credit note from a purchase refund; a page never prints one — a
     * credit note says £240.00, not -£240.00 — so comparing signed figures
     * would send every credit note and every refund to manual review for a
     * disagreement that is a convention rather than a difference.
     *
     * @param array<string,mixed> $invoice    The synced Clear Books record
     * @param array<string,mixed> $extraction The document's own extraction
     * @return array{ok:bool,agreed:int,signals:array<int,array<string,mixed>>,summary:string}
     */
    public static function check(array $invoice, array $extraction): array
    {
        $signals = [
            self::checkDate($invoice, $extraction),
            self::checkGross($invoice, $extraction),
        ];

        $agreed = count(array_filter(
            $signals,
            static fn (array $s): bool => $s['outcome'] === self::AGREED
        ));

        return [
            // Every signal, not "most of them". This is the whole gate.
            'ok'      => $agreed === count($signals),
            'agreed'  => $agreed,
            'signals' => $signals,
            'summary' => self::summary($signals),
        ];
    }

    /** @param array<string,mixed> $invoice @param array<string,mixed> $extraction @return array<string,mixed> */
    private static function checkDate(array $invoice, array $extraction): array
    {
        $recorded  = self::day($invoice['document_date'] ?? null);
        $extracted = self::day($extraction['invoice_date'] ?? null);

        if ($recorded === null || $extracted === null) {
            return self::signal(
                'date',
                'Invoice date',
                $recorded === null ? '—' : format_date($recorded),
                $extracted === null ? '—' : format_date($extracted),
                self::MISSING,
                $recorded === null
                    ? 'The Clear Books record has no date, so there is nothing to check against.'
                    : 'Nothing was extracted as the invoice date, so it cannot be confirmed.'
            );
        }

        return self::signal(
            'date',
            'Invoice date',
            format_date($recorded),
            format_date($extracted),
            $recorded === $extracted ? self::AGREED : self::DISAGREED,
            $recorded === $extracted
                ? 'The same day.'
                : 'A different day. Nothing is linked on a date that does not agree exactly.'
        );
    }

    /** @param array<string,mixed> $invoice @param array<string,mixed> $extraction @return array<string,mixed> */
    private static function checkGross(array $invoice, array $extraction): array
    {
        $recorded  = $invoice['gross_amount'] ?? null;
        $extracted = $extraction['gross_amount'] ?? null;

        if (!is_numeric($recorded) || !is_numeric($extracted)) {
            return self::signal(
                'gross',
                'Gross total',
                is_numeric($recorded) ? format_money($recorded) : '—',
                is_numeric($extracted) ? format_money($extracted) : '—',
                self::MISSING,
                is_numeric($recorded)
                    ? 'No gross total was extracted, so it cannot be confirmed.'
                    : 'The Clear Books record has no total. The invoice sync reports how many of these '
                      . 'there are, and why.'
            );
        }

        // To the penny, on the absolute value — the docblock above says why the
        // sign is dropped and why that is not a tolerance. Compared as the
        // strings the column stores, so no float ever decides this.
        $same = self::pence($recorded) === self::pence($extracted);

        return self::signal(
            'gross',
            'Gross total',
            format_money($recorded),
            format_money($extracted),
            $same ? self::AGREED : self::DISAGREED,
            $same
                ? 'The same figure.'
                : 'A different figure. Nothing is linked on a total that does not agree to the penny.'
        );
    }

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

    /** One sentence naming what agreed and what did not, for an event and a badge. */
    private static function summary(array $signals): string
    {
        $by = [self::AGREED => [], self::DISAGREED => [], self::MISSING => []];

        foreach ($signals as $signal) {
            $by[$signal['outcome']][] = strtolower((string) $signal['label']);
        }

        $parts = [];

        foreach ([
            self::DISAGREED => 'the %s does not agree',
            self::MISSING   => 'the %s is missing',
            self::AGREED    => 'the %s agrees',
        ] as $outcome => $wording) {
            if ($by[$outcome] !== []) {
                $parts[] = sprintf($wording, implode(' and the ', $by[$outcome]));
            }
        }

        return $parts === [] ? 'There was nothing to check.' : ucfirst(implode('; ', $parts)) . '.';
    }

    /**
     * A date column as `YYYY-MM-DD`, or null when there is nothing usable.
     *
     * Both sides are stored as DATE columns already, so this is a guard against
     * a value that arrived as a timestamp rather than a conversion anybody is
     * relying on.
     *
     * **Public because `DuplicateMatcher` compares dates too** (§34). The
     * duplicate check on the New Invoice route is the same comparison as the
     * checksum here, asked of a document with no Clearbooks Number to key on;
     * a second spelling of "the same day" would drift from this one and the
     * two screens would disagree about the same pair of records.
     */
    public static function day(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '' || str_starts_with($value, '0000')) {
            return null;
        }

        $timestamp = strtotime(trim($value));

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * An amount as whole pence, unsigned.
     *
     * An integer, so the comparison never goes near float equality: 413.28 and
     * 413.2800000001 are the same invoice, and `===` on floats is how that
     * stops being true on somebody else's machine.
     *
     * Public for the same reason `day()` is: the duplicate check compares gross
     * totals against the same table, and it must reach the same answer.
     */
    public static function pence(mixed $value): int
    {
        return (int) round(abs((float) $value) * 100);
    }

    /**
     * A supplier's own reference, reduced to what two spellings of it share.
     *
     * The counterpart of the digits-and-leading-zeros pass `lookup()` makes on
     * a Clear Books document number, and a **normalisation rather than a
     * tolerance** for exactly the same reason. `INV-2026/0042`, `inv 2026
     * 0042` and `INV20260042` are one reference typed three ways by three
     * people; `0042` and `0043` stay two different references, because nothing
     * here does anything to the characters but fold case and drop separators.
     *
     * Leading zeros are **not** stripped, which is the one place this differs
     * from the document-number pass. Clear Books writes its own numbers to a
     * fixed width and a person writing one on a page does not, so `80421` and
     * `PUR0080421` are the same number; a supplier's reference has no such
     * convention behind it, and `0042` against `42` is two references that
     * happen to look alike.
     *
     * Returns an empty string when nothing usable is left, and an empty string
     * never agrees with anything — see `DuplicateMatcher`.
     */
    public static function reference(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return (string) preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper(trim((string) $value)));
    }
}
