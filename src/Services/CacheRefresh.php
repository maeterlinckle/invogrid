<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClearbooksCache;
use RuntimeException;

/**
 * Refill the local copy of the Clear Books reference lists.
 *
 * Everything the pipeline matches against is read from `clearbooks_cache`, not
 * from Clear Books: three extraction calls per document, each needing the whole
 * supplier list injected into its prompt, would mean thousands of API calls
 * against a service that starts throttling above five a second — and would make
 * every document wait on somebody else's uptime.
 *
 * Run from cron and from the "Refresh now" button, which are the same code
 * path. What it fetches:
 *
 *  - **suppliers**, minus the archived ones. Archiving is how a supplier is
 *    retired in practice, so an archived one is treated exactly as a deleted
 *    one is: the cached row is deactivated rather than dropped, so a document
 *    that already matched it still resolves, and the correspondent sync sees
 *    it;
 *  - **account codes** — narrowed to the ones marked `purchases`. A sales-only
 *    code offered to the extraction prompt is a wrong answer waiting to be
 *    picked;
 *  - **VAT treatments** for purchases;
 *  - **VAT rates** for purchases, asked for once per treatment, because which
 *    rates are legal depends on the treatment.
 *
 * There is deliberately no projects refresh: Clear Books' v1 API has no
 * projects endpoint and no project scope. Project codes are set by hand in the
 * Clear Books web interface, which is why a submitted document offers a link
 * straight to it.
 */
final class CacheRefresh
{
    /**
     * @param callable(string):void|null $log
     * @return array<string,array{fetched:int,created:int,updated:int,deactivated:int}>
     */
    public static function run(?callable $log = null): array
    {
        $say = $log ?? static function (string $line): void {
        };

        if (!ClearBooksClient::isConfigured()) {
            throw new RuntimeException('Clear Books is not configured. Add the application credentials in Settings.');
        }

        if (!ClearBooksClient::isConnected()) {
            throw new ClearBooksAuthException(
                'InvoGrid is not authorised with Clear Books. Connect it from the Clear Books settings screen.'
            );
        }

        $client = new ClearBooksClient();
        $tally  = [];

        $tally[ClearbooksCache::SUPPLIER]      = self::suppliers($client, $say);
        $tally[ClearbooksCache::ACCOUNT_CODE]  = self::accountCodes($client, $say);
        $treatments                            = self::vatTreatments($client, $say);
        $tally[ClearbooksCache::VAT_TREATMENT] = $treatments['tally'];
        $tally[ClearbooksCache::VAT_RATE]      = self::vatRates($client, $treatments['keys'], $say);

        // Only when the lists actually moved. This runs twice a day; recording
        // "nothing changed" every time would bury the entry that says a
        // supplier disappeared under a fortnight of noise.
        $changed = 0;
        foreach ($tally as $counts) {
            $changed += $counts['created'] + $counts['updated'] + $counts['deactivated'];
        }

        if ($changed > 0) {
            AuditLog::record('clearbooks.cache_refresh', null, self::describe($tally));
        }

        return $tally;
    }

    /**
     * @param callable(string):void $say
     * @return array{fetched:int,created:int,updated:int,deactivated:int}
     */
    private static function suppliers(ClearBooksClient $client, callable $say): array
    {
        $rows  = $client->suppliers();
        $tally = self::emptyTally(count($rows));
        $seen  = [];

        foreach ($rows as $row) {
            $id   = self::id($row);
            $name = trim((string) ($row['name'] ?? ''));

            // A supplier with no name cannot be matched against and cannot be
            // shown; skipping it is better than caching a blank.
            if ($id === null || $name === '') {
                continue;
            }

            // Archiving is how a supplier is normally retired in Clear Books —
            // far more common than a hard delete. An archived one is left out
            // of $seen and so is deactivated below by exactly the same path as
            // one that has gone altogether: the cached row and its Paperless
            // link survive, and the correspondent sync gets its signal either
            // way. Nothing else has to know which of the two happened.
            if (($row['archived'] ?? false) === true) {
                continue;
            }

            $seen[] = $id;
            self::count($tally, ClearbooksCache::upsert(ClearbooksCache::SUPPLIER, $id, $name, $row));
        }

        $tally['deactivated'] = ClearbooksCache::deactivateMissing(ClearbooksCache::SUPPLIER, $seen);
        $say('  suppliers: ' . self::line($tally));

        return $tally;
    }

    /**
     * @param callable(string):void $say
     * @return array{fetched:int,created:int,updated:int,deactivated:int}
     */
    private static function accountCodes(ClearBooksClient $client, callable $say): array
    {
        $rows  = $client->accountCodes();
        $tally = self::emptyTally(count($rows));
        $seen  = [];

        foreach ($rows as $row) {
            // The list covers the whole chart of accounts. Only the purchase
            // side is any use to a bill, and offering the rest to the
            // extraction prompt is handing it wrong answers to choose from.
            if (($row['purchases'] ?? false) !== true) {
                continue;
            }

            $id   = self::id($row);
            $name = trim((string) ($row['name'] ?? ''));

            if ($id === null || $name === '') {
                continue;
            }

            $seen[] = $id;
            self::count($tally, ClearbooksCache::upsert(ClearbooksCache::ACCOUNT_CODE, $id, $name, $row));
        }

        $tally['deactivated'] = ClearbooksCache::deactivateMissing(ClearbooksCache::ACCOUNT_CODE, $seen);
        $say('  account codes (purchases only): ' . self::line($tally));

        return $tally;
    }

    /**
     * @param callable(string):void $say
     * @return array{tally:array{fetched:int,created:int,updated:int,deactivated:int},keys:array<int,string>}
     */
    private static function vatTreatments(ClearBooksClient $client, callable $say): array
    {
        $rows  = $client->vatTreatments('purchases');
        $tally = self::emptyTally(count($rows));
        $seen  = [];

        foreach ($rows as $row) {
            // A treatment is identified by a string key, not a numeric id.
            $key  = trim((string) ($row['key'] ?? ''));
            $name = trim((string) ($row['name'] ?? '')) ?: $key;

            if ($key === '') {
                continue;
            }

            $seen[] = $key;
            self::count($tally, ClearbooksCache::upsert(ClearbooksCache::VAT_TREATMENT, $key, $name, $row));
        }

        $tally['deactivated'] = ClearbooksCache::deactivateMissing(ClearbooksCache::VAT_TREATMENT, $seen);
        $say('  VAT treatments: ' . self::line($tally));

        return ['tally' => $tally, 'keys' => $seen];
    }

    /**
     * @param array<int,string> $treatmentKeys
     * @param callable(string):void $say
     * @return array{fetched:int,created:int,updated:int,deactivated:int}
     */
    private static function vatRates(ClearBooksClient $client, array $treatmentKeys, callable $say): array
    {
        $tally = self::emptyTally(0);
        $seen  = [];

        // Collected across treatments before anything is written, because the
        // same rate key legitimately appears under more than one treatment and
        // the cache holds one row per key. Which treatments a rate belongs to
        // is kept on the row, since a submission has to send a treatment and a
        // rate that agree with each other.
        $rates = [];

        // An empty treatment list means the treatments call found nothing, so
        // asking per treatment would ask nothing at all. Fall back to the
        // unfiltered list rather than silently caching no rates — with no rates
        // cached, no document can have its VAT worked out.
        $queries = $treatmentKeys === [] ? [null] : $treatmentKeys;

        foreach ($queries as $treatment) {
            foreach ($client->vatRates('purchases', $treatment) as $row) {
                $key = trim((string) ($row['key'] ?? ''));

                if ($key === '') {
                    continue;
                }

                $tally['fetched']++;

                if (!isset($rates[$key])) {
                    $rates[$key] = $row + ['treatments' => []];
                }

                if ($treatment !== null && !in_array($treatment, $rates[$key]['treatments'], true)) {
                    $rates[$key]['treatments'][] = $treatment;
                }
            }
        }

        foreach ($rates as $key => $row) {
            $seen[] = (string) $key;
            $name   = trim((string) ($row['name'] ?? '')) ?: (string) $key;

            self::count($tally, ClearbooksCache::upsert(ClearbooksCache::VAT_RATE, (string) $key, $name, $row));
        }

        $tally['deactivated'] = ClearbooksCache::deactivateMissing(ClearbooksCache::VAT_RATE, $seen);
        $say('  VAT rates: ' . self::line($tally));

        return $tally;
    }

    /**
     * The remote id as a string.
     *
     * Clear Books ids are integers in the API and `VARCHAR(64)` here, because
     * they are Clear Books' identifiers rather than this database's. Everything
     * downstream compares them as strings, so the conversion happens once, on
     * the way in.
     */
    private static function id(array $row): ?string
    {
        $id = $row['id'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /** @return array{fetched:int,created:int,updated:int,deactivated:int} */
    private static function emptyTally(int $fetched): array
    {
        return ['fetched' => $fetched, 'created' => 0, 'updated' => 0, 'deactivated' => 0];
    }

    /** @param array{fetched:int,created:int,updated:int,deactivated:int} $tally */
    private static function count(array &$tally, string $outcome): void
    {
        if ($outcome === 'created') {
            $tally['created']++;
        } elseif ($outcome === 'updated') {
            $tally['updated']++;
        }
    }

    /** @param array{fetched:int,created:int,updated:int,deactivated:int} $tally */
    private static function line(array $tally): string
    {
        return sprintf(
            '%d fetched, %d new, %d changed, %d gone',
            $tally['fetched'],
            $tally['created'],
            $tally['updated'],
            $tally['deactivated']
        );
    }

    /** @param array<string,array{fetched:int,created:int,updated:int,deactivated:int}> $tally */
    private static function describe(array $tally): string
    {
        $parts = [];

        foreach ($tally as $entityType => $counts) {
            $parts[] = str_replace('_', ' ', $entityType) . ' ' . self::line($counts);
        }

        return implode('; ', $parts);
    }
}
