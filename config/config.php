<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Central configuration.
 *
 * Values come from the environment (.env), never from credentials hardcoded in
 * the repository. Anything an administrator changes day to day lives in the
 * `settings` table instead — see App\Models\Setting. Where both exist, a
 * non-empty setting wins and the value here is the fallback.
 */

$root = dirname(__DIR__);

$storage = Env::get('STORAGE_PATH', 'storage');
if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', (string) $storage)) {
    $storage = $root . DIRECTORY_SEPARATOR . $storage;
}

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'InvoGrid'),

        // Product branding, as distinct from `name` (what this instance calls
        // itself). `full_name` is the longer form used in the footer and on
        // printed output. `mark` is the two-letter fallback shown in the header
        // when no logo has been uploaded — a brand mark, not initials of `name`.
        'product'         => Env::get('APP_PRODUCT', 'InvoGrid'),
        'full_name'       => Env::get('APP_FULL_NAME', 'InvoGrid by Junction'),
        'product_tagline' => Env::get('APP_PRODUCT_TAGLINE', 'Purchase document automation'),
        'mark'            => Env::get('APP_MARK', 'IG'),
        'vendor'          => Env::get('APP_VENDOR', 'Junction Inc Ltd'),
        'vendor_url'      => Env::get('APP_VENDOR_URL', 'https://www.junctioninc.co.uk/'),

        'env'             => Env::get('APP_ENV', 'production'),
        'debug'           => Env::bool('APP_DEBUG', false),
        'url'             => rtrim((string) Env::get('APP_URL', ''), '/'),
        'timezone'        => Env::get('APP_TIMEZONE', 'Europe/London'),
        'currency'        => Env::get('APP_CURRENCY', 'GBP'),
        'currency_symbol' => Env::get('APP_CURRENCY_SYMBOL', '£'),
        'root'            => $root,

        // Encryption key for every secret stored in the database, and the
        // session secret. Generate with `php bin/console.php key:generate`.
        // App\Core\Crypto fails closed without it: a secret is refused rather
        // than written in the clear.
        'key'             => Env::get('APP_KEY', ''),
    ],

    'database' => [
        'host'     => Env::get('DB_HOST', '127.0.0.1'),
        'port'     => (int) Env::get('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', 'invogrid'),
        'username' => Env::get('DB_USERNAME', ''),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset'  => Env::get('DB_CHARSET', 'utf8mb4'),
    ],

    'session' => [
        'name'     => Env::get('SESSION_NAME', 'invogrid_session'),
        'lifetime' => (int) Env::get('SESSION_LIFETIME', 240), // minutes idle
        'samesite' => Env::get('SESSION_SAMESITE', 'Lax'),
    ],

    'security' => [
        'force_https' => Env::bool('FORCE_HTTPS', true),
        'trust_proxy' => Env::bool('TRUST_PROXY', true),
        'login' => [
            'max_attempts'    => (int) Env::get('LOGIN_MAX_ATTEMPTS', 5),
            'decay_minutes'   => (int) Env::get('LOGIN_DECAY_MINUTES', 15),
            'lockout_minutes' => (int) Env::get('LOGIN_LOCKOUT_MINUTES', 15),
        ],
        /*
         * Read only through App\Core\PasswordPolicy, which is what the command
         * line, the users screen and the change-password page all ask. A policy
         * written out in three places is a policy that drifts.
         */
        'password' => [
            'min_length'  => (int) Env::get('PASSWORD_MIN_LENGTH', 12),
            'min_classes' => (int) Env::get('PASSWORD_MIN_CLASSES', 3),
        ],
    ],

    'storage' => [
        'path'    => $storage,
        'uploads' => $storage . DIRECTORY_SEPARATOR . 'uploads',
        'logs'    => $storage . DIRECTORY_SEPARATOR . 'logs',
        // Source PDFs pulled from Paperless, and the page images rendered from
        // them for the vision OCR stage.
        'pdf'     => $storage . DIRECTORY_SEPARATOR . 'pdf',
        'pages'   => $storage . DIRECTORY_SEPARATOR . 'pages',
    ],

    /*
     * Integration fallbacks. The live values are settings rows; these are what
     * a site gets when the corresponding setting is empty, so an install can be
     * driven entirely from .env if it prefers to keep secrets out of the
     * database. See App\Models\Setting::secret() and ::value().
     */
    'integrations' => [
        'paperless' => [
            'base_url'       => rtrim((string) Env::get('PAPERLESS_BASE_URL', ''), '/'),
            'token'          => Env::get('PAPERLESS_TOKEN', ''),
            'webhook_secret' => Env::get('INVOGRID_WEBHOOK_SECRET', ''),
        ],
        'clearbooks' => [
            'base_url'      => rtrim((string) Env::get('CLEARBOOKS_BASE_URL', 'https://api.clearbooks.co.uk'), '/'),
            'client_id'     => Env::get('CLEARBOOKS_CLIENT_ID', ''),
            'client_secret' => Env::get('CLEARBOOKS_CLIENT_SECRET', ''),
            'business_id'   => Env::get('CLEARBOOKS_BUSINESS_ID', ''),
        ],
        'openai' => [
            'api_key' => Env::get('OPENAI_API_KEY', ''),
        ],
        'anthropic' => [
            'api_key' => Env::get('ANTHROPIC_API_KEY', ''),
        ],
    ],

    'pdf' => [
        // Blank means "find it on PATH". Only consulted when Imagick is absent.
        'pdftoppm' => Env::get('PDFTOPPM_PATH', ''),
    ],

    'uploads' => [
        /*
         * A ceiling on one PDF fetched from Paperless.
         *
         * Not an upload in the browser sense — nobody uploads a PDF to
         * InvoGrid, they arrive from Paperless over the API — but it is the
         * one thing a remote service can write to this disk, and the transfer
         * is aborted the moment it goes over. 100MB is far above any real
         * scanned invoice and far below "fills the volume".
         */
        'max_pdf_bytes' => (int) Env::get('MAX_PDF_BYTES', (string) (100 * 1024 * 1024)),

        // A logo is a logo, not a photograph.
        'max_logo_bytes' => 2 * 1024 * 1024,
        'logo_mimes'      => ['image/png', 'image/jpeg', 'image/webp'],
        'logo_extensions' => ['png', 'jpg', 'jpeg', 'webp'],
    ],
];
