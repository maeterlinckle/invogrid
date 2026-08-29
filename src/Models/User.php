<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/**
 * Application accounts.
 *
 * Sign-in is by username rather than email: this is an internal tool on a
 * private network, and the people using it already have a short name they are
 * known by. Email is optional and kept only so a person can be contacted about
 * a document.
 *
 * There is no self-service sign-up. Accounts are made by an administrator on
 * the users screen, or by `bin/create-admin.php` for the very first one — a
 * registration form on a server reachable before anybody has configured it is
 * a hole, not a convenience.
 */
final class User
{
    /**
     * Every column except the hash.
     *
     * `findByUsername()` is the one reader that needs `password_hash`, because
     * it is the one that verifies a password. Everything else — the list, the
     * form, the signed-in user carried around in `Auth` and handed to every
     * template — reads this instead, so a hash cannot reach a page by sitting
     * in an array somebody forgot to unset.
     */
    private const COLUMNS = 'id, username, display_name, email, role, active,
        must_change_password, password_changed_at, created_at, updated_at,
        last_login_at, last_login_ip';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findActive(int $id): ?array
    {
        return Database::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = ? AND active = 1',
            [$id]
        );
    }

    /**
     * The one reader that returns the hash, because it is the one that checks
     * a password. Do not use it to render anything.
     *
     * @return array<string,mixed>|null
     */
    public static function findByUsername(string $username): ?array
    {
        return Database::selectOne(
            'SELECT * FROM users WHERE username = ?',
            [mb_strtolower(trim($username))]
        );
    }

    public static function usernameExists(string $username, int $ignoreId = 0): bool
    {
        $sql    = 'SELECT COUNT(*) FROM users WHERE username = ?';
        $params = [mb_strtolower(trim($username))];

        if ($ignoreId > 0) {
            $sql     .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select(
            'SELECT ' . self::COLUMNS . ' FROM users ORDER BY active DESC, username'
        );
    }

    public static function count(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM users');
    }

    /**
     * How many active administrators there are, optionally ignoring one.
     *
     * Asked before every change that could reduce the number, because an
     * application with no administrator cannot be administered back into
     * having one — that takes shell access to the server.
     */
    public static function activeAdmins(int $excludingId = 0): int
    {
        $sql    = "SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1";
        $params = [];

        if ($excludingId > 0) {
            $sql     .= ' AND id <> ?';
            $params[] = $excludingId;
        }

        return (int) Database::scalar($sql, $params);
    }

    /**
     * Create an account. The password is hashed here so no caller ever holds a
     * hash, or is tempted to make one itself.
     */
    public static function create(
        string $username,
        string $password,
        string $role = 'reviewer',
        string $displayName = '',
        ?string $email = null,
        bool $mustChangePassword = false
    ): int {
        $username = mb_strtolower(trim($username));

        if (!self::validUsername($username)) {
            throw new RuntimeException(
                'A username must be 3-64 characters: letters, numbers, dots, dashes or underscores.'
            );
        }

        if (self::usernameExists($username)) {
            throw new RuntimeException('That username is already in use.');
        }

        return Database::insert('users', [
            'username'             => $username,
            'display_name'         => trim($displayName) !== '' ? trim($displayName) : $username,
            'email'                => ($email !== null && trim($email) !== '') ? trim($email) : null,
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'role'                 => in_array($role, Auth::ROLES, true) ? $role : 'reviewer',
            'active'               => 1,
            'must_change_password' => $mustChangePassword ? 1 : 0,
            'password_changed_at'  => $mustChangePassword ? null : date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Change the details of an account. Returns the names of what changed, so
     * the audit entry can say what happened rather than "was edited".
     *
     * The username is deliberately not among them: it is what the audit log,
     * the login throttle and every "resolved by" line refer to, and renaming
     * one makes a year of history point at somebody who no longer exists under
     * that name. Deactivate and create another instead.
     *
     * @param  array<string,mixed> $input
     * @return array<int,string>   The columns that actually changed
     */
    public static function update(int $id, array $input): array
    {
        $user = self::find($id);

        if ($user === null) {
            throw new RuntimeException('No such user.');
        }

        if (array_key_exists('username', $input)
            && mb_strtolower(trim((string) $input['username'])) !== (string) $user['username']) {
            throw new RuntimeException(
                'A username cannot be changed — the audit log refers to it. '
                    . 'Deactivate this account and create another instead.'
            );
        }

        $role = (string) ($input['role'] ?? $user['role']);

        if (!in_array($role, Auth::ROLES, true)) {
            throw new RuntimeException('Unknown role.');
        }

        $email  = trim((string) ($input['email'] ?? ''));
        $fields = [
            'display_name' => trim((string) ($input['display_name'] ?? '')) !== ''
                ? trim((string) $input['display_name'])
                : (string) $user['username'],
            'email'  => $email !== '' ? $email : null,
            'role'   => $role,
            'active' => !empty($input['active']) ? 1 : 0,
        ];

        self::guardLastAdmin($user, $fields['role'], (int) $fields['active']);

        $changed = [];

        foreach ($fields as $column => $value) {
            if ((string) ($user[$column] ?? '') !== (string) $value) {
                $changed[] = $column;
            }
        }

        if ($changed === []) {
            return [];
        }

        Database::update('users', $fields, $id);

        return $changed;
    }

    /**
     * Set a password.
     *
     * `$mustChange` is true whenever somebody other than the account's owner
     * set it. An administrator who resets a password knows it until it is
     * changed; the flag is what makes that a moment rather than a state.
     */
    public static function setPassword(int $id, string $password, bool $mustChange = false): void
    {
        Database::update('users', [
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => $mustChange ? 1 : 0,
            'password_changed_at'  => $mustChange ? null : date('Y-m-d H:i:s'),
        ], $id);
    }

    /** Take an account out of use, or put it back. */
    public static function setActive(int $id, bool $active): void
    {
        $user = self::find($id);

        if ($user === null) {
            throw new RuntimeException('No such user.');
        }

        self::guardLastAdmin($user, (string) $user['role'], $active ? 1 : 0);

        Database::update('users', ['active' => $active ? 1 : 0], $id);
    }

    public static function touchLogin(int $id, string $ip): void
    {
        Database::update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ], $id);
    }

    public static function validUsername(string $username): bool
    {
        return preg_match('/^[a-z0-9._-]{3,64}$/i', $username) === 1;
    }

    /**
     * Refuse a change that would leave nobody able to administer the site.
     *
     * In the model rather than the controller because there are two ways in —
     * editing an account and toggling one — and a rule enforced on one path
     * only is a rule with a way around it.
     *
     * @param array<string,mixed> $user
     */
    private static function guardLastAdmin(array $user, string $newRole, int $newActive): void
    {
        $wasAdmin = (string) $user['role'] === 'admin' && (int) $user['active'] === 1;
        $isAdmin  = $newRole === 'admin' && $newActive === 1;

        if (!$wasAdmin || $isAdmin) {
            return;
        }

        if (self::activeAdmins((int) $user['id']) === 0) {
            throw new RuntimeException(
                'This is the only active administrator. Give somebody else the administrator '
                    . 'role first — an application with no administrator can only be rescued '
                    . 'from the server itself.'
            );
        }
    }
}
