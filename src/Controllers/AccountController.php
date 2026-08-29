<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\PasswordPolicy;
use App\Core\Response;
use App\Core\Session;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Your own account — which today means one thing: your password.
 *
 * This exists because the users screen can set somebody's password, and a
 * password an administrator knows has to be changeable by the person it belongs
 * to. Without this page the only route back is asking an administrator for
 * another one, which is the same hole one step further along.
 */
final class AccountController extends Controller
{
    public function password(): void
    {
        $user = Auth::user();

        $this->view('account/password', [
            'pageTitle' => 'Change your password',
            'forced'    => $user !== null && (int) ($user['must_change_password'] ?? 0) === 1,
            'policy'    => PasswordPolicy::describe(),
        ]);
    }

    /**
     * Change it.
     *
     * The current password is asked for even when the change is a forced one.
     * Somebody who has walked away from an unlocked screen has handed over a
     * session, and a session should not be enough to change the credential that
     * outlives it.
     */
    public function updatePassword(): void
    {
        $user = Auth::user();

        if ($user === null) {
            Response::redirect('/login');
        }

        $input = $this->validate([
            'current_password' => 'required',
            'password'         => PasswordPolicy::rule(),
            'password_confirm' => 'required|matches:password',
        ], [
            'current_password' => 'Current password',
            'password'         => 'New password',
            'password_confirm' => 'Confirmation',
        ], '/account/password');

        // findByUsername is the one reader that returns the hash, because it is
        // the one that verifies a password.
        $stored = User::findByUsername((string) $user['username']);

        if ($stored === null || !password_verify($input['current_password'], (string) $stored['password_hash'])) {
            AuditLog::record('account.password_change_failed', null, sprintf(
                'A password change for "%s" was refused: the current password did not match.',
                $user['username']
            ));

            $this->failValidation(
                ['current_password' => 'That is not your current password.'],
                '/account/password'
            );
        }

        if (password_verify($input['password'], (string) $stored['password_hash'])) {
            $this->failValidation(
                ['password' => 'That is the password you already have. Choose a different one.'],
                '/account/password'
            );
        }

        $problems = PasswordPolicy::problems($input['password'], [
            (string) $user['username'],
            (string) $user['display_name'],
            'invogrid',
        ]);

        if ($problems !== []) {
            $this->failValidation(['password' => implode(' ', $problems)], '/account/password');
        }

        User::setPassword((int) $user['id'], $input['password']);

        // A password change is a privilege change: a session id captured before
        // it should not still be good after it.
        Session::regenerate();

        AuditLog::record('account.password_changed', null, sprintf(
            '%s changed their own password.',
            Auth::displayName()
        ));

        Flash::success('Your password has been changed.');

        // Back to wherever they were headed when the forced change caught them,
        // rather than dumping them on the dashboard.
        $intended = Session::get('__intended_url');
        Session::forget('__intended_url');

        Response::redirect(is_string($intended) && $intended !== '' && str_starts_with($intended, '/')
            ? $intended
            : '/');
    }
}
