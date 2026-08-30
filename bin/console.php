<?php

declare(strict_types=1);

/*
 * Small maintenance commands.
 *
 *   php bin/console.php doctor          check everything a working install needs
 *   php bin/console.php key:generate    print a new APP_KEY for .env
 *   php bin/console.php db:check        verify the database is reachable and migrated
 *   php bin/console.php settings:list   list settings keys and whether each is set
 *
 * `manage.sh` calls this for anything that touches the database, so those jobs
 * go through the application's own models — the same prepared statements, the
 * same validation and the same guard rails as the web interface. Shelling out
 * to the database client to change a role would bypass every one of them.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\PasswordPolicy;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\Setting;
use App\Models\User;
use App\Services\Doctor;

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
        // The same job as the Settings screen, and kept alongside it rather
        // than replaced by it: a credential has to be settable before anybody
        // can sign in to change one, and install.sh has no browser.
        //
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

    case 'doctor':
        /*
         * The first thing anybody runs on a server that is misbehaving, so
         * every row says what is wrong *and* what to do about it. Exit 1 on a
         * failure and 0 otherwise, so it is usable in a health check; a warning
         * is not a failure, because an install that has not been pointed at
         * Clear Books yet is incomplete rather than broken.
         */
        $rows  = Doctor::run();
        $group = '';

        foreach ($rows as $row) {
            if ($row['group'] !== $group) {
                $group = $row['group'];
                echo "\n", $group, "\n";
            }

            printf(
                "  %-6s %-42s %s\n",
                match ($row['status']) {
                    Doctor::OK   => '[ ok ]',
                    Doctor::WARN => '[warn]',
                    default      => '[FAIL]',
                },
                $row['label'],
                $row['detail']
            );

            if ($row['status'] !== Doctor::OK && $row['hint'] !== '') {
                echo '         ', $row['hint'], "\n";
            }
        }

        $worst = Doctor::worst($rows);

        echo "\n", match ($worst) {
            Doctor::OK   => "Everything checks out.\n",
            Doctor::WARN => "Usable, with warnings above.\n",
            default      => "Something is wrong. See the FAIL rows above.\n",
        };

        exit($worst === Doctor::FAIL ? 1 : 0);

    case 'stats':
        $counts = Document::countsByStatus();

        echo "Documents\n";
        foreach ($counts as $status => $count) {
            printf("  %-18s %d\n", Document::label($status), $count);
        }

        $queue = PipelineJob::countsByStatus();

        echo "\nQueue\n";
        foreach ($queue as $status => $count) {
            printf("  %-18s %d\n", $status, $count);
        }

        printf("\n  %-18s %d\n", 'Accounts', User::count());
        break;

    case 'queue:retry':
        /*
         * The command-line twin of the Retry button, for a document nobody can
         * reach the web interface to fix.
         *
         * It resumes at the head of the stage that failed rather than the
         * beginning, exactly as the web one does — `Document::retryStatusFor()`
         * is the single place that decides, so the two cannot drift.
         *
         * The audit entry records no user, because there is none. That is
         * honest: `manage.sh` warns about it before calling this.
         */
        $id       = (int) ($argv[2] ?? 0);
        $document = $id > 0 ? Document::find($id) : null;

        if ($document === null) {
            exit("No document with id {$id}. That is the InvoGrid id, not the Paperless one.\n");
        }

        $from   = (string) $document['status'];
        $target = Document::retryStatusFor(
            $document['failed_stage'] === null ? null : (string) $document['failed_stage']
        );

        try {
            Document::transitionTo($id, $target);
        } catch (Throwable $e) {
            exit($e->getMessage() . "\n");
        }

        PipelineJob::clearFinished($id);
        $queued = App\Services\Pipeline::advance($id, $target);

        App\Models\DocumentEvent::record($id, 'retry', App\Models\DocumentEvent::SUCCEEDED, $from . ' → ' . $target);
        App\Models\AuditLog::record('document.retry', $id, 'Retried from the command line (no signed-in user).');

        echo "Document {$id}: ", Document::label($from), ' → ', Document::label($target), "\n";
        echo $queued === null
            ? "Nothing runs that stage, so it will wait there.\n"
            : "Queued. The next worker run picks it up.\n";
        break;

    case 'user:list':
        printf("  %-18s %-24s %-12s %-8s %s\n", 'USERNAME', 'NAME', 'ROLE', 'STATE', 'LAST SEEN');

        foreach (User::all() as $account) {
            printf(
                "  %-18s %-24s %-12s %-8s %s\n",
                $account['username'],
                mb_substr((string) $account['display_name'], 0, 24),
                $account['role'],
                (int) $account['active'] === 1
                    ? ((int) $account['must_change_password'] === 1 ? 'pw-due' : 'active')
                    : 'off',
                $account['last_login_at'] ?? 'never'
            );
        }
        break;

    case 'user:unlock':
        // No username clears every lockout, which is what you want after a
        // scripted client has hammered the sign-in form and locked out the
        // address for everybody behind it.
        $username = $argv[2] ?? '';

        if ($username === '') {
            $cleared = Database::run('DELETE FROM login_attempts WHERE successful = 0');
            echo "Cleared every sign-in lockout.\n";
            break;
        }

        Database::run('DELETE FROM login_attempts WHERE username = ? AND successful = 0', [mb_strtolower($username)]);
        echo "Cleared the lockout on {$username}.\n";
        break;

    case 'user:activate':
    case 'user:deactivate':
        $username = $argv[2] ?? '';
        $active   = $command === 'user:activate';

        if ($username === '') {
            exit("Usage: php bin/console.php {$command} <username>\n");
        }

        $account = User::findByUsername($username);

        if ($account === null) {
            exit("No such account: {$username}\n");
        }

        try {
            // The model refuses to strand the site without an administrator,
            // and it refuses here for the same reason it refuses on the web.
            User::setActive((int) $account['id'], $active);
        } catch (Throwable $e) {
            exit($e->getMessage() . "\n");
        }

        echo $username, $active ? " can sign in again.\n" : " is deactivated. Its history is kept.\n";
        break;

    case 'user:role':
        $username = $argv[2] ?? '';
        $role     = $argv[3] ?? '';

        if ($username === '' || $role === '') {
            exit("Usage: php bin/console.php user:role <username> <" . implode('|', Auth::ROLES) . ">\n");
        }

        $account = User::findByUsername($username);

        if ($account === null) {
            exit("No such account: {$username}\n");
        }

        try {
            $changed = User::update((int) $account['id'], [
                'display_name' => (string) $account['display_name'],
                'email'        => (string) ($account['email'] ?? ''),
                'role'         => $role,
                'active'       => (int) $account['active'] === 1,
            ]);
        } catch (Throwable $e) {
            exit($e->getMessage() . "\n");
        }

        echo $changed === []
            ? "{$username} was already a {$role}.\n"
            : "{$username} is now a {$role}. It applies to their very next request.\n";
        break;

    case 'user:password':
        /*
         * Read from stdin, never an argument: an argument is in the shell
         * history and in the process list. Sets the must-change flag, exactly
         * as the web screen does — a password somebody else chose is a way in,
         * not a password.
         */
        $username = $argv[2] ?? '';

        if ($username === '') {
            exit("Usage: php bin/console.php user:password <username>\nThe password is read from standard input.\n");
        }

        $account = User::findByUsername($username);

        if ($account === null) {
            exit("No such account: {$username}\n");
        }

        echo PasswordPolicy::describe(), "\n";
        echo "New password for {$username}: ";

        $password = trim((string) fgets(STDIN));
        $problems = PasswordPolicy::problems($password, [
            (string) $account['username'],
            (string) $account['display_name'],
            'invogrid',
        ]);

        if ($problems !== []) {
            exit("\n" . implode("\n", $problems) . "\nNothing changed.\n");
        }

        User::setPassword((int) $account['id'], $password, true);

        echo "\nSet. They will be asked to choose their own on their next sign-in.\n";
        break;
    default:
        echo "InvoGrid console\n\n";
        echo "  php bin/console.php doctor               check everything a working install needs\n";
        echo "  php bin/console.php stats                pipeline and queue counts\n";
        echo "  php bin/console.php key:generate         print a new APP_KEY for .env\n";
        echo "  php bin/console.php secret:generate      print a random shared secret\n";
        echo "  php bin/console.php db:check             verify the database and schema\n";
        echo "  php bin/console.php settings:list        which settings are configured\n";
        echo "  php bin/console.php settings:set <key>   set one, reading the value from stdin\n";
        echo "\n";
        echo "  php bin/console.php queue:retry <id>     retry a failed document\n";
        echo "\n";
        echo "  php bin/console.php user:list            every account\n";
        echo "  php bin/console.php user:password <u>    set a password, reading it from stdin\n";
        echo "  php bin/console.php user:role <u> <role> viewer, reviewer or admin\n";
        echo "  php bin/console.php user:activate <u>    re-enable an account\n";
        echo "  php bin/console.php user:deactivate <u>  disable one\n";
        echo "  php bin/console.php user:unlock [u]      clear sign-in lockouts\n";
        exit($command === '' ? 0 : 1);
}
