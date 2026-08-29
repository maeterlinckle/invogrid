<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function redirect(string $path, int $status = 302): never
    {
        $location = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : Request::basePath() . '/' . ltrim($path, '/');

        header('Location: ' . $location, true, $status);
        exit;
    }

    public static function back(string $fallback = '/'): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host    = Request::host();

        if ($referer !== '') {
            $parsed = parse_url($referer);
            // Only follow the referer when it points at this host.
            if (isset($parsed['host']) && strcasecmp($parsed['host'], parse_url('http://' . $host, PHP_URL_HOST) ?: $host) === 0) {
                self::redirect($referer);
            }
        }

        self::redirect($fallback);
    }

    /** @param array<string,mixed>|array<int,mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), payment=()');
        header_remove('X-Powered-By');

        // Everything is served from this origin. object-src and frame-src are
        // open to self because the review screen puts the source PDF in an
        // <object> beside the extracted data — the browser's own viewer, from
        // our own /documents/{id}/pdf route, never a third-party script.
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "img-src 'self' data: blob:; "
            . "object-src 'self'; "
            . "frame-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "font-src 'self'; "
            . "connect-src 'self'; "
            . "frame-ancestors 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );

        if (Request::isSecure()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}
