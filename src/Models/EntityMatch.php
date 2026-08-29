<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

/**
 * What each thing on a document resolved to in Clear Books, and how.
 *
 * One row per entity that has to exist before a document can be submitted: the
 * supplier, and per line item an account code and a VAT rate, plus the
 * document's VAT treatment. `line_index` is what keeps two lines guessing
 * different account codes apart.
 *
 * `matched_via` records *which pass* settled it, and that distinction is the
 * point of the table rather than a detail of it:
 *
 *  - `llm` — the extraction stage matched it against the injected cache, and
 *    the matching stage confirmed the id is real and still current.
 *  - `code_fallback` — the LLM found nothing and the deterministic name
 *    comparison resolved it.
 *  - `manual` — a person did. Those rows survive a re-match; everything else is
 *    rebuilt from scratch, because a stale automatic guess is worse than none.
 */
final class EntityMatch
{
    public const SUPPLIER      = 'supplier';
    public const ACCOUNT_CODE  = 'account_code';
    public const VAT_RATE      = 'vat_rate';
    public const VAT_TREATMENT = 'vat_treatment';

    public const VIA_LLM      = 'llm';
    public const VIA_FALLBACK = 'code_fallback';
    public const VIA_MANUAL   = 'manual';

    public const MATCHED   = 'matched';
    public const UNMATCHED = 'unmatched';
    public const CREATED   = 'created';
    public const REJECTED  = 'rejected';

    /**
     * Replace the automatic matches for one extraction.
     *
     * Anything a person resolved is left exactly as it is, and a fresh row that
     * would land on the same entity is dropped rather than written beside it.
     * Re-running the matching stage after somebody has picked a supplier by
     * hand must not quietly undo that choice — which is the whole reason
     * `needs_review ⇄ matching` exists as a transition.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return int How many were written
     */
    public static function replaceAutomatic(int $extractionId, array $rows): int
    {
        $keep = Database::select(
            'SELECT entity_type, line_index, matched_id FROM entity_matches
              WHERE extraction_id = ? AND (resolved_by IS NOT NULL OR matched_via = ?)',
            [$extractionId, self::VIA_MANUAL]
        );

        $preserved = [];
        $dead      = [];

        foreach ($keep as $row) {
            // A human decision is kept only while it still points at something
            // real. A supplier somebody picked by hand and Clear Books has since
            // archived would otherwise stay "matched" for ever, and the whole
            // point of re-running this stage is to catch exactly that. A row
            // whose target has gone is dropped and re-derived like any other.
            $alive = $row['matched_id'] !== null && ClearbooksCache::isActive(
                (string) $row['entity_type'],
                (string) $row['matched_id']
            );

            if ($alive) {
                $preserved[self::slot((string) $row['entity_type'], $row['line_index'])] = true;
            } else {
                $dead[] = self::slot((string) $row['entity_type'], $row['line_index']);
            }
        }

        Database::run(
            'DELETE FROM entity_matches
              WHERE extraction_id = ? AND resolved_by IS NULL AND (matched_via IS NULL OR matched_via <> ?)',
            [$extractionId, self::VIA_MANUAL]
        );

        foreach ($dead as $slot) {
            [$entityType, $lineIndex] = explode('#', $slot, 2);

            Database::run(
                'DELETE FROM entity_matches
                  WHERE extraction_id = ? AND entity_type = ?
                    AND ' . ($lineIndex === '-' ? 'line_index IS NULL' : 'line_index = ?'),
                $lineIndex === '-'
                    ? [$extractionId, $entityType]
                    : [$extractionId, $entityType, (int) $lineIndex]
            );
        }

        $written = 0;

        foreach ($rows as $row) {
            $slot = self::slot((string) $row['entity_type'], $row['line_index'] ?? null);

            if (isset($preserved[$slot])) {
                continue;
            }

            Database::insert('entity_matches', [
                'extraction_id' => $extractionId,
                'entity_type'   => $row['entity_type'],
                'line_index'    => $row['line_index'] ?? null,
                'raw_value'     => mb_substr((string) ($row['raw_value'] ?? ''), 0, 255),
                'matched_id'    => $row['matched_id'] ?? null,
                'matched_name'  => $row['matched_name'] === null ? null : mb_substr((string) $row['matched_name'], 0, 255),
                'matched_via'   => $row['matched_via'] ?? null,
                'confidence'    => $row['confidence'] ?? null,
                'status'        => $row['status'] ?? self::UNMATCHED,
                'note'          => isset($row['note']) && $row['note'] !== null ? mb_substr((string) $row['note'], 0, 255) : null,
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * Record a person's decision.
     *
     * Stamped with who and when, which is what makes it survive the next
     * automatic pass.
     */
    public static function resolve(int $id, ?string $matchedId, ?string $matchedName, string $status = self::MATCHED): void
    {
        Database::update('entity_matches', [
            'matched_id'   => $matchedId,
            'matched_name' => $matchedName === null ? null : mb_substr($matchedName, 0, 255),
            'matched_via'  => self::VIA_MANUAL,
            'confidence'   => 1.0,
            'status'       => $status,
            'resolved_by'  => Auth::id(),
            'resolved_at'  => date('Y-m-d H:i:s'),
        ], $id);
    }

    /**
     * Record that InvoGrid created this entity in Clear Books.
     *
     * `created` rather than `matched` because the distinction is the whole
     * point of the rule it enforces: **nothing is created without a person
     * confirming it**, and a row saying so is the evidence. Re-deriving the
     * match from the cache afterwards would produce `matched`, which is true
     * but forgets who decided — so the row is stamped and, being human-resolved,
     * survives the next automatic pass.
     */
    public static function markCreated(int $id, string $remoteId, string $name): void
    {
        self::resolve($id, $remoteId, $name, self::CREATED);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forExtraction(int $extractionId): array
    {
        return Database::select(
            'SELECT * FROM entity_matches
              WHERE extraction_id = ?
              ORDER BY FIELD(entity_type, ?, ?, ?, ?), line_index, id',
            [$extractionId, self::SUPPLIER, self::VAT_TREATMENT, self::ACCOUNT_CODE, self::VAT_RATE]
        );
    }

    /**
     * The ones still standing in the way of a submission.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function unresolved(int $extractionId): array
    {
        return Database::select(
            'SELECT * FROM entity_matches
              WHERE extraction_id = ? AND status IN (?, ?)
              ORDER BY FIELD(entity_type, ?, ?, ?, ?), line_index, id',
            [
                $extractionId, self::UNMATCHED, self::REJECTED,
                self::SUPPLIER, self::VAT_TREATMENT, self::ACCOUNT_CODE, self::VAT_RATE,
            ]
        );
    }

    /** Is every entity on this extraction settled? */
    public static function allResolved(int $extractionId): bool
    {
        return self::unresolved($extractionId) === [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM entity_matches WHERE id = ?', [$id]);
    }

    /**
     * The supplier row for an extraction, matched or not.
     *
     * @return array<string,mixed>|null
     */
    public static function supplier(int $extractionId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM entity_matches WHERE extraction_id = ? AND entity_type = ? ORDER BY id DESC LIMIT 1',
            [$extractionId, self::SUPPLIER]
        );
    }

    /**
     * A human label for an entity type, for the review screen.
     */
    public static function label(string $entityType): string
    {
        return match ($entityType) {
            self::SUPPLIER      => 'Supplier',
            self::ACCOUNT_CODE  => 'Account code',
            self::VAT_RATE      => 'VAT rate',
            self::VAT_TREATMENT => 'VAT treatment',
            default             => ucfirst(str_replace('_', ' ', $entityType)),
        };
    }

    /** The identity of one slot on a document: an entity type and, maybe, a line. */
    private static function slot(string $entityType, mixed $lineIndex): string
    {
        return $entityType . '#' . ($lineIndex === null ? '-' : (string) (int) $lineIndex);
    }
}
