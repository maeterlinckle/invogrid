<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthMiddleware
{
    /**
     * The only pages an account owing a password change may still reach.
     *
     * Signing out has to stay possible — trapping somebody on one page with no
     * way off it is how a person ends up abandoning a browser session on a
     * shared machine — and the change page itself obviously has to be reachable.
     */
    private const WHILE_PASSWORD_OWED = ['/account/password', '/logout'];

    public static function handle(): void
    {
        if (Auth::check()) {
            self::forcePasswordChange();

            return;
        }

        if (Request::isAjax() || Request::isJson()) {
            Response::json(['error' => 'Not signed in.'], 401);
        }

        // Remember where they were headed so the sign-in can send them back.
        if (Request::method() === 'GET') {
            Session::put('__intended_url', Request::path());
        }

        Response::redirect('/login');
    }

    /**
     * An account whose password was set by somebody else can sign in, and can
     * then do exactly one thing.
     *
     * Enforced here rather than on the routes it protects, because "everything
     * except two paths" is a list nobody remembers to add to. Every signed-in
     * route runs this — `can`, `canany` and `role` all call `handle()` first —
     * so a route added next year is covered without being told about it.
     */
    private static function forcePasswordChange(): void
    {
        if (!Auth::mustChangePassword()) {
            return;
        }

        $path = Request::path();

        if (in_array($path, self::WHILE_PASSWORD_OWED, true)) {
            return;
        }

        if (Request::isAjax() || Request::isJson()) {
            Response::json(['error' => 'You must choose a new password before continuing.'], 403);
        }

        if (Request::method() === 'GET') {
            Session::put('__intended_url', $path);
        }

        Flash::warning('Your password was set by an administrator. Choose your own to carry on.');

        Response::redirect('/account/password');
    }
}
