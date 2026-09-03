<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The local copy of the purchase documents that already exist in Clear Books.
 *
 * This is not a cache in the sense `clearbooks_cache` is. That one exists so
 * the pipeline does not have to call Clear Books mid-document; this one exists
 * so a later stage can ask a question the API cannot answer cheaply — *has this
 * invoice already been posted?* Answering it from Clear Books directly would
 * mean a search per document against a service that throttles above five
 * requests a second, and there is no search endpoint to use anyway.
 *
 * One table for bills and credit notes together, because Clear Books' `id` is
 * unique across both. `purchase_type` says which endpoint a row came from,
 * which matters: the two are posted to differently, and a duplicate matched
 * against the wrong kind is a wrong answer rather than a near miss.
 *
 * Nothing here writes to Clear Books. `App\Services\InvoiceSync` fills this
 * table and is the only thing that should; everything else reads.
 */
final class ClearbooksInvoice
{
    /** `purchase_type` values. Clear Books' own spellings, singular. */
    public const BILL        = 'bill';
    public const CREDIT_NOTE = 'creditNote';

    /** The API path segment each type is fetched from and posted to. */
    public const RESOURCES = [
        self::BILL        => 'bills',
        self::CREDIT_NOTE => 'creditNotes',
    ];

    /** @return array<int,string> */
    public static function types(): array
    {
        return [self::BILL, self::CREDIT_NOTE];
    }

    /** How this type reads in a sentence. */
    public static function label(string $purchaseType, bool $plural = false): string
    {
        $labels = [
            self::BILL        => $plural ? 'bills' : 'bill',
            self::CREDIT_NOTE => $plural ? 'credit notes' : 'credit note',
        ];

        return $labels[$purchaseType] ?? $purchaseType;
    }

    // --- Reading ------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function find(string $clearbooksId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM clearbooks_invoices WHERE clearbooks_id = ?',
            [$clearbooksId]
        );
    }

    /**
     * The purchase documents answering to a Clear Books document number.
     *
     * Two comparisons in one statement, because the number this is asked about
     * is read off handwriting and Clear Books' own is `formattedDocumentNumber`
     * — which may be bare digits and may equally be `PUR0080421`:
     *
     *  1. exactly as given, which is the ordinary case and uses the index on
     *     `document_number` directly;
     *  2. the digits alone with leading zeros dropped from both sides, which is
     *     what makes a number somebody wrote as "80421" find `PUR0080421`.
     *
     * The second pass cannot use the index — it is a function of the column —
     * but it is one scan of a table holding a business's purchase history, not
     * a call to somebody else's API, and it runs once per document rather than
     * once per page.
     *
     * More than one row coming back is a real answer, not an error: the caller
     * refuses to guess between them. `$limit` exists so it can say "more than
     * one" without reading a thousand.
     *
     * @param string $number The number as written, punctuation and all
     * @param string $digits The same with non-digits and leading zeros removed
     * @return array<int,array<string,mixed>>
     */
    public static function findByDocumentNumber(string $number, string $digits, int $limit = 6): array
    {
        $number = trim($number);

        if ($number === '') {
            return [];
        }

        $limit = max(1, min(100, $limit));

        $sql = 'SELECT i.*, c.name AS supplier_name
                  FROM clearbooks_invoices i
                  LEFT JOIN clearbooks_cache c
                    ON c.entity_type = ? AND c.remote_id = i.supplier_id
                 WHERE i.document_number = ?';

        $params = [ClearbooksCache::SUPPLIER, $number];

        /*
         * Only when there is something left after stripping. "000" reduces to
         * an empty string, and an empty needle would match every row whose
         * document number contains no digits at all — which is every row where
         * the column is NULL, once COALESCE has done its work.
         */
        if ($digits !== '') {
            $sql        .= " OR TRIM(LEADING '0' FROM REGEXP_REPLACE(COALESCE(i.document_number, ''), '[^0-9]', '')) = ?";
            $params[]    = $digits;
        }

        return Database::select($sql . ' ORDER BY i.document_date DESC, i.id DESC LIMIT ' . $limit, $params);
    }

    /**
     * The purchase documents a New Invoice document could plausibly already be.
     *
     * The other half of the duplicate question, and it is asked completely
     * differently from `findByDocumentNumber()` above. There the page carried a
     * handwritten Clearbooks Number and the only job was resolving it. Here
     * there is no such annotation — that is precisely why the document went
     * down the New Invoice route — so the only things to compare are the values
     * the extraction produced: the supplier's own reference, and the money.
     *
     * **This narrows; it does not decide.** `App\Services\DuplicateMatcher`
     * scores what comes back and settles what is plausible. The two are split
     * because the narrowing has to be something the database can do with an
     * index, and the judgement has to be something a person can read on a
     * screen beside the scan.
     *
     * Two conditions, OR'd, and both are deliberate:
     *
     *  1. **the gross total, to the penny, either sign.** Compared as the two
     *     literal DECIMAL values rather than with `ABS()`, so
     *     `ix_clearbooks_invoices_amount` is actually used — a function of the
     *     column would force a scan of every purchase document the business has
     *     ever had. The sign is doubled up rather than dropped because the sync
     *     keeps Clear Books' own sign (§31) and a page never prints one;
     *  2. **the supplier's reference**, exactly as stored and again with case
     *     and separators folded away. The same two-pass shape as the document
     *     number above and for the same reason: the first spelling uses
     *     `ix_clearbooks_invoices_reference`, the second catches the same
     *     reference written differently by two people.
     *
     * Nothing is narrowed on the supplier or the date **alone**, and that is
     * the point rather than an omission. A business buys from the same supplier
     * every week and receives invoices dated the same day all the time; a
     * candidate set built on either would be most of the table, and a duplicate
     * queue that stops every regular purchase is a queue nobody works. Both are
     * still *scored* — they are how a candidate found on the money is confirmed
     * or dismissed.
     *
     * @param string|null $reference The supplier's own invoice number
     * @param string|null $gross     The extraction's gross total
     * @return array<int,array<string,mixed>>
     */
    public static function findPossibleDuplicates(
        ?string $reference,
        ?string $gross,
        int $limit = 20,
    ): array {
        $conditions = [];
        $params     = [ClearbooksCache::SUPPLIER];

        if ($gross !== null && is_numeric($gross)) {
            $amount = number_format(abs((float) $gross), 2, '.', '');

            $conditions[] = 'i.gross_amount IN (?, ?)';
            $params[]     = $amount;
            $params[]     = '-' . $amount;
        }

        $folded = $reference === null ? '' : preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper(trim($reference)));

        if (is_string($folded) && $folded !== '') {
            $conditions[] = 'i.reference = ?';
            $params[]     = trim((string) $reference);

            $conditions[] = "UPPER(REGEXP_REPLACE(COALESCE(i.reference, ''), '[^A-Za-z0-9]', '')) = ?";
            $params[]     = $folded;
        }

        // Nothing to go on is not a reason to return the table. A document with
        // no reference and no total cannot be judged a duplicate of anything,
        // and saying so costs one statement less than proving it.
        if ($conditions === []) {
            return [];
        }

        $limit = max(1, min(100, $limit));

        return Database::select(
            'SELECT i.*, c.name AS supplier_name
               FROM clearbooks_invoices i
               LEFT JOIN clearbooks_cache c
                 ON c.entity_type = ? AND c.remote_id = i.supplier_id
              WHERE ' . implode(' OR ', $conditions) . '
              ORDER BY i.document_date DESC, i.id DESC
              LIMIT ' . $limit,
            $params
        );
    }

    /**
     * How many of each type are stored, and when the newest row was confirmed.
     *
     * `syncedAt` is the maximum across the table rather than per type: one run
     * fetches both, so two different answers would mean a run that half worked.
     *
     * @return array{bill:int,creditNote:int,total:int,syncedAt:?string}
     */
    public static function summary(): array
    {
        $counts = [self::BILL => 0, self::CREDIT_NOTE => 0];

        foreach (Database::select(
            'SELECT purchase_type, COUNT(*) AS n FROM clearbooks_invoices GROUP BY purchase_type'
        ) as $row) {
            $counts[(string) $row['purchase_type']] = (int) $row['n'];
        }

        $syncedAt = Database::scalar('SELECT MAX(synced_at) FROM clearbooks_invoices');

        return $counts + [
            'total'    => $counts[self::BILL] + $counts[self::CREDIT_NOTE],
            'syncedAt' => $syncedAt === null ? null : (string) $syncedAt,
        ];
    }

    /**
     * The most recently dated documents, for the settings screen.
     *
     * A count on its own does not tell an administrator whether the sync
     * fetched anything sensible. Half a dozen rows they recognise does.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        return Database::select(
            'SELECT i.*, c.name AS supplier_name
               FROM clearbooks_invoices i
               LEFT JOIN clearbooks_cache c
                 ON c.entity_type = ? AND c.remote_id = i.supplier_id
              ORDER BY i.document_date DESC, i.id DESC
              LIMIT ' . $limit,
            [ClearbooksCache::SUPPLIER]
        );
    }

    // --- Writing, from the sync only ----------------------------------------

    /**
     * Store one purchase document as Clear Books returned it.
     *
     * The mapping from their JSON onto these columns lives here rather than in
     * the sync service, because it is knowledge about this table: which of
     * their fields each column holds, and what to do when one is spelled
     * differently from the guess.
     *
     * @param array<string,mixed> $record The record exactly as the API returned it
     * @return array{outcome:string,derivedGross:bool} created | updated | unchanged | skipped
     */
    public static function upsert(string $purchaseType, array $record): array
    {
        $id = self::scalarId($record['id'] ?? null);

        if ($id === null) {
            // A record with no id cannot be upserted, deleted or matched
            // against. The caller counts these and says so, rather than
            // inventing a key for it.
            return ['outcome' => 'skipped', 'derivedGross' => false];
        }

        $gross = self::gross($record);

        $columns = [
            'purchase_type'   => $purchaseType,
            'document_number' => self::text($record['formattedDocumentNumber'] ?? $record['documentNumber'] ?? null, 100),
            'supplier_id'     => self::scalarId($record['supplierId'] ?? $record['supplier']['id'] ?? null),
            'document_date'   => self::date($record['date'] ?? null),
            'due_date'        => self::date($record['dateDue'] ?? $record['dueDate'] ?? null),
            'reference'       => self::text($record['reference'] ?? null, 191),
            'gross_amount'    => $gross['amount'],
            'raw_json'        => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'synced_at'       => date('Y-m-d H:i:s'),
        ];

        $existing = self::find($id);

        if ($existing === null) {
            Database::insert('clearbooks_invoices', $columns + ['clearbooks_id' => $id]);

            return ['outcome' => 'created', 'derivedGross' => $gross['derived']];
        }

        // "Changed" is judged on the record itself rather than on the columns:
        // two records can agree on all seven broken-out fields and still differ
        // in a line item, and a run reporting that as unchanged would be lying
        // about what it did.
        $changed = (string) ($existing['raw_json'] ?? '') !== (string) $columns['raw_json']
            || (string) $existing['purchase_type'] !== $purchaseType;

        Database::update('clearbooks_invoices', $columns, (int) $existing['id']);

        return ['outcome' => $changed ? 'updated' : 'unchanged', 'derivedGross' => $gross['derived']];
    }

    /**
     * Delete everything the latest run did not see.
     *
     * Deleted rather than deactivated, which is the opposite of what
     * `ClearbooksCache` does — the migration says why, and when that has to be
     * revisited. In short: nothing in InvoGrid points at these rows yet, so a
     * document withdrawn in Clear Books can simply go, and a row that lingered
     * would be a false duplicate for ever.
     *
     * An empty list is refused, exactly as the cache refuses one. "Nothing came
     * back at all" is far more likely to be a failed fetch than a business that
     * has deleted every purchase document it ever had, and the caller cannot
     * tell those apart either — so the safe reading is the one taken.
     *
     * @param array<int,string> $seenIds
     * @return int How many were deleted
     */
    public static function deleteMissing(array $seenIds): int
    {
        if ($seenIds === []) {
            return 0;
        }

        $deleted = 0;

        // In batches, because this list is every purchase document the business
        // has: one NOT IN with fifty thousand placeholders is a statement
        // MariaDB will refuse to prepare. Each batch narrows the survivors
        // further, so the result is what a single statement would have given.
        foreach (array_chunk(array_values($seenIds), 1000) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $deleted += Database::run(
                'DELETE FROM clearbooks_invoices WHERE clearbooks_id NOT IN (' . $placeholders . ')',
                $chunk
            )->rowCount();
        }

        return $deleted;
    }

    // --- Reading Clear Books' JSON ------------------------------------------

    /**
     * The gross total of a purchase document.
     *
     * Clear Books' specification is not explicit about what a total is called
     * on a purchase document, and the sample responses on file carry no total
     * at all. So: take a reported one if it is there under any of the names it
     * could plausibly have, and otherwise work it out from the line items —
     * which is the same arithmetic Clear Books does, using VAT percentages
     * already cached from their own API.
     *
     * `derived` says which happened, and the sync reports the count. The first
     * real run against a live business therefore answers the question this
     * guesswork exists because of: if every row is derived, the reported total
     * has a name not on the list below, and adding it is one line here.
     *
     * The **sign is left alone**. A purchase refund is a bill with negative
     * amounts and a credit note is positive at creation; flattening either to
     * an absolute value would throw away the distinction a duplicate check
     * needs most.
     *
     * @param array<string,mixed> $record
     * @return array{amount:?string,derived:bool}
     */
    public static function gross(array $record): array
    {
        foreach (['grossAmount', 'totalAmount', 'total', 'gross', 'amountGross'] as $key) {
            if (isset($record[$key]) && is_numeric($record[$key])) {
                return ['amount' => self::money($record[$key]), 'derived' => false];
            }
        }

        foreach (['totals', 'amounts'] as $group) {
            foreach (['gross', 'total'] as $key) {
                $value = $record[$group][$key] ?? null;

                if (is_numeric($value)) {
                    return ['amount' => self::money($value), 'derived' => false];
                }
            }
        }

        $lines = $record['lineItems'] ?? null;

        if (!is_array($lines) || $lines === []) {
            return ['amount' => null, 'derived' => false];
        }

        $gross = 0.0;

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $net = (float) ($line['unitPrice'] ?? 0) * (float) ($line['quantity'] ?? 1);

            // A stated VAT amount wins over a rate: `vatRateKey: "Manual"`
            // requires one, which is the whole reason the API has the field.
            if (isset($line['vatAmount']) && is_numeric($line['vatAmount'])) {
                $vat = (float) $line['vatAmount'];
            } else {
                $rateKey    = trim((string) ($line['vatRateKey'] ?? ''));
                $percentage = $rateKey === '' ? null : ClearbooksCache::vatPercentage($rateKey);
                $vat        = $percentage === null ? 0.0 : $net * $percentage / 100;
            }

            $gross += $net + $vat;
        }

        return ['amount' => self::money($gross), 'derived' => true];
    }

    /** A DECIMAL(14,2) string, so the value stored is the value compared. */
    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** A remote id as a string, or null when there is nothing usable. */
    private static function scalarId(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function text(mixed $value, int $length): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    /**
     * A date column from whatever the API sent.
     *
     * Clear Books sends `YYYY-MM-DD`, but a field arriving as a full timestamp
     * must not become `0000-00-00`: an unparseable value is stored as NULL,
     * which reads as "not known" rather than as a date in the year zero.
     */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime(trim($value));

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
