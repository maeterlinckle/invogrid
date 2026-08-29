<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        // Set by Session::start() when the idle timeout fired, so the page can
        // say why the user is looking at it again.
        $expired = (bool) Session::pull('__expired', false);

        $this->view('auth/login', [
            'pageTitle' => 'Sign in',
            'expired'   => $expired,
        ], 'layouts/auth');
    }

    public function login(): void
    {
        $input = $this->validate([
            'username' => 'required|max:64',
            'password' => 'required|max:255',
        ], [
            'username' => 'Username',
            'password' => 'Password',
        ], '/login');

        $error = Auth::attempt((string) $input['username'], (string) $input['password']);

        if ($error !== null) {
            Flash::error($error);
            // The username is repopulated, the password never is.
            Flash::old(['username' => $input['username']]);

            Response::redirect('/login');
        }

        // Where they were headed before being stopped at the door.
        $intended = Session::pull('__intended_url');
        $target   = is_string($intended) && str_starts_with($intended, '/') ? $intended : '/';

        Flash::success('Signed in as ' . Auth::displayName() . '.');

        Response::redirect($target);
    }

    public function logout(): void
    {
        Auth::logout();

        // A fresh session so the sign-in form has a valid CSRF token to offer.
        Session::start();
        Flash::success('You have been signed out.');

        Response::redirect('/login');
    }

    /**
     * An unauthenticated liveness check, for a container probe or a monitor.
     *
     * It deliberately says nothing beyond "the PHP process answered": a health
     * endpoint that reports version numbers or database state to anyone who
     * asks is a reconnaissance endpoint.
     */
    public function health(): void
    {
        Response::json(['status' => 'ok']);
    }
}
