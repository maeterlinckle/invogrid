<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;

/**
 * Key/value application settings, cached for the life of the request.
 *
 * Two kinds of value live here. An ordinary one is stored and read as typed. A
 * secret one — every integration credential — is encrypted with APP_KEY before
 * it is written and decrypted on the way out, so a database dump on its own
 * gives up nothing. secret() is the only way to read one back, it is only ever
 * called from PHP, and nothing in a template may print its result.
 *
 * Where a setting is empty, the matching value from config/config.php (which is
 * to say from .env) is used instead. That lets a site keep its credentials out
 * of the database entirely without the application caring which it got.
 */
final class Setting
{
    /** @var array<string,array{value:?string,secret:bool}>|null */
    private static ?array $cache = null;

    /**
     * The .env fallback for a setting, by key. A key absent from this map has
     * no fallback and simply reads as empty until somebody fills it in.
     *
     * @var array<string,string>
     */
    private const ENV_FALLBACK = [
        'clearbooks_base_url'      => 'integrations.clearbooks.base_url',
        'clearbooks_client_id'     => 'integrations.clearbooks.client_id',
        'clearbooks_client_secret' => 'integrations.clearbooks.client_secret',
        'clearbooks_business_id'   => 'integrations.clearbooks.business_id',
        'openai_api_key'           => 'integrations.openai.api_key',
        'anthropic_api_key'        => 'integrations.anthropic.api_key',
    ];

    /** A plain (non-secret) setting. */
    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();

        $row = self::$cache[$key] ?? null;

        if ($row !== null && $row['secret']) {
            // Reading a secret through get() would hand a raw ciphertext to
            // whatever asked, which is never what the caller meant.
            return self::secret($key) ?? $default;
        }

        $value = $row['value'] ?? null;

        if ($value === null || $value === '') {
            return self::fallback($key) ?? $default;
        }

        return $value;
    }

    /**
     * A secret setting, decrypted.
     *
     * Returns null when it is unset, or when it cannot be decrypted — a missing
     * or changed APP_KEY. A caller that gets null must behave as though the
     * credential were not configured; it must not fall back to the ciphertext.
     */
    public static function secret(string $key): ?string
    {
        self::load();

        $stored = self::$cache[$key]['value'] ?? null;

        if ($stored === null || $stored === '') {
            return self::fallback($key);
        }

        if (Crypto::isEncrypted($stored)) {
            return Crypto::decrypt($stored) ?? self::fallback($key);
        }

        // Not encrypted: either APP_KEY was missing when it was saved, or the
        // row was edited by hand. Usable, but it should be re-saved.
        return $stored;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return ($value === null || !is_numeric($value)) ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The value actually stored in the row, ignoring the .env fallback.
     *
     * The Settings screen needs this and `get()` will not do: `get()` answers
     * "what does the application use", which for an empty row is the .env
     * value. Putting that into the form's input would copy .env into the
     * database the moment somebody pressed Save on an unrelated field, and the
     * fallback would stop being a fallback without anyone deciding that.
     *
     * Never returns a secret. A screen has no business holding one, which is
     * why the form treats an empty box as "leave this one alone".
     */
    public static function stored(string $key): ?string
    {
        self::load();

        if (self::isSecret($key)) {
            return null;
        }

        return self::$cache[$key]['value'] ?? null;
    }

    /** Is there an .env fallback for this key at all, filled in or not? */
    public static function hasEnvFallback(string $key): bool
    {
        return array_key_exists($key, self::ENV_FALLBACK);
    }

    /**
     * The .env fallback in force for a key, for the hint under an empty field.
     *
     * Null for a secret even when one is present: that a fallback exists is
     * worth showing, what it is never is.
     */
    public static function envFallbackValue(string $key): ?string
    {
        if (self::isSecret($key)) {
            return null;
        }

        return self::fallback($key);
    }

    public static function isSecret(string $key): bool
    {
        self::load();

        return (bool) (self::$cache[$key]['secret'] ?? false);
    }

    /** Is a value actually present, from either the database or .env? */
    public static function isConfigured(string $key): bool
    {
        $value = self::isSecret($key) ? self::secret($key) : self::get($key);

        return $value !== null && $value !== '';
    }

    /**
     * Write a setting.
     *
     * A secret with no usable APP_KEY is refused rather than written in the
     * clear: failing closed is the whole point of encrypting it. The caller is
     * told so it can put the reason in front of the administrator.
     */
    public static function put(string $key, ?string $value, ?bool $secret = null): bool
    {
        self::load();

        $isSecret = $secret ?? self::isSecret($key);
        $stored   = $value;

        if ($isSecret && $value !== null && $value !== '') {
            $stored = Crypto::encrypt($value);

            if ($stored === null) {
                return false;
            }
        }

        Database::run(
            'INSERT INTO settings (setting_key, setting_value, is_secret, updated_by)
                  VALUES (:k, :v, :s, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     is_secret     = VALUES(is_secret),
                                     updated_by    = VALUES(updated_by)',
            [
                'k' => $key,
                'v' => $stored,
                's' => $isSecret ? 1 : 0,
                'u' => Auth::id(),
            ]
        );

        self::flush();

        return true;
    }

    /**
     * Every setting, for the Settings screen.
     *
     * A secret comes back as a flag saying whether it is set rather than as a
     * value: the screen shows that a credential is present, never what it is.
     *
     * @return array<string,array{value:?string,secret:bool,configured:bool}>
     */
    public static function summary(): array
    {
        self::load();

        $out = [];
        foreach (self::$cache ?? [] as $key => $row) {
            $out[$key] = [
                'value'      => $row['secret'] ? null : self::get($key),
                'secret'     => $row['secret'],
                'configured' => self::isConfigured($key),
            ];
        }

        return $out;
    }

    /**
     * Forget the cached settings so the next read goes to the database.
     *
     * The cache is per-request, which is right for a request and wrong for the
     * pipeline worker, which is long-lived and has to notice that a credential
     * changed in the browser.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function fallback(string $key): ?string
    {
        $path = self::ENV_FALLBACK[$key] ?? null;

        if ($path === null) {
            return null;
        }

        $value = (string) Config::get($path, '');

        return $value === '' ? null : $value;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        foreach (Database::select('SELECT setting_key, setting_value, is_secret FROM settings') as $row) {
            self::$cache[(string) $row['setting_key']] = [
                'value'  => $row['setting_value'] === null ? null : (string) $row['setting_value'],
                'secret' => (bool) $row['is_secret'],
            ];
        }
    }
}
