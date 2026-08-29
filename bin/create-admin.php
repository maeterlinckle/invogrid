<?php

declare(strict_types=1);

/*
 * Create the first administrator — or any other account — from the command
 * line. This is how InvoGrid gets its first user: there is no open sign-up page
 * and no first-run web wizard, because either one is a hole on a server that is
 * reachable before anybody has configured it.
 *
 *   php bin/create-admin.php
 *   php bin/create-admin.php --username=jo --name="Jo Bloggs" --role=admin
 *
 * The password is asked for interactively so it never lands in shell history.
 * Run it again for an existing username to reset that account's password.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\PasswordPolicy;
use App\Models\User;

/** @param array<int,string> $argv */
function option(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return trim(substr($arg, strlen($name) + 3), " \"'");
        }
    }

    return null;
}

function prompt(string $question, bool $hidden = false): string
{
    echo $question;

    if ($hidden && DIRECTORY_SEPARATOR !== chr(92)) {
        // POSIX: turn off terminal echo while the password is typed. On Windows
        // there is no portable equivalent, so it is simply visible there.
        shell_exec('stty -echo 2>/dev/null');
        $value = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;

        return $value;
    }

    return trim((string) fgets(STDIN));
}

echo "InvoGrid — create user\n\n";

try {
    $existingUsers = User::count();
} catch (Throwable) {
    exit("Could not read the users table. Have you run `php bin/migrate.php` yet?\n");
}

$username = option($argv, 'username') ?? prompt('Username: ');
$name     = option($argv, 'name')     ?? '';

// The first account has to be an administrator — there would be nobody to
// configure the integrations otherwise — so that is the default until one
// exists, and the flag is honoured after that.
$role = option($argv, 'role') ?? ($existingUsers === 0 ? 'admin' : 'reviewer');

if ($username === '') {
    exit("A username is required.\n");
}

if (!preg_match('/^[a-z0-9._-]{3,64}$/i', $username)) {
    exit("A username must be 3-64 characters, letters, numbers, dots, dashes or underscores.\n");
}

if (!in_array($role, Auth::ROLES, true)) {
    exit("Unknown role '{$role}'. One of: " . implode(', ', Auth::ROLES) . ".\n");
}

$existing = User::findByUsername($username);

if ($existing !== null) {
    echo "That username already exists.\n";
    $answer = prompt('Reset its password instead? [y/N]: ');

    if (strtolower($answer) !== 'y') {
        exit("Nothing changed.\n");
    }
}

echo PasswordPolicy::describe() . "\n";

$password = prompt('Password: ', true);
$confirm  = prompt('Confirm password: ', true);

if ($password !== $confirm) {
    exit("Those passwords do not match. Nothing changed.\n");
}

// The same policy the users screen applies, from the same place. Two copies of
// a password rule is one rule and one thing that used to be the rule.
$problems = PasswordPolicy::problems($password, [$username, $name, 'invogrid']);

if ($problems !== []) {
    foreach ($problems as $problem) {
        echo $problem . "\n";
    }

    exit("Nothing changed.\n");
}

if ($existing !== null) {
    User::setPassword((int) $existing['id'], $password);
    echo "\nPassword reset for '{$username}'.\n";
    exit(0);
}

$id = User::create($username, $password, $role, $name);

echo "\nCreated user '{$username}' (#{$id}) with the {$role} role.\n";

if ($existingUsers === 0) {
    echo "\nThis is the first account, so it is an administrator. Sign in and fill in\n";
    echo "Settings before pointing a Paperless workflow at the webhook receiver.\n";
}
