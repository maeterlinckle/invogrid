<?php

declare(strict_types=1);

/*
 * Work the pipeline queue. Run from cron, once a minute:
 *
 *   * * * * * www-data /usr/bin/php /var/www/invogrid/bin/process-queue.php >/dev/null 2>&1
 *
 *   php bin/process-queue.php            work up to --limit jobs and exit
 *   php bin/process-queue.php --status   what is waiting, without doing any of it
 *   php bin/process-queue.php --limit=20 how many jobs one run may take
 *   php bin/process-queue.php --verbose  say what happened to each one
 *
 * Cron rather than a long-running daemon. The reasoning is in the README, but
 * in short: nothing to supervise, nothing to restart after a deploy, and a
 * crash is one missed minute rather than a queue that stops until somebody
 * notices.
 *
 * Overlapping runs are prevented with a lock file rather than by hoping a run
 * finishes inside a minute. A slow LLM call will sometimes take longer than
 * that, and two workers picking up the same backlog would double the API spend.
 * (Job claiming is safe on its own — SELECT ... FOR UPDATE SKIP LOCKED — so the
 * lock is about not piling up processes, not about correctness.)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Services\Pipeline;

/** @param array<int,string> $argv */
function flag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

/** @param array<int,string> $argv */
function option(array $argv, string $name, int $default): int
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return (int) substr($argument, strlen($name) + 3);
        }
    }

    return $default;
}

$verbose = flag($argv, 'verbose');

// --- What is waiting ------------------------------------------------------

if (flag($argv, 'status')) {
    $jobs = PipelineJob::countsByStatus();

    echo "InvoGrid — queue\n\n";
    printf("  queued %d, running %d, done %d, failed %d\n\n", $jobs['queued'], $jobs['running'], $jobs['done'], $jobs['failed']);

    $pending = Pipeline::pending(20);

    if ($pending === []) {
        echo "  Nothing waiting.\n";
    } else {
        foreach ($pending as $job) {
            printf(
                "  #%-5d %-9s doc %-5d (paperless %-6d) due %s attempt %d%s\n",
                $job['id'],
                $job['stage'],
                $job['document_id'],
                $job['paperless_doc_id'],
                $job['available_at'],
                $job['attempts'],
                $job['last_error'] === null ? '' : '  last error: ' . str_limit((string) $job['last_error'], 80)
            );
        }
    }

    $counts = Document::countsByStatus();
    echo "\n  Documents: ";
    $parts = [];
    foreach ($counts as $status => $count) {
        if ($count > 0) {
            $parts[] = $count . ' ' . Document::label($status);
        }
    }
    echo($parts === [] ? "none yet" : implode(', ', $parts)), "\n";

    exit(0);
}

// --- Do the work ----------------------------------------------------------

$lockPath = rtrim((string) Config::get('storage.path'), '/' . DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'queue.lock';

$lock = fopen($lockPath, 'c');

if ($lock === false) {
    fwrite(STDERR, "Could not open the lock file at {$lockPath}.\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // The previous run is still going. Not an error — this is the normal way a
    // slow stage and a one-minute cron get along.
    if ($verbose) {
        echo "Another run is still working; nothing to do.\n";
    }

    fclose($lock);
    exit(0);
}

$started = microtime(true);

$tally = Pipeline::work(
    option($argv, 'limit', 5),
    $verbose ? static function (string $line): void {
        echo $line, "\n";
    } : null
);

flock($lock, LOCK_UN);
fclose($lock);

if ($verbose || $tally['claimed'] > 0) {
    printf(
        "%d job(s): %d ok, %d failed, in %.1fs\n",
        $tally['claimed'],
        $tally['succeeded'],
        $tally['failed'],
        microtime(true) - $started
    );
}

// Non-zero when something failed, so a cron wrapper or a monitor can notice.
exit($tally['failed'] > 0 ? 1 : 0);
