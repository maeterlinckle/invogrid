<?php

declare(strict_types=1);

/*
 * Apply pending database migrations.
 *
 *   php bin/migrate.php            run everything that has not been applied
 *   php bin/migrate.php --status   show what is applied and what is pending
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Migrator;

$migrator = new Migrator();

echo "InvoGrid — migrations\n";
echo 'Database: ' . Config::get('database.database') . ' on ' . Config::get('database.host') . "\n\n";

if (in_array('--status', $argv, true)) {
    $applied = $migrator->applied();

    foreach ($migrator->available() as $file) {
        printf("  [%s] %s\n", in_array($file, $applied, true) ? 'x' : ' ', $file);
    }

    echo "\n" . count($migrator->pending()) . " pending.\n";
    exit(0);
}

$pending = $migrator->pending();

if ($pending === []) {
    echo "Nothing to migrate — the database is up to date.\n";
    exit(0);
}

$applied = $migrator->run(static function (string $file, int $statements): void {
    printf("  applied %s (%d statements)\n", $file, $statements);
});

echo "\nDone. " . count($applied) . " migration(s) applied.\n";
echo "Next: create the first administrator with `php bin/create-admin.php`.\n";
