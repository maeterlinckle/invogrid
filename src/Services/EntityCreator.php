<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\ClearbooksCache;
use App\Models\Document;
use App\Models\EntityMatch;
use App\Models\Extraction;
use RuntimeException;

/**
 * Create in Clear Books what a document needs and Clear Books does not have.
 *
 * **Only ever from a human pressing a button.** Nothing in the pipeline reaches
 * this class: the extraction stage matches, the matching stage validates, and
 * where neither can resolve something the document waits for a person. That is
 * the rule this whole application is arranged around, and this is the one place
 * it would be easy to break — so it is worth saying plainly that every entry
 * point here is a POST from the review screen with a reviewer's confirmation
 * behind it, and there is no scheduled or automatic caller.
 *
 * The values written are the ones on the form, not the ones the model
 * extracted. The form is *pre-filled* from the extraction, because retyping a
 * supplier's VAT number off a scan is exactly the sort of transcription a
 * machine should do — but what gets created is what the person approved after
 * looking at it.
 *
 * Creating a supplier does two things, in this order, because a failure part
 * way through must leave the earlier one true rather than the later one
 * orphaned:
 *
 *  1. creates it in Clear Books — the record of account, and the only step that
 *     cannot be undone from here;
 *  2. caches it, so the matching pass can see it immediately.
 *
 * There used to be a third — mirroring the supplier into Paperless as a
 * correspondent — and its removal is why the return value no longer carries a
 * correspondent id.
 */
final class EntityCreator
{
    /**
     * Create a supplier and resolve the document's supplier match to it.
     *
     * @param array<string,mixed> $fields As confirmed on the form
     * @return array{cbId:string,name:string,status:string}
     */
    public static function supplier(int $documentId, int $entityMatchId, array $fields): array
    {
        $name = trim((string) ($fields['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('A supplier needs a name.');
        }

        $match = self::matchFor($entityMatchId, $documentId, EntityMatch::SUPPLIER);

        // Refuse to create a second one for the same document. Two reviewers
        // with the page open, or one impatient double-click, would otherwise
        // put two identical suppliers into somebody's ledger — and the
        // duplicate is discovered weeks later by an accountant, not now.
        if ((string) $match['status'] === EntityMatch::CREATED || $match['matched_id'] !== null) {
            throw new RuntimeException(
                'That supplier has already been resolved to ' . ($match['matched_name'] ?? $match['matched_id'])
                . '. Reload the page.'
            );
        }

        $payload = array_filter([
            'name'          => $name,
            'email'         => self::str($fields['email'] ?? null),
            'phone'         => self::str($fields['phone'] ?? null),
            'vatNumber'     => self::str($fields['vatNumber'] ?? null),
            'companyNumber' => self::str($fields['companyNumber'] ?? null),
            'address'       => self::address($fields),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $created = (new ClearBooksClient())->createSupplier($payload);
        $cbId    = (string) ($created['id'] ?? '');

        if ($cbId === '') {
            throw new RuntimeException('Clear Books created the supplier but did not say what its id is.');
        }

        AuditLog::record(
            'clearbooks.supplier_created',
            $documentId,
            'Created Clear Books supplier ' . $cbId . ' "' . $name . '" — confirmed by ' . Auth::displayName() . '.'
        );

        // Cached straight away rather than waiting for the next refresh: the
        // re-check below reads the cache, and a supplier that exists in Clear
        // Books but not here would leave the document unresolved with no
        // explanation a person could act on.
        ClearbooksCache::upsert(ClearbooksCache::SUPPLIER, $cbId, $name, $created + $payload);
        $cached = ClearbooksCache::find(ClearbooksCache::SUPPLIER, $cbId);

        // Point the extraction at it, so the re-check derives the match from
        // the record rather than from this method having said so.
        self::pointExtractionAtSupplier($documentId, $cbId, $name);

        $status = MatchStage::recheck($documentId);

        // Stamped after the re-check, which rebuilds the row: `created` is a
        // fact about what InvoGrid did that re-deriving from the cache would
        // quietly turn into an ordinary match.
        $rebuilt = EntityMatch::supplier((int) self::extraction($documentId)['id']);

        if ($rebuilt !== null) {
            EntityMatch::markCreated((int) $rebuilt['id'], $cbId, $name);
        }

        return [
            'cbId'   => $cbId,
            'name'   => $name,
            'status' => $status,
        ];
    }

    /**
     * Resolve an entity to something already on file.
     *
     * The other half of the review screen, and the far more common one: the
     * supplier *is* in Clear Books, the matching simply could not see it —
     * a trading name nobody has recorded, an ampersand, a merger. Nothing is
     * created; the extraction is pointed at the existing record and re-checked.
     *
     * For account codes, VAT rates and VAT treatments this is the **only**
     * option offered. A VAT rate cannot be created through the API at all, and
     * inventing a nominal code from an invoice-review screen is how a chart of
     * accounts quietly fills up with near-duplicates.
     */
    public static function pick(int $documentId, int $entityMatchId, string $remoteId): array
    {
        $match  = self::matchFor($entityMatchId, $documentId, null);
        $type   = (string) $match['entity_type'];
        $cached = ClearbooksCache::find(self::cacheType($type), $remoteId);

        if ($cached === null || (int) $cached['active'] !== 1) {
            throw new RuntimeException('That is not something currently on file in Clear Books.');
        }

        $name = (string) $cached['name'];

        switch ($type) {
            case EntityMatch::SUPPLIER:
                self::pointExtractionAtSupplier($documentId, $remoteId, $name);
                break;

            case EntityMatch::VAT_TREATMENT:
                Extraction::updateFields(
                    (int) self::extraction($documentId)['id'],
                    ['vat_treatment' => ['key' => $remoteId, 'name' => $name]],
                    Auth::id()
                );
                break;

            default:
                self::setLineValue(
                    $documentId,
                    $match['line_index'] === null ? null : (int) $match['line_index'],
                    $type === EntityMatch::ACCOUNT_CODE ? 'accountCode' : 'vatRateKey',
                    $type === EntityMatch::ACCOUNT_CODE && ctype_digit($remoteId) ? (int) $remoteId : $remoteId
                );
        }

        AuditLog::record(
            'review.entity_resolved',
            $documentId,
            EntityMatch::label($type) . ($match['line_index'] === null ? '' : ' on line ' . ((int) $match['line_index'] + 1))
                . ' set to "' . $name . '" (' . $remoteId . ') by ' . Auth::displayName() . '.'
        );

        $status = MatchStage::recheck($documentId);

        // Same reasoning as a creation: a person made this choice, and the row
        // should say so rather than reporting whichever pass happened to
        // re-derive it.
        $rebuilt = self::rebuiltRow($documentId, $type, $match['line_index']);

        if ($rebuilt !== null) {
            EntityMatch::resolve((int) $rebuilt['id'], $remoteId, $name, EntityMatch::MATCHED);
        }

        return ['remoteId' => $remoteId, 'name' => $name, 'status' => $status];
    }

    // --- Plumbing -----------------------------------------------------------

    /**
     * Write a supplier decision into the extraction record.
     *
     * The extraction is the document's own statement of what it is; the
     * `entity_matches` row is derived from it. Updating the derived row alone
     * would be undone by the next re-check, which is exactly the bug that makes
     * a review screen feel haunted.
     */
    private static function pointExtractionAtSupplier(int $documentId, string $cbId, string $name): void
    {
        $extraction = self::extraction($documentId);
        $supplier   = Extraction::decode($extraction, 'supplier_match');

        $supplier['supplierMatched'] = true;
        $supplier['cbId']            = $cbId;

        // Any `paperlessId` left on an extraction from before the pivot is
        // dropped rather than carried forward. It points at a record in a
        // system this application no longer talks to, and a stale id that
        // still looks live is worse than no id at all.
        unset($supplier['paperlessId']);

        // What the model read off the letterhead is kept: it is the evidence
        // for the decision, and a later reader asking "why was this matched to
        // that" needs to see both halves.
        $supplier['nameOnDocument'] = $supplier['nameOnDocument']
            ?? ($extraction['supplier_name_raw'] ?? ($supplier['name'] ?? null));

        Extraction::updateFields((int) $extraction['id'], [
            'supplier_match'    => $supplier,
            'supplier_name_raw' => $name,
        ], Auth::id());

        Document::setMatchedSupplier($documentId, $cbId, $name);
    }

    /** Set one field on one line item. */
    private static function setLineValue(int $documentId, ?int $lineIndex, string $key, mixed $value): void
    {
        $extraction = self::extraction($documentId);
        $lines      = array_values(Extraction::decode($extraction, 'line_items'));

        if ($lineIndex === null || !isset($lines[$lineIndex]) || !is_array($lines[$lineIndex])) {
            throw new RuntimeException('That line is no longer on this document. Reload the page.');
        }

        $lines[$lineIndex][$key] = $value;

        Extraction::updateFields((int) $extraction['id'], ['line_items' => $lines], Auth::id());

        // A line has changed, so the totals have to follow. Picking a VAT rate
        // is the case that matters: the stored VAT and gross were left null
        // because the rate was unknown, and without this the document would be
        // submitted still describing itself as having no VAT.
        Extraction::refreshTotals((int) $extraction['id'], Auth::id());
    }

    /** @return array<string,mixed> */
    private static function extraction(int $documentId): array
    {
        $extraction = Extraction::latest($documentId);

        if ($extraction === null) {
            throw new RuntimeException('This document has nothing extracted to resolve.');
        }

        return $extraction;
    }

    /**
     * The entity_matches row, checked to belong to this document.
     *
     * A match id comes off a form, which means it comes from outside. Without
     * this, a reviewer could resolve an entity on a document they are not
     * looking at by editing one number.
     *
     * @return array<string,mixed>
     */
    private static function matchFor(int $entityMatchId, int $documentId, ?string $expectedType): array
    {
        $match      = EntityMatch::find($entityMatchId);
        $extraction = self::extraction($documentId);

        if ($match === null || (int) $match['extraction_id'] !== (int) $extraction['id']) {
            throw new RuntimeException('That is not something to resolve on this document.');
        }

        if ($expectedType !== null && (string) $match['entity_type'] !== $expectedType) {
            throw new RuntimeException('That entity is a ' . $match['entity_type'] . ', not a ' . $expectedType . '.');
        }

        return $match;
    }

    /** The row the re-check produced in the same slot, if any. */
    private static function rebuiltRow(int $documentId, string $entityType, mixed $lineIndex): ?array
    {
        $extractionId = (int) self::extraction($documentId)['id'];

        foreach (EntityMatch::forExtraction($extractionId) as $row) {
            $sameLine = $lineIndex === null
                ? $row['line_index'] === null
                : (string) $row['line_index'] === (string) $lineIndex;

            if ((string) $row['entity_type'] === $entityType && $sameLine) {
                return $row;
            }
        }

        return null;
    }

    /** `entity_matches.entity_type` and `clearbooks_cache.entity_type` agree by design. */
    private static function cacheType(string $entityType): string
    {
        return match ($entityType) {
            EntityMatch::SUPPLIER      => ClearbooksCache::SUPPLIER,
            EntityMatch::ACCOUNT_CODE  => ClearbooksCache::ACCOUNT_CODE,
            EntityMatch::VAT_RATE      => ClearbooksCache::VAT_RATE,
            EntityMatch::VAT_TREATMENT => ClearbooksCache::VAT_TREATMENT,
            default                    => throw new RuntimeException('Not an entity type: ' . $entityType),
        };
    }

    /**
     * The Clear Books address object, or null when nothing was given.
     *
     * @param array<string,mixed> $fields
     * @return array<string,string>|null
     */
    private static function address(array $fields): ?array
    {
        $address = [];

        foreach (['building', 'line1', 'line2', 'town', 'county', 'postcode', 'countryCode'] as $part) {
            $value = self::str($fields['address_' . $part] ?? null);

            if ($value !== null) {
                $address[$part] = $value;
            }
        }

        return $address === [] ? null : $address;
    }

    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
