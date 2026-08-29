<?php

declare(strict_types=1);

/*
 * Small maintenance commands.
 *
 *   php bin/console.php key:generate    print a new APP_KEY for .env
 *   php bin/console.php db:check        verify the database is reachable and migrated
 *   php bin/console.php settings:list   list settings keys and whether each is set
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Migrator;
use App\Models\Setting;

$command = $argv[1] ?? '';

switch ($command) {
    case 'key:generate':
        // Printed rather than written: .env may be root-owned, and silently
        // rewriting a file that holds every credential is not a thing a helper
        // command should do behind an operator's back.
        echo "Add this to .env as APP_KEY (and back it up with the database):\n\n";
        echo '    APP_KEY=' . Crypto::generateKey() . "\n\n";

        if ((string) Config::get('app.key', '') !== '') {
            echo "Note: APP_KEY is already set. Replacing it makes every stored secret\n";
            echo "unreadable — they would all have to be re-entered in Settings.\n";
        }
        break;

    case 'db:check':
        try {
            Database::connection();
        } catch (Throwable $e) {
            exit('Database unreachable: ' . $e->getMessage() . "\n");
        }

        echo 'Connected to ' . Config::get('database.database')
            . ' on ' . Config::get('database.host') . ".\n";

        $pending = (new Migrator())->pending();

        if ($pending === []) {
            echo "Schema is up to date.\n";
        } else {
            echo count($pending) . " migration(s) pending. Run `php bin/migrate.php`.\n";
        }

        echo Crypto::hasKey()
            ? "APP_KEY is set, so secrets can be stored encrypted.\n"
            : "APP_KEY is NOT set. Secrets cannot be saved from Settings until it is —\n"
                . "run `php bin/console.php key:generate`.\n";
        break;

    case 'settings:list':
        // Values of secrets are never printed, here or anywhere else. This says
        // whether each one is configured, which is the question an operator
        // actually has.
        foreach (Setting::summary() as $key => $row) {
            printf(
                "  %-30s %s%s\n",
                $key,
                $row['configured'] ? 'set' : '-',
                $row['secret'] ? '  (secret)' : ''
            );
        }
        break;

    case 'settings:set':
        // Until the Settings screen exists, this is how a credential gets in.
        // The value is read from stdin rather than taken as an argument, so it
        // never reaches shell history or the process list.
        $key = $argv[2] ?? '';

        if ($key === '') {
            exit("Usage: php bin/console.php settings:set <key>\nThe value is read from standard input.\n");
        }

        if (!array_key_exists($key, Setting::summary())) {
            echo "No such setting: {$key}\nRun `php bin/console.php settings:list` for the keys.\n";
            exit(1);
        }

        echo Setting::isSecret($key)
            ? "Value for {$key} (secret; it will be encrypted): "
            : "Value for {$key}: ";

        $value = trim((string) fgets(STDIN));

        if (!Setting::put($key, $value)) {
            exit("\nRefused: {$key} is a secret and APP_KEY is not usable, so it cannot be encrypted.\n"
                . "Run `php bin/console.php key:generate` and put the result in .env.\n");
        }

        echo "\nSaved {$key}.\n";
        break;

    case 'secret:generate':
        // For the webhook shared secret, which has to be invented rather than
        // issued by anybody.
        echo bin2hex(random_bytes(32)), "\n";
        break;

    default:
        echo "InvoGrid console\n\n";
        echo "  php bin/console.php key:generate         print a new APP_KEY for .env\n";
        echo "  php bin/console.php secret:generate      print a random shared secret\n";
        echo "  php bin/console.php db:check             verify the database and schema\n";
        echo "  php bin/console.php settings:list        which settings are configured\n";
        echo "  php bin/console.php settings:set <key>   set one, reading the value from stdin\n";
        exit($command === '' ? 0 : 1);
}
