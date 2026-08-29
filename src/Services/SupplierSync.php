<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClearbooksCache;
use App\Models\Document;
use App\Models\Setting;
use RuntimeException;
use Throwable;

/**
 * Keep Paperless correspondents in step with Clear Books suppliers.
 *
 * **Clear Books is the source of truth.** A supplier there is a correspondent
 * here; a rename there is a rename here; a supplier retired there eventually
 * means a correspondent removed here. Nothing flows the other way — a
 * correspondent invented in Paperless is left entirely alone, because it is
 * somebody filing a document, not somebody deciding the chart of suppliers.
 *
 * Run after a cache refresh, daily rather than hourly: correspondent churn is
 * slow, and every run is a handful of writes against somebody's live archive.
 *
 * The three cases, and the one that needs the care:
 *
 *  - **New supplier** → find a correspondent that already has that name before
 *    creating one. Paperless correspondents are name-unique, and creating a
 *    duplicate is both an error and, worse, a second correspondent that
 *    document filing then splits across.
 *  - **Renamed supplier** → rename the correspondent.
 *  - **Removed supplier** → this is the dangerous one, and the rule is
 *    absolute: **a correspondent with documents pointing at it is never
 *    deleted.** Each such document is first sent to whichever supplier Clear
 *    Books now considers correct; only when the count reaches zero does the
 *    correspondent go. A document that cannot be re-pointed is flagged and the
 *    correspondent stays, indefinitely if need be. An unfiled document is a
 *    real loss to a person; a stale correspondent is untidiness.
 *
 * Every create, rename, delete, re-point and flag is written to `audit_log`,
 * because this is the one part of InvoGrid that changes somebody else's system
 * without a person pressing anything.
 */
final class SupplierSync
{
    /** Marks a note as ours, so a daily run does not add it again. */
    private const NOTE_MARKER = '[InvoGrid]';

    /**
     * @param callable(string):void|null $log
     * @return array<string,int>
     */
    public static function run(bool $dryRun = false, ?callable $log = null): array
    {
        $say = $log ?? static function (string $line): void {
        };

        if (!PaperlessClient::isConfigured()) {
            throw new RuntimeException('Paperless is not configured, so correspondents cannot be synchronised.');
        }

        if (!Setting::bool('clearbooks_sync_correspondents', true)) {
            $say('  correspondent sync is switched off in Settings.');

            return self::emptyTally();
        }

        $paperless = new PaperlessClient();
        $tally     = self::emptyTally();

        // Fetched once. Two hundred suppliers would otherwise be two hundred
        // list calls, and the list changes only as fast as this run changes it.
        $correspondents = [];
        $byName         = [];

        foreach ($paperless->correspondents() as $row) {
            $id   = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));

            if ($id === 0 || $name === '') {
                continue;
            }

            $correspondents[$id] = $name;

            // Indexed by the same key the supplier matcher uses, so "Acme Ltd"
            // in Paperless is found for "Acme Limited" in Clear Books rather
            // than duplicated.
            $byName[Normaliser::key($name)] = $id;
        }

        $say('  ' . count($correspondents) . ' correspondent(s) in Paperless.');

        foreach (ClearbooksCache::all(ClearbooksCache::SUPPLIER) as $supplier) {
            $linked = $supplier['paperless_correspondent_id'] === null
                ? null
                : (int) $supplier['paperless_correspondent_id'];
            $name   = (string) $supplier['name'];

            // A link to a correspondent somebody has since deleted in Paperless
            // is a dead link, not a link. Treated as unlinked so the next block
            // re-establishes it.
            if ($linked !== null && !isset($correspondents[$linked])) {
                ClearbooksCache::linkCorrespondent((int) $supplier['id'], null);
                $linked = null;
            }

            if ($linked === null) {
                $tally = self::link($paperless, $supplier, $byName, $correspondents, $dryRun, $say, $tally);
                continue;
            }

            if ($correspondents[$linked] !== $name) {
                $tally = self::rename($paperless, $supplier, $linked, $correspondents[$linked], $dryRun, $say, $tally);
            }
        }

        foreach (ClearbooksCache::orphanedCorrespondents() as $supplier) {
            $tally = self::remove($paperless, $supplier, $correspondents, $dryRun, $say, $tally);
        }

        // Only when something actually moved. `flagged` and `skipped` are
        // standing states rather than changes — a document that could not be
        // re-pointed is counted every night for as long as it lasts, and
        // recording that nightly would bury the entries that matter.
        $changed = $tally['linked'] + $tally['created'] + $tally['renamed']
            + $tally['repointed'] + $tally['deleted'] + $tally['failed'];

        if ($changed > 0) {
            AuditLog::record(
                'clearbooks.correspondent_sync',
                null,
                ($dryRun ? 'Dry run. ' : '') . self::describe($tally)
            );
        }

        return $tally;
    }

    /**
     * Give one cached supplier its Paperless correspondent, now.
     *
     * The single-supplier form of the link/create pass below, for the review
     * screen: somebody has just created a supplier in Clear Books by hand and
     * the correspondent has to exist before the document can be filed under it,
     * rather than at three in the morning when the cron runs.
     *
     * It refetches the correspondent list, which the bulk pass would never do
     * per supplier — one extra call against a human action is nothing, and
     * sharing the **rule** (look by normalised name before creating) matters
     * far more than sharing the loop. A duplicate correspondent splits a
     * supplier's filing in two and is tedious to undo.
     *
     * @param array<string,mixed> $supplier A `clearbooks_cache` row
     * @return int|null The correspondent id, or null if Paperless is unusable
     */
    public static function ensureCorrespondent(array $supplier): ?int
    {
        if (!PaperlessClient::isConfigured() || !Setting::bool('clearbooks_sync_correspondents', true)) {
            return null;
        }

        $name = (string) $supplier['name'];

        try {
            $paperless = new PaperlessClient();
            $wanted    = Normaliser::key($name);

            foreach ($paperless->correspondents() as $row) {
                $existingId   = (int) ($row['id'] ?? 0);
                $existingName = trim((string) ($row['name'] ?? ''));

                if ($existingId === 0 || $existingName === '' || Normaliser::key($existingName) !== $wanted) {
                    continue;
                }

                ClearbooksCache::linkCorrespondent((int) $supplier['id'], $existingId);
                AuditLog::record(
                    'paperless.correspondent_linked',
                    null,
                    'Clear Books supplier "' . $name . '" (' . $supplier['remote_id']
                        . ') linked to the correspondent already called "' . $existingName . '".'
                );

                return $existingId;
            }

            $created = $paperless->createCorrespondent($name);
            $id      = (int) ($created['id'] ?? 0);

            if ($id === 0) {
                return null;
            }

            ClearbooksCache::linkCorrespondent((int) $supplier['id'], $id);
            AuditLog::record(
                'paperless.correspondent_created',
                null,
                'Created Paperless correspondent ' . $id . ' "' . $name
                    . '" for Clear Books supplier ' . $supplier['remote_id'] . '.'
            );

            return $id;
        } catch (Throwable $e) {
            // Not fatal to the thing the person was actually doing. The supplier
            // exists in Clear Books, the document can be submitted, and the
            // nightly sync will pick the correspondent up. Saying so is better
            // than throwing away a successful creation.
            AuditLog::record(
                'paperless.correspondent_failed',
                null,
                'Could not create a correspondent for "' . $name . '": ' . $e->getMessage()
            );

            return null;
        }
    }

    // --- New supplier -------------------------------------------------------

    /**
     * @param array<string,mixed>  $supplier
     * @param array<string,int>    $byName
     * @param array<int,string>    $correspondents
     * @param array<string,int>    $tally
     * @param callable(string):void $say
     * @return array<string,int>
     */
    private static function link(
        PaperlessClient $paperless,
        array $supplier,
        array &$byName,
        array &$correspondents,
        bool $dryRun,
        callable $say,
        array $tally,
    ): array {
        $name     = (string) $supplier['name'];
        $existing = $byName[Normaliser::key($name)] ?? null;

        if ($existing !== null) {
            $say('  link: "' . $name . '" → existing correspondent ' . $existing);

            if (!$dryRun) {
                ClearbooksCache::linkCorrespondent((int) $supplier['id'], $existing);
                AuditLog::record(
                    'paperless.correspondent_linked',
                    null,
                    'Clear Books supplier "' . $name . '" (' . $supplier['remote_id']
                        . ') linked to the correspondent already called "' . $correspondents[$existing] . '".'
                );
            }

            $tally['linked']++;

            return $tally;
        }

        $say('  create: "' . $name . '"');

        if ($dryRun) {
            $tally['created']++;

            return $tally;
        }

        try {
            $created = $paperless->createCorrespondent($name);
        } catch (Throwable $e) {
            $say('    failed: ' . $e->getMessage());
            AuditLog::record(
                'paperless.correspondent_failed',
                null,
                'Could not create a correspondent for "' . $name . '": ' . $e->getMessage()
            );
            $tally['failed']++;

            return $tally;
        }

        $id = (int) ($created['id'] ?? 0);

        if ($id === 0) {
            $tally['failed']++;

            return $tally;
        }

        ClearbooksCache::linkCorrespondent((int) $supplier['id'], $id);
        $correspondents[$id]              = $name;
        $byName[Normaliser::key($name)]   = $id;
        $tally['created']++;

        AuditLog::record(
            'paperless.correspondent_created',
            null,
            'Created Paperless correspondent ' . $id . ' "' . $name . '" for Clear Books supplier ' . $supplier['remote_id'] . '.'
        );

        return $tally;
    }

    // --- Renamed supplier ---------------------------------------------------

    /**
     * @param array<string,mixed>  $supplier
     * @param array<string,int>    $tally
     * @param callable(string):void $say
     * @return array<string,int>
     */
    private static function rename(
        PaperlessClient $paperless,
        array $supplier,
        int $correspondentId,
        string $currentName,
        bool $dryRun,
        callable $say,
        array $tally,
    ): array {
        $name = (string) $supplier['name'];

        $say('  rename: correspondent ' . $correspondentId . ' "' . $currentName . '" → "' . $name . '"');

        if ($dryRun) {
            $tally['renamed']++;

            return $tally;
        }

        try {
            $paperless->updateCorrespondent($correspondentId, ['name' => $name]);
        } catch (Throwable $e) {
            // Correspondent names are unique in Paperless, so the likely
            // failure here is that somebody has already created one under the
            // new name by hand. Recorded rather than retried: which of the two
            // correspondents the documents should end up under is a judgement,
            // and merging them silently is not this job's to make.
            $say('    failed: ' . $e->getMessage());
            AuditLog::record(
                'paperless.correspondent_failed',
                null,
                'Could not rename correspondent ' . $correspondentId . ' from "' . $currentName
                    . '" to "' . $name . '": ' . $e->getMessage()
                    . ' If a correspondent of that name already exists, the two need merging by hand.'
            );
            $tally['failed']++;

            return $tally;
        }

        $tally['renamed']++;

        AuditLog::record(
            'paperless.correspondent_renamed',
            null,
            'Renamed Paperless correspondent ' . $correspondentId . ' from "' . $currentName . '" to "' . $name
                . '", following Clear Books supplier ' . $supplier['remote_id'] . '.'
        );

        return $tally;
    }

    // --- Removed supplier ---------------------------------------------------

    /**
     * Deal with a correspondent whose supplier has gone from Clear Books.
     *
     * The order matters and is the safety property: **count first, re-point
     * what can be re-pointed, count again, and only then delete.** A delete is
     * never attempted on a correspondent that still has documents.
     *
     * @param array<string,mixed>  $supplier
     * @param array<int,string>    $correspondents
     * @param array<string,int>    $tally
     * @param callable(string):void $say
     * @return array<string,int>
     */
    private static function remove(
        PaperlessClient $paperless,
        array $supplier,
        array $correspondents,
        bool $dryRun,
        callable $say,
        array $tally,
    ): array {
        $correspondentId = (int) $supplier['paperless_correspondent_id'];
        $name            = (string) $supplier['name'];

        if (!isset($correspondents[$correspondentId])) {
            // Already gone from Paperless. Drop the dead link and say nothing
            // further about it.
            if (!$dryRun) {
                ClearbooksCache::linkCorrespondent((int) $supplier['id'], null);
            }

            return $tally;
        }

        $documents = $paperless->documentsForCorrespondent($correspondentId);
        $say('  removed supplier "' . $name . '": correspondent ' . $correspondentId
            . ' has ' . count($documents) . ' document(s).');

        $stillThere = 0;

        foreach ($documents as $paperlessDocument) {
            $documentId = (int) ($paperlessDocument['id'] ?? 0);

            if ($documentId === 0) {
                continue;
            }

            $replacement = self::replacementFor($documentId, $name, $correspondentId);

            if ($replacement === null) {
                $stillThere++;
                $tally['flagged']++;
                $say('    flag: Paperless document ' . $documentId . ' — no current supplier could be determined.');

                if (!$dryRun) {
                    self::flag($paperless, $paperlessDocument, $documentId, $name);
                }

                continue;
            }

            $say('    re-point: Paperless document ' . $documentId . ' → "' . $replacement['name'] . '"');

            if ($dryRun) {
                $tally['repointed']++;
                continue;
            }

            try {
                $paperless->setCorrespondent($documentId, (int) $replacement['correspondentId']);
            } catch (Throwable $e) {
                $stillThere++;
                $tally['failed']++;
                $say('      failed: ' . $e->getMessage());

                continue;
            }

            $tally['repointed']++;

            AuditLog::record(
                'paperless.document_repointed',
                self::localDocumentId($documentId),
                'Paperless document ' . $documentId . ' moved from the retired correspondent "' . $name
                    . '" to "' . $replacement['name'] . '".'
            );
        }

        if ($stillThere > 0) {
            $say('    correspondent ' . $correspondentId . ' kept: ' . $stillThere . ' document(s) still point at it.');

            AuditLog::record(
                'paperless.correspondent_kept',
                null,
                'Correspondent ' . $correspondentId . ' "' . $name . '" was not deleted: ' . $stillThere
                    . ' document(s) still reference it and no current supplier could be determined for them.'
            );

            return $tally;
        }

        if (!Setting::bool('clearbooks_delete_correspondents', true)) {
            $say('    correspondent ' . $correspondentId . ' left in place: deletion is switched off in Settings.');
            $tally['skipped']++;

            return $tally;
        }

        $say('    delete: correspondent ' . $correspondentId . ' "' . $name . '"');

        if ($dryRun) {
            $tally['deleted']++;

            return $tally;
        }

        try {
            $paperless->deleteCorrespondent($correspondentId);
        } catch (Throwable $e) {
            $say('      failed: ' . $e->getMessage());
            $tally['failed']++;

            return $tally;
        }

        ClearbooksCache::linkCorrespondent((int) $supplier['id'], null);
        $tally['deleted']++;

        AuditLog::record(
            'paperless.correspondent_deleted',
            null,
            'Deleted Paperless correspondent ' . $correspondentId . ' "' . $name
                . '": its Clear Books supplier is gone and no document referenced it.'
        );

        return $tally;
    }

    /**
     * Which supplier does Clear Books now consider correct for this document?
     *
     * Two ways of answering, and neither guesses:
     *
     *  1. InvoGrid processed the document and the matching stage settled on a
     *     supplier. If that supplier is still current and has a correspondent,
     *     that is the answer outright.
     *  2. Otherwise, the retired supplier's *name* is compared against the
     *     current supplier list. A supplier deleted and re-created — which is
     *     how a rename sometimes reaches an accounting system — resolves here.
     *     Only an unambiguous match counts.
     *
     * Anything else returns null, and the document is flagged rather than
     * moved. Filing a bill under the wrong supplier is worse than leaving it
     * where somebody put it.
     *
     * @return array{correspondentId:int,name:string}|null
     */
    private static function replacementFor(int $paperlessDocumentId, string $retiredName, int $retiredCorrespondentId): ?array
    {
        $local = $paperlessDocumentId === 0 ? null : Document::findByPaperlessId($paperlessDocumentId);
        $cbId  = $local === null ? null : $local['correspondent_matched_supplier_id'];

        if (is_scalar($cbId) && (string) $cbId !== '') {
            $candidate = ClearbooksCache::find(ClearbooksCache::SUPPLIER, (string) $cbId);

            $usable = $candidate !== null
                && (int) $candidate['active'] === 1
                && $candidate['paperless_correspondent_id'] !== null
                && (int) $candidate['paperless_correspondent_id'] !== $retiredCorrespondentId;

            if ($usable) {
                return [
                    'correspondentId' => (int) $candidate['paperless_correspondent_id'],
                    'name'            => (string) $candidate['name'],
                ];
            }
        }

        $found = ClearbooksCache::matchByName(ClearbooksCache::SUPPLIER, $retiredName);
        $row   = $found['row'];

        if ($row === null || $row['paperless_correspondent_id'] === null) {
            return null;
        }

        if ((int) $row['paperless_correspondent_id'] === $retiredCorrespondentId) {
            return null;
        }

        return [
            'correspondentId' => (int) $row['paperless_correspondent_id'],
            'name'            => (string) $row['name'],
        ];
    }

    /**
     * Leave a note on a document that could not be re-pointed.
     *
     * Written into Paperless as well as into `audit_log`, because the person who
     * has to decide is looking at Paperless, not at InvoGrid's activity page.
     * Added at most once: the marker is looked for first, or a daily cron would
     * paste the same sentence on for as long as the situation lasted.
     *
     * @param array<string,mixed> $paperlessDocument
     */
    private static function flag(PaperlessClient $paperless, array $paperlessDocument, int $documentId, string $retiredName): void
    {
        $note = self::NOTE_MARKER . ' The Clear Books supplier "' . $retiredName
            . '" no longer exists, and no current supplier could be determined for this document. '
            . 'Its correspondent has been left in place — please set the right one.';

        try {
            $notes = $paperlessDocument['notes'] ?? null;

            // The list serialiser normally carries the notes; if it did not,
            // ask for the document rather than assume there are none.
            if (!is_array($notes)) {
                $notes = $paperless->document($documentId)['notes'] ?? [];
            }

            foreach (is_array($notes) ? $notes : [] as $existing) {
                $text = is_array($existing) ? (string) ($existing['note'] ?? '') : (string) $existing;

                // Already flagged on an earlier run. Returning here is what
                // keeps a nightly cron from writing the same audit entry and
                // the same note for as long as the situation lasts — and an
                // audit trail nobody can read is not an audit trail.
                if (str_contains($text, self::NOTE_MARKER) && str_contains($text, $retiredName)) {
                    return;
                }
            }

            $paperless->addNote($documentId, $note);
        } catch (Throwable) {
            // A note that could not be written is not a reason to abandon the
            // rest of the run; the audit entry below is the record that
            // matters, and it is still made.
        }

        AuditLog::record(
            'paperless.document_flagged',
            self::localDocumentId($documentId),
            'Paperless document ' . $documentId . ' still points at the retired correspondent "' . $retiredName
                . '" and could not be re-pointed automatically.'
        );
    }

    /** The InvoGrid document id for a Paperless one, when there is one. */
    private static function localDocumentId(int $paperlessDocumentId): ?int
    {
        $row = Document::findByPaperlessId($paperlessDocumentId);

        return $row === null ? null : (int) $row['id'];
    }

    /** @return array<string,int> */
    private static function emptyTally(): array
    {
        return [
            'linked'    => 0,
            'created'   => 0,
            'renamed'   => 0,
            'repointed' => 0,
            'flagged'   => 0,
            'deleted'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
        ];
    }

    /** @param array<string,int> $tally */
    public static function describe(array $tally): string
    {
        $parts = [];

        foreach ($tally as $what => $count) {
            if ($count > 0) {
                $parts[] = $count . ' ' . $what;
            }
        }

        return $parts === [] ? 'Nothing to change.' : implode(', ', $parts) . '.';
    }
}
