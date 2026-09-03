<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Authenticated symmetric encryption for the few secrets that have to live in
 * the database rather than in .env.
 *
 * Here that is every integration credential: the Clear Books OAuth2 client
 * secret and its access/refresh tokens, and the OpenAI and Anthropic API keys.
 * An administrator has to be
 * able to rotate them from the Settings page without shell access, so they
 * cannot be .env-only — but a key sitting in a `settings` row in the clear
 * would be readable by anyone with a database backup, and those get emailed
 * about, copied to laptops and left on USB sticks.
 *
 * AES-256-GCM, so a tampered ciphertext fails to decrypt rather than quietly
 * producing rubbish. The key is APP_KEY in .env, which means the encrypted
 * value is worthless on its own: restoring a database dump onto a machine
 * without the matching .env gives you credentials you cannot read.
 *
 * Format of an encrypted value: `v1.` + base64( iv[12] | tag[16] | ciphertext ).
 * The version prefix is there so a future algorithm change can recognise and
 * migrate old values instead of guessing.
 */
final class Crypto
{
    private const CIPHER     = 'aes-256-gcm';
    private const PREFIX     = 'v1.';
    private const IV_BYTES   = 12;
    private const TAG_BYTES  = 16;
    private const KEY_BYTES  = 32;

    /** Is the openssl extension present? */
    public static function isAvailable(): bool
    {
        return extension_loaded('openssl') && function_exists('openssl_encrypt');
    }

    /** Is a usable APP_KEY configured? */
    public static function hasKey(): bool
    {
        return self::key() !== null;
    }

    /** A fresh key, in the form that belongs in .env. */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(self::KEY_BYTES));
    }

    /** True when a stored value looks like something this class produced. */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Encrypt a value. Returns null when encryption is not possible, so the
     * caller can refuse to store the secret rather than store it in the clear —
     * failing closed is the whole point.
     */
    public static function encrypt(string $plaintext): ?string
    {
        $key = self::key();

        if ($key === null || !self::isAvailable()) {
            return null;
        }

        $iv  = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);

        if ($ciphertext === false) {
            return null;
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value produced by encrypt().
     *
     * Returns null for anything that will not decrypt — wrong key, truncated
     * value, tampered ciphertext, or openssl missing. A caller that gets null
     * should behave as though the secret were not set.
     */
    public static function decrypt(string $value): ?string
    {
        $key = self::key();

        if ($key === null || !self::isAvailable() || !self::isEncrypted($value)) {
            return null;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        // An empty plaintext encrypts to iv|tag and nothing else, so the
        // boundary is "shorter than the header", not "no longer than it".
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }

        $iv         = substr($raw, 0, self::IV_BYTES);
        $tag        = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * The raw 32-byte key, or null when APP_KEY is missing or malformed.
     *
     * Accepts `base64:…` (what generateKey() emits and what the installer
     * writes), bare base64, or 64 hex characters — because someone will
     * generate one by hand with `openssl rand -hex 32` and be surprised if it
     * is rejected.
     */
    private static function key(): ?string
    {
        $configured = (string) Config::get('app.key', '');

        if ($configured === '') {
            return null;
        }

        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);

            return ($decoded !== false && strlen($decoded) === self::KEY_BYTES) ? $decoded : null;
        }

        if (strlen($configured) === self::KEY_BYTES * 2 && ctype_xdigit($configured)) {
            $decoded = hex2bin($configured);

            return $decoded === false ? null : $decoded;
        }

        $decoded = base64_decode($configured, true);
        if ($decoded !== false && strlen($decoded) === self::KEY_BYTES) {
            return $decoded;
        }

        // A plain passphrase would otherwise be silently accepted at whatever
        // length it happens to be. Hash it to the right size so a hand-edited
        // .env still works, rather than leaving every integration mysteriously
        // unconfigured.
        return hash('sha256', $configured, true);
    }
}
