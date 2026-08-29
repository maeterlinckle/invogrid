<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Capability and role gates. Both imply `auth`: somebody who is not signed in
 * is sent to sign in rather than told they lack a permission they could not
 * hold yet.
 */
final class CapabilityMiddleware
{
    public static function handle(string $capability): void
    {
        AuthMiddleware::handle();

        if (!Auth::can($capability)) {
            self::deny();
        }
    }

    /** @param array<int,string> $capabilities */
    public static function handleAny(array $capabilities): void
    {
        AuthMiddleware::handle();

        if (!Auth::canAny(...array_map('trim', $capabilities))) {
            self::deny();
        }
    }

    public static function handleRole(string $role): void
    {
        AuthMiddleware::handle();

        if (!Auth::atLeast(trim($role))) {
            self::deny();
        }
    }

    private static function deny(): never
    {
        if (Request::isAjax() || Request::isJson()) {
            Response::json(['error' => 'You do not have permission to do that.'], 403);
        }

        View::renderError(403, 'Not allowed', 'Your account does not have permission to do that. Ask an administrator if you think it should.');
        exit;
    }
}
