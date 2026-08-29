<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,string> */
    private static array $routeParams = [];

    /** @var array<string,mixed>|null Memoised JSON body — php://input reads once. */
    private static ?array $json = null;

    public static function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Browsers only send GET/POST, so honour a _method override on POST.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    /**
     * One request header, by its human name ("Authorization", "X-API-Key").
     *
     * `getallheaders()` is not available on every SAPI — notably not on some
     * FastCGI setups — so the `$_SERVER` fallback is the one that actually
     * runs in production more often than not.
     */
    public static function header(string $name): ?string
    {
        $normalised = strtoupper(str_replace('-', '_', trim($name)));

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $key => $value) {
                if (strtoupper(str_replace('-', '_', (string) $key)) === $normalised) {
                    return (string) $value;
                }
            }
        }

        foreach (['HTTP_' . $normalised, $normalised] as $key) {
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                return (string) $_SERVER[$key];
            }
        }

        // Apache with mod_php strips Authorization from the CGI environment
        // unless it is explicitly passed through; this is where it lands when
        // the rewrite in .htaccess forwards it.
        if ($normalised === 'AUTHORIZATION' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return null;
    }

    public static function isJson(): bool
    {
        return str_contains(strtolower((string) self::header('Content-Type')), 'application/json');
    }

    /**
     * The request body decoded as JSON.
     *
     * Read once and kept: `php://input` is a stream that cannot be rewound on
     * every SAPI, so a second read can come back empty.
     *
     * @return array<string,mixed>
     * @throws \JsonException when the body is present but not valid JSON
     */
    public static function json(): array
    {
        if (self::$json !== null) {
            return self::$json;
        }

        $raw = (string) file_get_contents('php://input');

        if (trim($raw) === '') {
            return self::$json = [];
        }

        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);

        return self::$json = is_array($decoded) ? $decoded : [];
    }

    public static function path(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        // Support installation in a subdirectory.
        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** Directory the front controller lives in, e.g. '' or '/assets'. */
    public static function basePath(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir    = str_replace('\\', '/', dirname($script));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    public static function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((bool) Config::get('security.trust_proxy', true)) {
            $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            if (strtolower((string) $proto) === 'https') {
                return true;
            }
            if (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
                return true;
            }
        }

        return ((int) ($_SERVER['SERVER_PORT'] ?? 80)) === 443;
    }

    public static function host(): string
    {
        return (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    }

    public static function fullUrl(): string
    {
        return (self::isSecure() ? 'https://' : 'http://') . self::host() . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    public static function ip(): string
    {
        if ((bool) Config::get('security.trust_proxy', true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip    = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function isAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        $value = $_GET[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public static function boolean(string $key): bool
    {
        $value = self::input($key);

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    /** @param array<string,string> $params */
    public static function setRouteParams(array $params): void
    {
        self::$routeParams = $params;
    }

    public static function route(string $key, ?string $default = null): ?string
    {
        return self::$routeParams[$key] ?? $default;
    }

    public static function routeInt(string $key): int
    {
        return (int) (self::$routeParams[$key] ?? 0);
    }
}
