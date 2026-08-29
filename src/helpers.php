<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;

if (!function_exists('e')) {
    /** Escape for HTML output. Use on every dynamic value in a template. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** Build an application URL that respects a subdirectory install. */
    function url(string $path = '/'): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Request::basePath() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset_url')) {
    /** Static asset URL with a cache-busting stamp based on file mtime. */
    function asset_url(string $path): string
    {
        $relative = ltrim($path, '/');
        $file     = Config::get('app.root') . '/public/' . $relative;
        $version  = is_file($file) ? (string) filemtime($file) : '1';

        return url($relative) . '?v=' . $version;
    }
}

if (!function_exists('partial')) {
    /**
     * Render a template fragment from inside another template.
     *
     * @param array<string,mixed> $data
     */
    function partial(string $template, array $data = []): string
    {
        return View::partial($template, $data);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('can')) {
    function can(string $capability): bool
    {
        return Auth::can($capability);
    }
}

if (!function_exists('can_any')) {
    function can_any(string ...$capabilities): bool
    {
        return Auth::canAny(...$capabilities);
    }
}

if (!function_exists('role_at_least')) {
    /**
     * For the handful of places gated on seniority rather than on a capability
     * — the resubmit escape hatch, which is `role:admin` on the route and has
     * to be the same test in the markup.
     *
     * Still not a role-string comparison at the call site: `Auth::atLeast()` is
     * the one method that knows the ordering.
     */
    function role_at_least(string $role): bool
    {
        return Auth::atLeast($role);
    }
}

if (!function_exists('auth_user')) {
    /** @return array<string,mixed>|null */
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('old')) {
    /**
     * Repopulate a form field after a validation failure.
     *
     * @param array<string,mixed> $old
     */
    function old(array $old, string $key, mixed $default = ''): string
    {
        return (string) ($old[$key] ?? $default ?? '');
    }
}

if (!function_exists('active_path_score')) {
    /**
     * How well a link matches the current request: 0 for no match, otherwise
     * the length of the path that matched.
     *
     * The prefix rule is what makes "Documents" light up while you are on
     * /documents/12. It also means a menu whose items nest under one another
     * matches more than one of them, so the score — rather than a boolean —
     * lets a group of sibling links pick the most specific winner.
     */
    function active_path_score(string $path): int
    {
        $current = Request::path();
        $path    = '/' . trim($path, '/');

        if ($path === '/') {
            return $current === '/' ? 1 : 0;
        }

        if ($current === $path || str_starts_with($current, $path . '/')) {
            return strlen($path);
        }

        return 0;
    }
}

if (!function_exists('is_active_path')) {
    /** True when the current request path is (or is under) the given path. */
    function is_active_path(string $path): bool
    {
        return active_path_score($path) > 0;
    }
}

if (!function_exists('active_path')) {
    /**
     * Of several candidate paths, the one the current request is really on.
     *
     * @param array<int,string> $paths
     */
    function active_path(array $paths): ?string
    {
        $best  = null;
        $score = 0;

        foreach ($paths as $path) {
            $candidate = active_path_score($path);

            if ($candidate > $score) {
                $score = $candidate;
                $best  = $path;
            }
        }

        return $best;
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'j M Y'): string
    {
        if ($date === null || $date === '' || str_starts_with($date, '0000')) {
            return '—';
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? '—' : date($format, $timestamp);
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $date): string
    {
        return format_date($date, 'j M Y, H:i');
    }
}

if (!function_exists('format_money')) {
    /**
     * A money value with its own currency symbol.
     *
     * Purchase documents arrive in whatever currency the supplier bills in, so
     * the symbol is per-value rather than a single application-wide setting.
     */
    function format_money(mixed $amount, ?string $currency = null): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $currency = strtoupper((string) ($currency ?? Config::get('app.currency', 'GBP')));
        $symbols  = ['GBP' => '£', 'EUR' => '€', 'USD' => '$'];
        $symbol   = $symbols[$currency] ?? ($currency . ' ');

        return $symbol . number_format((float) $amount, 2);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('str_limit')) {
    function str_limit(?string $value, int $length = 80): string
    {
        $value = (string) $value;

        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1) . '…';
    }
}
