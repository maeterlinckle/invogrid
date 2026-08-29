<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Failed-sign-in throttling, keyed on both the username and the client address
 * so neither a single account nor a single source can be hammered.
 *
 * The username is lower-cased everywhere here, including on the way in to
 * record(): a counter that treats "Nick" and "nick" as different accounts is
 * a counter that can be stepped around by pressing shift.
 */
final class LoginThrottle
{
    public static function record(string $username, string $ip, bool $successful): void
    {
        Database::insert('login_attempts', [
            'username'     => self::key($username),
            'ip_address'   => $ip,
            'successful'   => $successful ? 1 : 0,
            'user_agent'   => Request::userAgent(),
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);

        // Opportunistic cleanup so the table cannot grow forever. One run in
        // fifty is often enough for a table nobody reads in bulk.
        if (random_int(1, 50) === 1) {
            Database::run('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)');
        }
    }

    public static function isLocked(string $username, string $ip): bool
    {
        $max   = (int) Config::get('security.login.max_attempts', 5);
        $since = self::since();

        $byUsername = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE username = ? AND successful = 0 AND attempted_at >= ?',
            [self::key($username), $since]
        );

        if ($byUsername >= $max) {
            return true;
        }

        // A wider net for the address: several accounts probed from one source.
        $byIp = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = ? AND successful = 0 AND attempted_at >= ?',
            [$ip, $since]
        );

        return $byIp >= ($max * 3);
    }

    public static function clear(string $username, string $ip): void
    {
        Database::run(
            'DELETE FROM login_attempts WHERE successful = 0 AND (username = ? OR ip_address = ?)',
            [self::key($username), $ip]
        );
    }

    /** Attempts left before lockout, for a friendlier warning message. */
    public static function remaining(string $username, string $ip): int
    {
        $max = (int) Config::get('security.login.max_attempts', 5);

        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE username = ? AND successful = 0 AND attempted_at >= ?',
            [self::key($username), self::since()]
        );

        return max(0, $max - $count);
    }

    private static function key(string $username): string
    {
        return mb_substr(mb_strtolower(trim($username)), 0, 190);
    }

    /** The start of the window failed attempts are counted over. */
    private static function since(): string
    {
        $decay = (int) Config::get('security.login.decay_minutes', 15);

        return date('Y-m-d H:i:s', time() - ($decay * 60));
    }
}
