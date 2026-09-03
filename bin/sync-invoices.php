<?php

declare(strict_types=1);

// Keep a local copy of every purchase document already in Clear Books.
//
// The crontab entry — every five minutes, the script deciding whether the run
// is actually due. (These lines are // rather than part of the block comment
// below for one dull reason: a cron step expression contains the two characters
// that end a PHP block comment.)
//
//   */5 * * * * www-data /usr/bin/php /var/www/invogrid/bin/sync-invoices.php >/dev/null 2>&1

/*
 *   php bin/sync-invoices.php            sync if the schedule says it is due
 *   php bin/sync-invoices.php --force    sync now, whatever the schedule says
 *   php bin/sync-invoices.php --status   what is stored, without fetching
 *
 * Cron runs this often and the *schedule* lives in the database, so an
 * administrator changes "every N minutes" on the Clear Books screen rather than
 * editing /etc/cron.d as root. It also means the "Sync now" button and the cron
 * job are the same code path — the button proves the schedule works, instead of
 * proving that a second implementation of it works.
 *
 * Same shape as bin/process-queue.php and bin/refresh-clearbooks.php: cron
 * rather than a daemon, and a lock file rather than the hope that a run
 * finishes before the next one starts. A full fetch of a long-established
 * business is hundreds of paced requests and can easily outlast the interval.
 *
 * The lock is taken through InvoiceSync rather than here, because the settings
 * screen's button has to queue behind this run and this run behind it. Which
 * file, and why not clearbooks.lock, is documented on InvoiceSync::lock().
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Models\ClearbooksInvoice;
use App\Services\ClearBooksClient;
use App\Services\InvoiceSync;

/** @param array<int,string> $argv */
function flag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

// --- What is stored -------------------------------------------------------

if (flag($argv, 'status')) {
    $summary  = ClearbooksInvoice::summary();
    $interval = InvoiceSync::intervalMinutes();
    $last     = InvoiceSync::lastRun();
    $due      = InvoiceSync::dueAt();

    echo "InvoGrid — Clear Books purchase documents\n\n";

    printf(
        "  credentials %s, authorised %s\n\n",
        ClearBooksClient::isConfigured() ? 'set' : 'NOT set',
        ClearBooksClient::isConnected() ? 'yes' : 'NO — connect from Settings'
    );

    printf("  %-14s %6d\n", 'bills', $summary[ClearbooksInvoice::BILL]);
    printf("  %-14s %6d\n", 'credit notes', $summary[ClearbooksInvoice::CREDIT_NOTE]);
    printf("  %-14s %6d\n\n", 'total', $summary['total']);

    printf("  schedule       %s\n", $interval === 0 ? 'off — by hand only' : 'every ' . $interval . ' minutes');

    if ($last === null) {
        echo "  last run       never\n";
    } else {
        printf(
            "  last run       %s (%s, %s) %.1fs\n",
            (string) ($last['at'] ?? '?'),
            (string) ($last['trigger'] ?? '?'),
            ($last['ok'] ?? false) ? 'ok' : 'FAILED',
            (float) ($last['seconds'] ?? 0)
        );
        printf("                 %s\n", (string) ($last['message'] ?? ''));
    }

    if ($due !== null) {
        printf("  next due       %s%s\n", date('Y-m-d H:i', $due), $due <= time() ? '  (now)' : '');
    }

    exit(0);
}

// --- Do the work ----------------------------------------------------------

$force = flag($argv, 'force');

try {
    $lock = InvoiceSync::lock();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($lock === null) {
    // The previous run, or somebody pressing "Sync now" while this fired. Not
    // an error — it is how a slow fetch and a five-minute cron get along.
    echo "Another invoice sync is still going; nothing to do.\n";
    exit(0);
}

$say = static function (string $line): void {
    echo $line, "\n";
};

$status = 0;

try {
    if ($force) {
        echo "Syncing Clear Books purchase documents...\n";
        $tally = InvoiceSync::run($say, 'manual');
    } else {
        $tally = InvoiceSync::runIfDue($say);

        if ($tally === null) {
            // The normal answer on most cron runs, and not worth a line in a
            // log that is read when something has gone wrong.
            $due = InvoiceSync::dueAt();
            echo $due === null
                ? "The schedule is off; nothing to do.\n"
                : 'Not due until ' . date('Y-m-d H:i', $due) . "; nothing to do.\n";
        }
    }

    if (isset($tally) && $tally !== null) {
        echo "\n", InvoiceSync::describe($tally), "\n";

        if ($tally['derived'] > 0) {
            // Worth saying out loud: it means Clear Books returned no total of
            // its own and every gross amount stored was worked out from the
            // line items. See ClearbooksInvoice::gross().
            printf("Gross worked out from line items for %d of them.\n", $tally['derived']);
        }

        printf("Done in %.1fs\n", (float) $tally['seconds']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    $status = 1;
}

InvoiceSync::unlock($lock);

exit($status);
