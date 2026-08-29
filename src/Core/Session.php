<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetimeMinutes = (int) Config::get('session.lifetime', 480);

        session_name((string) Config::get('session.name', 'invogrid_session'));
        session_set_cookie_params([
            'lifetime' => 0,                       // session cookie; idle timeout enforced below
            'path'     => '/',
            'domain'   => '',
            'secure'   => Request::isSecure() || (bool) Config::get('security.force_https', true),
            'httponly' => true,
            'samesite' => (string) Config::get('session.samesite', 'Lax'),
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) ($lifetimeMinutes * 60));

        session_start();

        // Idle timeout.
        $now = time();
        if (isset($_SESSION['__last_activity']) && ($now - (int) $_SESSION['__last_activity']) > $lifetimeMinutes * 60) {
            self::destroy();
            session_start();
            $_SESSION['__expired'] = true;
        }
        $_SESSION['__last_activity'] = $now;

        // Bind the session to the user agent to make cookie theft less useful.
        // Keyed on APP_KEY so the stored fingerprint cannot be reproduced from
        // the request alone — that is the "session secret" the .env speaks of.
        $fingerprint = hash_hmac(
            'sha256',
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            (string) Config::get('app.key', 'invogrid')
        );
        if (!isset($_SESSION['__fingerprint'])) {
            $_SESSION['__fingerprint'] = $fingerprint;
        } elseif (!hash_equals((string) $_SESSION['__fingerprint'], $fingerprint)) {
            self::destroy();
            session_start();
            $_SESSION['__fingerprint'] = $fingerprint;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }
}
