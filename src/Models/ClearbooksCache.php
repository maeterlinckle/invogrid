<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Normaliser;

/**
 * The local copy of the Clear Books lists the pipeline matches against.
 *
 * Read from here rather than called for mid-pipeline: three extraction calls
 * per document, each needing the supplier list, would hammer Clear Books and
 * make every document wait on it. Prompt 5 fills and refreshes it; this is the
 * read side.
 */
final class ClearbooksCache
{
    public const SUPPLIER      = 'supplier';
    public const ACCOUNT_CODE  = 'account_code';
    public const VAT_RATE      = 'vat_rate';
    public const VAT_TREATMENT = 'vat_treatment';
    public const PROJECT       = 'project';

    /** @return array<int,array<string,mixed>> */
    public static function all(string $entityType, bool $activeOnly = true): array
    {
        $sql    = 'SELECT * FROM clearbooks_cache WHERE entity_type = ?';
        $params = [$entityType];

        if ($activeOnly) {
            $sql .= ' AND active = 1';
        }

        return Database::select($sql . ' ORDER BY name', $params);
    }

    public static function count(string $entityType): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM clearbooks_cache WHERE entity_type = ? AND active = 1',
            [$entityType]
        );
    }

    /** When this list was last refreshed, or null if it never has been. */
    public static function cachedAt(string $entityType): ?string
    {
        $value = Database::scalar(
            'SELECT MAX(cached_at) FROM clearbooks_cache WHERE entity_type = ?',
            [$entityType]
        );

        return $value === null ? null : (string) $value;
    }

    /** Is this entity still on file and current? */
    public static function isActive(string $entityType, string $remoteId): bool
    {
        $row = self::find($entityType, $remoteId);

        return $row !== null && (int) $row['active'] === 1;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $entityType, string $remoteId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM clearbooks_cache WHERE entity_type = ? AND remote_id = ?',
            [$entityType, $remoteId]
        );
    }

    /**
     * A list shaped for injection into a prompt.
     *
     * Only the fields a model needs to match against, not the whole cached
     * record: the raw Clear Books JSON carries addresses, contact details and
     * timestamps that cost tokens on every document and help with nothing.
     *
     * `raw_json` is merged in for the fields that matter per entity type — a VAT
     * rate is useless without its percentage — but the shape stays flat and
     * small.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forPrompt(string $entityType): array
    {
        $rows = [];

        foreach (self::all($entityType) as $row) {
            $raw   = is_string($row['raw_json'] ?? null) ? json_decode((string) $row['raw_json'], true) : null;
            $raw   = is_array($raw) ? $raw : [];
            $entry = ['id' => (string) $row['remote_id'], 'name' => (string) $row['name']];

            switch ($entityType) {
                case self::SUPPLIER:
                    // Both ids, because the supplier prompt returns both: the
                    // Clear Books one to submit against, the Paperless one to
                    // set the document's correspondent.
                    $entry['cbId']       = (string) $row['remote_id'];
                    $entry['paperlessId'] = $row['paperless_correspondent_id'] === null
                        ? null
                        : (int) $row['paperless_correspondent_id'];

                    foreach (['vatNumber', 'companyNumber', 'tradingNames'] as $key) {
                        if (isset($raw[$key]) && $raw[$key] !== '' && $raw[$key] !== []) {
                            $entry[$key] = $raw[$key];
                        }
                    }
                    break;

                case self::VAT_RATE:
                    // A rate without its percentage cannot be checked against a
                    // line's own arithmetic.
                    foreach (['rate', 'percentage'] as $key) {
                        if (isset($raw[$key])) {
                            $entry['rate'] = $raw[$key];
                            break;
                        }
                    }
                    break;

                case self::ACCOUNT_CODE:
                    if (isset($raw['description']) && $raw['description'] !== '') {
                        $entry['description'] = $raw['description'];
                    }
                    break;
            }

            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * The VAT percentage for a rate key, if it is known.
     *
     * Used to work out a document's VAT and gross from its line totals. Returns
     * null when the rate is not cached or carries no percentage — in which case
     * the totals are left unset rather than guessed at.
     */
    public static function vatPercentage(string $remoteId): ?float
    {
        $row = self::find(self::VAT_RATE, $remoteId);

        if ($row === null || !is_string($row['raw_json'] ?? null)) {
            return null;
        }

        $raw = json_decode((string) $row['raw_json'], true);

        if (!is_array($raw)) {
            return null;
        }

        foreach (['rate', 'percentage'] as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                return (float) $raw[$key];
            }
        }

        return null;
    }

    // --- The write side, used by the refresh and the correspondent sync -----

    /**
     * Store one entity, inserting or updating as needed.
     *
     * `normalised_name` is written here rather than by a caller, so the value
     * the deterministic fallback looks up by can never drift from the algorithm
     * that produces it.
     *
     * Note that this **reactivates** a row it finds deactivated: a supplier
     * unarchived in Clear Books comes back with its Paperless link intact,
     * rather than as a new record whose correspondent has to be found again.
     *
     * @param array<string,mixed> $raw The record as Clear Books returned it
     * @return string 'created' | 'updated' | 'unchanged'
     */
    public static function upsert(string $entityType, string $remoteId, string $name, array $raw = []): string
    {
        $json     = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $existing = self::find($entityType, $remoteId);

        if ($existing === null) {
            Database::insert('clearbooks_cache', [
                'entity_type'     => $entityType,
                'remote_id'       => $remoteId,
                'name'            => mb_substr($name, 0, 255),
                'normalised_name' => mb_substr(Normaliser::key($name), 0, 255),
                'raw_json'        => $json,
                'active'          => 1,
            ]);

            return 'created';
        }

        $changed = (string) $existing['name'] !== $name
            || (int) $existing['active'] !== 1
            || (string) ($existing['raw_json'] ?? '') !== (string) $json;

        Database::update('clearbooks_cache', [
            'name'            => mb_substr($name, 0, 255),
            'normalised_name' => mb_substr(Normaliser::key($name), 0, 255),
            'raw_json'        => $json,
            'active'          => 1,
            'cached_at'       => date('Y-m-d H:i:s'),
        ], (int) $existing['id']);

        return $changed ? 'updated' : 'unchanged';
    }

    /**
     * Deactivate everything of this type that the latest refresh did not see.
     *
     * Deactivated rather than deleted, for two reasons. A document already
     * matched against a supplier keeps a resolvable record of what it matched;
     * and `paperless_correspondent_id` survives, which is the only link back to
     * the correspondent that now has to be dealt with. The correspondent sync
     * reads exactly these rows.
     *
     * @param array<int,string> $seenRemoteIds
     * @return int How many were deactivated
     */
    public static function deactivateMissing(string $entityType, array $seenRemoteIds): int
    {
        if ($seenRemoteIds === []) {
            // Nothing came back at all. Almost certainly a failed or partial
            // fetch rather than a business that deleted every supplier, and
            // wiping the cache on that basis would put every document into
            // review. The caller decides; this refuses.
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($seenRemoteIds), '?'));

        return Database::run(
            'UPDATE clearbooks_cache SET active = 0
              WHERE entity_type = ? AND active = 1 AND remote_id NOT IN (' . $placeholders . ')',
            array_merge([$entityType], array_values($seenRemoteIds))
        )->rowCount();
    }

    /**
     * Find a cached entity by name, deterministically.
     *
     * This is the code fallback the matching stage runs when the LLM found no
     * supplier. It is deliberately unwilling to guess: a name that reduces to
     * the same key as two different records resolves to **neither**, because a
     * wrong supplier on a bill is worse than a bill that waits for a person.
     *
     * Two passes, tried in order and reported separately so the caller can put
     * the weaker one in front of somebody:
     *
     *  1. the normalised key — case, punctuation, `&` and legal suffixes gone;
     *  2. the same with word boundaries removed, which settles "Clearbooks"
     *     against "Clear Books".
     *
     * @param array<int,string> $alsoTry Trading names and other spellings
     * @return array{row:?array<string,mixed>,via:?string,ambiguous:bool,candidates:int}
     */
    public static function matchByName(string $entityType, string $name, array $alsoTry = []): array
    {
        $miss = ['row' => null, 'via' => null, 'ambiguous' => false, 'candidates' => 0];
        $keys = Normaliser::keysFor($name, $alsoTry);

        if ($keys === []) {
            return $miss;
        }

        $rows = self::all($entityType);

        foreach (['exact', 'compact'] as $pass) {
            $hits = [];

            foreach ($rows as $row) {
                // Recomputed rather than trusted: a row cached before the
                // normaliser last changed would otherwise never match.
                $rowKey = Normaliser::key((string) $row['name']);

                foreach ($keys as $key) {
                    $same = $pass === 'exact'
                        ? $rowKey === $key
                        : str_replace(' ', '', $rowKey) === str_replace(' ', '', $key);

                    if ($same) {
                        $hits[(int) $row['id']] = $row;
                        break;
                    }
                }
            }

            if (count($hits) === 1) {
                return [
                    'row'        => reset($hits),
                    'via'        => $pass,
                    'ambiguous'  => false,
                    'candidates' => 1,
                ];
            }

            if (count($hits) > 1) {
                return ['row' => null, 'via' => $pass, 'ambiguous' => true, 'candidates' => count($hits)];
            }
        }

        return $miss;
    }

    /**
     * What this supplier usually does when a document is not an ordinary bill.
     *
     * Local knowledge, not Clear Books'. Some suppliers always issue a credit
     * note against the next invoice; others always refund the card. Recording
     * which turns a reviewer's decision from working it out again every time
     * into confirming a pre-selected answer — and a pre-selected answer that is
     * right nine times in ten is worth having, provided the tenth is still a
     * choice rather than an assumption.
     *
     * It survives a cache refresh because `upsert()` writes only the columns it
     * gets from the API, which is the same reason `paperless_correspondent_id`
     * lives here.
     */
    public static function setDefaultCreditRoute(int $id, ?string $typeKey): void
    {
        Database::update('clearbooks_cache', ['default_credit_route' => $typeKey], $id);
    }

    /**
     * That usual route for a supplier, by Clear Books id, or null.
     */
    public static function defaultCreditRoute(?string $remoteId): ?string
    {
        if ($remoteId === null || $remoteId === '') {
            return null;
        }

        $row = self::find(self::SUPPLIER, $remoteId);

        return $row === null || ($row['default_credit_route'] ?? null) === null
            ? null
            : (string) $row['default_credit_route'];
    }

    /** Point a cached supplier at its Paperless correspondent. */
    public static function linkCorrespondent(int $id, ?int $correspondentId): void
    {
        Database::update('clearbooks_cache', ['paperless_correspondent_id' => $correspondentId], $id);
    }

    /**
     * Suppliers that are no longer in Clear Books but still have a
     * correspondent pointing at them — the sync's demolition list.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function orphanedCorrespondents(): array
    {
        return Database::select(
            'SELECT * FROM clearbooks_cache
              WHERE entity_type = ? AND active = 0 AND paperless_correspondent_id IS NOT NULL
              ORDER BY name',
            [self::SUPPLIER]
        );
    }

    /**
     * How many of each type are cached, and when each was last refreshed.
     *
     * @return array<string,array{count:int,cachedAt:?string}>
     */
    public static function summary(): array
    {
        $summary = [];

        foreach ([self::SUPPLIER, self::ACCOUNT_CODE, self::VAT_TREATMENT, self::VAT_RATE] as $entityType) {
            $summary[$entityType] = [
                'count'    => self::count($entityType),
                'cachedAt' => self::cachedAt($entityType),
            ];
        }

        return $summary;
    }
}
