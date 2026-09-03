<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Authentication, and the read side of access control.
 *
 * Roles are a simple ordered enum for now — viewer < reviewer < admin — and
 * every check goes through `can()` rather than comparing role strings at the
 * call site. When the full permission model arrives, `can()` is the one method
 * that has to learn about it and nothing else in the application changes.
 */
final class Auth
{
    private const SESSION_KEY = '__auth_user_id';

    /**
     * Roles in increasing order of authority.
     *
     * viewer   — read the queue and the documents; changes nothing.
     * reviewer — resolves a review, creates entities in Clear Books, submits.
     * admin    — the above, plus settings, prompts, custom fields and users.
     */
    public const ROLES = ['viewer', 'reviewer', 'admin'];

    /**
     * What each role is allowed to do.
     *
     * A capability held by a lesser role is held by a greater one, so the list
     * is cumulative and `can()` walks up from the user's own role.
     */
    private const CAPABILITIES = [
        'viewer' => [
            'documents.view',
            'queue.view',
        ],
        'reviewer' => [
            // Putting a document into the pipeline spends money — every page
            // goes to a vision model and the extraction runs three more calls
            // — so it sits with `documents.retry` rather than with viewing. A
            // viewer can read everything and start nothing.
            'documents.upload',
            'documents.retry',
            'review.resolve',
            'entities.create',
            'documents.submit',

            /*
             * Deleting a document outright, which is the only irreversible
             * destruction in this application — the row, the transcription and
             * the stored PDF all go, and `ignored` is what everything else
             * means by "take this out of the way".
             *
             * Its own capability rather than folded into `review.resolve`,
             * because it is a different kind of act and somebody may well want
             * to move it to `admin`: that is one line here and nothing else.
             *
             * A reviewer holds it because the Existing Invoice queue is a
             * reviewer's screen and it is one of the three answers that queue
             * offers — a queue with a resolution its own audience cannot reach
             * is a queue that stops being worked. The controls on it are the
             * required reason and the audit row, which outlives the document by
             * design.
             */
            'documents.delete',
        ],
        'admin' => [
            'settings.manage',
            'prompts.manage',
            'fields.manage',
            'users.manage',
            'audit.view',
        ],
    ];

    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = Session::get(self::SESSION_KEY);
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }

        $user = User::findActive((int) $id);
        if ($user === null) {
            // Deactivated or deleted mid-session: drop the session rather than
            // leave a signed-in shell of an account walking around.
            self::forgetSession();

            return null;
        }

        return self::$user = $user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function username(): string
    {
        return (string) (self::user()['username'] ?? 'guest');
    }

    public static function displayName(): string
    {
        $user = self::user();

        if ($user === null) {
            return 'Guest';
        }

        $name = trim((string) ($user['display_name'] ?? ''));

        return $name !== '' ? $name : (string) $user['username'];
    }

    public static function role(): ?string
    {
        $user = self::user();

        return $user === null ? null : (string) $user['role'];
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /**
     * Has this account been handed a password somebody else chose?
     *
     * Set whenever an administrator creates an account or resets a password.
     * `AuthMiddleware` reads it on every request and lets the account do
     * nothing but choose its own until it has.
     */
    public static function mustChangePassword(): bool
    {
        $user = self::user();

        return $user !== null && (int) ($user['must_change_password'] ?? 0) === 1;
    }

    /**
     * Every role with everything it can do, cumulative, in role order.
     *
     * Built from the same constants `can()` reads, so the users screen cannot
     * describe a permission model the application does not actually enforce.
     * A table of capabilities maintained by hand in a template would be wrong
     * within one release.
     *
     * @return array<string,array<int,string>>
     */
    public static function capabilityMap(): array
    {
        $map        = [];
        $cumulative = [];

        foreach (self::ROLES as $role) {
            $cumulative = array_merge($cumulative, self::CAPABILITIES[$role] ?? []);
            $map[$role] = $cumulative;
        }

        return $map;
    }

    /** What a role adds over the one below it. */
    public static function capabilitiesAddedBy(string $role): array
    {
        return self::CAPABILITIES[$role] ?? [];
    }

    /** Does the signed-in user hold this capability? */
    public static function can(string $capability): bool
    {
        $role = self::role();

        if ($role === null) {
            return false;
        }

        $rank = array_search($role, self::ROLES, true);
        if ($rank === false) {
            return false;
        }

        // Cumulative: everything this role's own list holds, plus everything
        // below it.
        for ($i = 0; $i <= $rank; $i++) {
            if (in_array($capability, self::CAPABILITIES[self::ROLES[$i]] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public static function canAny(string ...$capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (self::can($capability)) {
                return true;
            }
        }

        return false;
    }

    /** Is this role at least as senior as the one named? */
    public static function atLeast(string $role): bool
    {
        $own = self::role();

        if ($own === null) {
            return false;
        }

        $ownRank      = array_search($own, self::ROLES, true);
        $requiredRank = array_search($role, self::ROLES, true);

        return $ownRank !== false && $requiredRank !== false && $ownRank >= $requiredRank;
    }

    /**
     * Attempt a sign-in. Returns an error message on failure, null on success.
     *
     * The message is deliberately the same whether the account is unknown or
     * the password is wrong: a login form that distinguishes the two is a list
     * of valid usernames.
     */
    public static function attempt(string $username, string $password): ?string
    {
        $ip       = Request::ip();
        $username = mb_strtolower(trim($username));
        $lockout  = (int) Config::get('security.login.lockout_minutes', 15);

        if (LoginThrottle::isLocked($username, $ip)) {
            LoginThrottle::record($username, $ip, false);

            return "Too many failed sign-in attempts. Please try again in {$lockout} minutes.";
        }

        $user = User::findByUsername($username);

        // Always run a hash comparison, even for an unknown account, so the
        // response time does not reveal whether it exists.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $ok   = password_verify($password, (string) $hash);

        if (!$ok || $user === null) {
            LoginThrottle::record($username, $ip, false);

            return 'Those sign-in details were not recognised.';
        }

        if ((int) $user['active'] !== 1) {
            LoginThrottle::record($username, $ip, false);

            return 'That account has been deactivated. Please contact an administrator.';
        }

        // Re-hashing the same password is housekeeping, not a password change.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            User::setPassword((int) $user['id'], $password);
        }

        LoginThrottle::record($username, $ip, true);
        LoginThrottle::clear($username, $ip);

        self::login((int) $user['id']);

        User::touchLogin((int) $user['id'], $ip);
        AuditLog::record('auth.login', null, 'Signed in');

        return null;
    }

    public static function login(int $userId): void
    {
        // A new session id on privilege change, so a fixated one is worthless.
        Session::regenerate();
        Session::put(self::SESSION_KEY, $userId);
        Csrf::rotate();

        self::$user = null;
    }

    public static function logout(): void
    {
        if (self::check()) {
            AuditLog::record('auth.logout', null, 'Signed out');
        }

        self::forgetSession();
        Session::destroy();
    }

    private static function forgetSession(): void
    {
        Session::forget(self::SESSION_KEY);
        self::$user = null;
    }
}
