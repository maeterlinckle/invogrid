<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\AuditLog;
use App\Models\ClearbooksInvoice;
use App\Models\Setting;
use RuntimeException;
use Throwable;

/**
 * Keep a local copy of every purchase document already in Clear Books.
 *
 * The question this exists to answer is asked later, by the matching and
 * deduplication work: **has this invoice already been posted?** It cannot be
 * asked of Clear Books directly — there is no search endpoint, and a lookup per
 * document against a service that throttles above five requests a second would
 * make every review wait on somebody else's uptime. So the whole list is
 * fetched on a schedule and kept in `clearbooks_invoices`.
 *
 * Two endpoints, one table: `purchases/bills` and `purchases/creditNotes`.
 * Clear Books' `id` is unique across both, so a single `clearbooks_id` key
 * covers them; `purchase_type` records which endpoint a row came from.
 *
 * **Clear Books is the sole source of truth**, exactly as it is for suppliers.
 * A document deleted there is deleted here on the next run. The difference from
 * the supplier sync is that this one may actually delete: a supplier is kept as
 * an inactive row because documents already point at it, and nothing points at
 * these rows at all yet. If anything ever does, `deleteMissing()` is where that
 * decision has to be re-made.
 *
 * The schedule is a plain interval in minutes rather than a cron expression.
 * Cron runs `bin/sync-invoices.php` every few minutes; this decides whether the
 * run is due. That makes changing the schedule a form field instead of root
 * editing `/etc/cron.d/invogrid`, and it means the "Sync now" button and the
 * cron job are the same code path — which is the only way the button proves
 * anything about the schedule.
 */
final class InvoiceSync
{
    /** How often the sync should run, in minutes. 0 means only by hand. */
    public const INTERVAL_KEY = 'clearbooks_invoice_sync_interval_minutes';

    /** What happened last time, as a JSON blob. */
    public const LAST_RUN_KEY = 'clearbooks_invoice_sync_last_run';

    public const DEFAULT_INTERVAL = 60;

    /**
     * The interval bounds offered on the screen.
     *
     * Five minutes at the bottom because a full fetch is hundreds of requests
     * against a rate-limited API and a business does not raise invoices faster
     * than that; a week at the top because anything longer is "off", and 0
     * already says that more honestly.
     */
    public const MIN_INTERVAL = 5;
    public const MAX_INTERVAL = 10080;

    // --- The schedule --------------------------------------------------------

    public static function intervalMinutes(): int
    {
        $minutes = Setting::int(self::INTERVAL_KEY, self::DEFAULT_INTERVAL);

        if ($minutes <= 0) {
            return 0;
        }

        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $minutes));
    }

    /**
     * What happened last time, or null if it has never run.
     *
     * @return array<string,mixed>|null
     */
    public static function lastRun(): ?array
    {
        $stored = (string) Setting::get(self::LAST_RUN_KEY, '');

        if ($stored === '') {
            return null;
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * When the next run is due, as a unix timestamp.
     *
     * Null when the schedule is off. A run that has never happened is due now,
     * which is what an administrator who has just set the interval expects.
     *
     * Measured from when the last run **started**, not from when it finished:
     * "every 30 minutes" should mean every 30 minutes, not "30 minutes after
     * however long the last one took".
     *
     * A failed run stamps the time too, so a broken integration is retried on
     * the schedule rather than on every cron tick — which for a five-minute
     * cron would be a failing request every five minutes, indefinitely, with
     * nobody reading the result.
     */
    public static function dueAt(): ?int
    {
        $interval = self::intervalMinutes();

        if ($interval === 0) {
            return null;
        }

        $last      = self::lastRun();
        $startedAt = is_string($last['at'] ?? null) ? strtotime((string) $last['at']) : false;

        return $startedAt === false ? time() : $startedAt + $interval * 60;
    }

    public static function isDue(): bool
    {
        $due = self::dueAt();

        return $due !== null && $due <= time();
    }

    // --- Not two at once -----------------------------------------------------

    /**
     * Take the sync lock, or get null because another run holds it.
     *
     * A file lock in `storage/`, the same mechanism `bin/process-queue.php` and
     * `bin/refresh-clearbooks.php` use — but reached from here rather than only
     * from the cron script, because "Sync now" on the settings screen has to
     * queue behind a cron run and a cron run behind it. A full fetch takes
     * minutes on an established business, so the two overlapping is not a
     * theoretical race: it is what happens the first time somebody presses the
     * button while wondering why the list is empty.
     *
     * Its own file rather than `clearbooks.lock`: a long invoice sync must not
     * be able to starve the cache refresh that matching depends on. The one
     * thing the two genuinely must not do at once — spend the single-use
     * refresh token twice — is held under a named database lock inside
     * `ClearBooksClient`.
     *
     * @return resource|null The handle to pass back to `unlock()`
     */
    public static function lock()
    {
        $path = rtrim((string) Config::get('storage.path'), '/' . DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'invoices.lock';

        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException('Could not open the invoice sync lock file at ' . $path . '.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    public static function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    // --- Doing it ------------------------------------------------------------

    /**
     * Fetch everything and reconcile, if the schedule says it is time.
     *
     * @param callable(string):void|null $log
     * @return array<string,mixed>|null The tally, or null when nothing was due
     */
    public static function runIfDue(?callable $log = null): ?array
    {
        if (!self::isDue()) {
            return null;
        }

        return self::run($log, 'cron');
    }

    /**
     * Fetch every bill and credit note, and make the table say what Clear Books
     * says.
     *
     * The order matters and is the whole of the deletion safety: **both fetches
     * complete before anything is deleted.** A failure part way through raises,
     * having written whatever it had already upserted — which is harmless,
     * those records really are in Clear Books — and having deleted nothing.
     * Deleting from a half-read list would remove documents that exist.
     *
     * @param callable(string):void|null $log
     * @param string $trigger 'cron' or 'manual', recorded with the result
     * @return array<string,mixed>
     */
    public static function run(?callable $log = null, string $trigger = 'manual'): array
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

        $started   = microtime(true);
        $startedAt = date('Y-m-d H:i:s');
        $client    = new ClearBooksClient();

        $tally = [
            'started_at' => $startedAt,
            'trigger'    => $trigger,
            'types'      => [],
            'deleted'    => 0,
            'derived'    => 0,
            'seconds'    => 0.0,
        ];

        try {
            $seen = [];

            foreach (ClearbooksInvoice::types() as $type) {
                $counts = self::fetch($client, $type, $seen);

                $tally['types'][$type] = $counts;
                $tally['derived']     += $counts['derived'];

                $say('  ' . ClearbooksInvoice::label($type, true) . ': ' . self::line($counts));
            }

            $tally['deleted'] = self::reconcile($seen, $say);
            $tally['seconds'] = round(microtime(true) - $started, 1);

            self::record($tally, true, self::describe($tally));
        } catch (Throwable $e) {
            // Recorded before it is re-thrown, so the settings screen shows the
            // failure rather than a last-run time that quietly stops advancing.
            // A run nobody watched failing invisibly is exactly how a stale
            // duplicate check gets trusted.
            $tally['seconds'] = round(microtime(true) - $started, 1);
            self::record($tally, false, $e->getMessage());

            throw $e;
        }

        // Only when something actually moved. This may run every hour, and an
        // "8,412 unchanged" entry twelve times a day would bury the one that
        // says forty documents disappeared.
        $moved = $tally['deleted'];

        foreach ($tally['types'] as $counts) {
            $moved += $counts['created'] + $counts['updated'];
        }

        if ($moved > 0) {
            AuditLog::record('clearbooks.invoice_sync', null, self::describe($tally));
        }

        return $tally;
    }

    /**
     * Fetch one endpoint, upserting as the records arrive.
     *
     * `$seen` collects the ids across both types, because deletion is decided
     * once for the whole table rather than per endpoint. That is deliberate: a
     * per-type rule would have to treat "no credit notes came back" as
     * suspicious, and for most businesses it is simply true — which would leave
     * a deleted credit note in the table for ever.
     *
     * @param array<int,string> $seen
     * @param callable(string):void $say
     * @return array{fetched:int,created:int,updated:int,unchanged:int,skipped:int,derived:int}
     */
    private static function fetch(ClearBooksClient $client, string $type, array &$seen): array
    {
        $counts = [
            'fetched' => 0, 'created' => 0, 'updated' => 0,
            'unchanged' => 0, 'skipped' => 0, 'derived' => 0,
        ];

        $client->eachPurchase(
            ClearbooksInvoice::RESOURCES[$type],
            static function (array $record) use ($type, &$seen, &$counts): void {
                $counts['fetched']++;

                $result = ClearbooksInvoice::upsert($type, $record);
                $counts[$result['outcome']]++;

                if ($result['derivedGross']) {
                    $counts['derived']++;
                }

                $id = $record['id'] ?? null;

                if (is_scalar($id) && (string) $id !== '') {
                    $seen[] = (string) $id;
                }
            }
        );

        return $counts;
    }

    /**
     * Remove what Clear Books no longer has.
     *
     * The empty-list guard lives in `ClearbooksInvoice::deleteMissing()` and is
     * repeated here only so the operator is told *why* nothing was deleted. A
     * business with no purchase documents at all is possible on day one, and
     * indistinguishable from a fetch that silently returned nothing — so the
     * table is left alone and the run says so.
     *
     * @param array<int,string> $seen
     * @param callable(string):void $say
     */
    private static function reconcile(array $seen, callable $say): int
    {
        if ($seen === []) {
            $say('  nothing came back from either endpoint, so nothing was deleted');

            return 0;
        }

        $deleted = ClearbooksInvoice::deleteMissing($seen);

        $say('  gone from Clear Books: ' . $deleted);

        return $deleted;
    }

    /**
     * Write the outcome where the settings screen can read it.
     *
     * A settings row rather than a table: it is displayed and never queried,
     * and a failed run needs somewhere to put a sentence, which a table of
     * counts has not got.
     *
     * @param array<string,mixed> $tally
     */
    private static function record(array $tally, bool $ok, string $message): void
    {
        Setting::put(self::LAST_RUN_KEY, (string) json_encode([
            'at'      => $tally['started_at'],
            'ok'      => $ok,
            'trigger' => $tally['trigger'],
            'message' => mb_substr($message, 0, 500),
            'types'   => $tally['types'],
            'deleted' => $tally['deleted'],
            'derived' => $tally['derived'],
            'seconds' => $tally['seconds'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array{fetched:int,created:int,updated:int,unchanged:int,skipped:int,derived:int} $counts */
    private static function line(array $counts): string
    {
        return sprintf(
            '%d fetched, %d new, %d changed, %d unchanged%s',
            $counts['fetched'],
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $counts['skipped'] === 0 ? '' : ', ' . $counts['skipped'] . ' with no id skipped'
        );
    }

    /**
     * The run in one sentence, for the activity log and the flash message.
     *
     * @param array<string,mixed> $tally
     */
    public static function describe(array $tally): string
    {
        $parts = [];

        foreach ($tally['types'] as $type => $counts) {
            $parts[] = ClearbooksInvoice::label((string) $type, true) . ' ' . self::line($counts);
        }

        $parts[] = $tally['deleted'] . ' deleted';

        return implode('; ', $parts) . '.';
    }
}
