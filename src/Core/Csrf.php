<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const KEY = '__csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put(self::KEY, $token);
        }

        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function isValid(?string $token): bool
    {
        $expected = Session::get(self::KEY);

        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    /**
     * Verify the token on any state-changing request. Aborts with 419 when the
     * token is missing or wrong.
     */
    public static function verify(): void
    {
        if (in_array(Request::method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!self::isValid(is_string($token) ? $token : null)) {
            if (Request::isAjax()) {
                Response::json(['error' => 'Your session has expired. Please refresh the page.'], 419);
            }

            http_response_code(419);
            View::renderError(419, 'Session expired', 'Your session expired or the form was submitted twice. Please go back and try again.');
            exit;
        }
    }

    public static function rotate(): void
    {
        Session::forget(self::KEY);
        self::token();
    }
}
