<?php

declare(strict_types=1);

/*
 * Refresh the cached Clear Books lists, and optionally mirror suppliers into
 * Paperless correspondents.
 *
 *   # every twelve hours: the lists the extraction prompts are built from
 *   0 0,12 * * *  www-data /usr/bin/php /var/www/invogrid/bin/refresh-clearbooks.php >/dev/null 2>&1
 *
 *   # once a day, after a refresh: correspondents follow suppliers
 *   30 3 * * *   www-data /usr/bin/php /var/www/invogrid/bin/refresh-clearbooks.php --sync >/dev/null 2>&1
 *
 *   php bin/refresh-clearbooks.php             refresh the cache
 *   php bin/refresh-clearbooks.php --sync      refresh, then sync correspondents
 *   php bin/refresh-clearbooks.php --sync --dry-run   say what the sync would do
 *   php bin/refresh-clearbooks.php --status    what is cached, without fetching
 *
 * `--dry-run` exists because the sync is the one part of InvoGrid that changes
 * somebody else's system without a person pressing anything. Run it once before
 * the first real run.
 *
 * Same shape as bin/process-queue.php on purpose: cron rather than a daemon,
 * and a lock file rather than the hope that a run finishes before the next one
 * starts. Two concurrent runs would fight over the Clear Books refresh token,
 * which is single use — spending it twice locks the integration out until
 * somebody signs in again.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Models\ClearbooksCache;
use App\Services\CacheRefresh;
use App\Services\ClearBooksClient;
use App\Services\SupplierSync;

/** @param array<int,string> $argv */
function flag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

$dryRun = flag($argv, 'dry-run');
$sync   = flag($argv, 'sync');

// --- What is cached -------------------------------------------------------

if (flag($argv, 'status')) {
    echo "InvoGrid — Clear Books cache\n\n";

    printf(
        "  credentials %s, authorised %s\n",
        ClearBooksClient::isConfigured() ? 'set' : 'NOT set',
        ClearBooksClient::isConnected() ? 'yes' : 'NO — connect from Settings'
    );

    $expires = ClearBooksClient::expiresAt();

    if ($expires !== null) {
        printf(
            "  access token %s (%s)\n",
            $expires > time() ? 'valid' : 'expired',
            date('Y-m-d H:i', $expires)
        );
    }

    echo "\n";

    foreach (ClearbooksCache::summary() as $entityType => $row) {
        printf(
            "  %-15s %5d  %s\n",
            str_replace('_', ' ', $entityType),
            $row['count'],
            $row['cachedAt'] === null ? 'never refreshed' : 'refreshed ' . $row['cachedAt']
        );
    }

    echo "\n  Clear Books has no projects endpoint, so project codes are not cached.\n";
    echo "  They are set by hand in Clear Books, from the link on a submitted document.\n";

    exit(0);
}

// --- Do the work ----------------------------------------------------------

$lockPath = rtrim((string) Config::get('storage.path'), '/' . DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'clearbooks.lock';

$lock = fopen($lockPath, 'c');

if ($lock === false) {
    fwrite(STDERR, "Could not open the lock file at {$lockPath}.\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another Clear Books run is still going; nothing to do.\n";
    fclose($lock);
    exit(0);
}

$started = microtime(true);
$say     = static function (string $line): void {
    echo $line, "\n";
};

$status = 0;

try {
    echo "Refreshing the Clear Books cache...\n";
    CacheRefresh::run($say);

    if ($sync) {
        echo ($dryRun ? "\nCorrespondent sync (dry run — nothing will be changed)...\n" : "\nSyncing Paperless correspondents...\n");
        $tally = SupplierSync::run($dryRun, $say);
        echo '  ' . SupplierSync::describe($tally), "\n";
    }

    printf("\nDone in %.1fs\n", microtime(true) - $started);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    $status = 1;
}

flock($lock, LOCK_UN);
fclose($lock);

exit($status);
