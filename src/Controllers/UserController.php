<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\PasswordPolicy;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\User;
use Throwable;

/**
 * Accounts, and who may do what.
 *
 * Admin only, and there is no sign-up page anywhere: every account is made
 * here, or by `bin/create-admin.php` for the first one.
 *
 * Three rules the screen exists to enforce, all of them checked in the model or
 * here rather than only in the markup:
 *
 *  - **Nobody edits their own role or their own active flag.** An administrator
 *    who can demote themselves can lock themselves out with one mis-click, and
 *    a reviewer who could reach this screen at all could promote themselves.
 *  - **The last active administrator cannot be demoted or deactivated**, on
 *    either path in. An application with no administrator needs shell access to
 *    rescue, not a password.
 *  - **A password set by anybody other than its owner must be changed on the
 *    next sign-in.** Otherwise "reset a password" quietly means "hold a
 *    colleague's credentials indefinitely".
 */
final class UserController extends Controller
{
    public function index(): void
    {
        $this->view('admin/users', [
            'pageTitle'    => 'Users',
            'users'        => User::all(),
            'currentId'    => Auth::id(),
            'activeAdmins' => User::activeAdmins(),
            'capabilities' => Auth::capabilityMap(),
        ]);
    }

    /** The form, for a new account or an existing one. */
    public function edit(?string $id = null): void
    {
        $user = $id === null ? null : User::find((int) $id);

        if ($id !== null && $user === null) {
            $this->notFound('No such user.');
        }

        $this->view('admin/user-form', [
            'pageTitle'    => $user === null ? 'Add a user' : 'Edit ' . $user['display_name'],
            'user'         => $user,
            'isSelf'       => $user !== null && (int) $user['id'] === Auth::id(),
            'lastAdmin'    => $user !== null
                && (string) $user['role'] === 'admin'
                && (int) $user['active'] === 1
                && User::activeAdmins((int) $user['id']) === 0,
            'policy'       => PasswordPolicy::describe(),
            'capabilities' => Auth::capabilityMap(),
        ]);
    }

    /**
     * Create an account, or change one.
     *
     * A new account needs a password here, because there is nowhere else to set
     * one — no email is sent, no invitation link exists, and an account that
     * cannot be signed into is not an account.
     */
    public function save(?string $id = null): void
    {
        $user = $id === null ? null : User::find((int) $id);

        if ($id !== null && $user === null) {
            $this->notFound('No such user.');
        }

        $isSelf = $user !== null && (int) $user['id'] === Auth::id();
        $back   = '/admin/users/' . ($user === null ? 'new' : (int) $user['id']);

        $rules = [
            'display_name' => 'required|max:120',
            'email'        => 'optional|email|max:190',
            'role'         => 'required|in:' . implode(',', Auth::ROLES),
        ];

        if ($user === null) {
            $rules['username'] = 'required|min:3|max:64';
            $rules['password'] = PasswordPolicy::rule();
        }

        $input = $this->validate($rules, [
            'display_name' => 'Name',
            'username'     => 'Username',
            'password'     => 'Password',
        ], $back);

        // Somebody editing themselves keeps whatever they already had: the form
        // shows those two controls as read-only, and this is what makes that
        // true rather than merely displayed.
        $role   = $isSelf ? (string) $user['role'] : $input['role'];
        $active = $isSelf ? (int) $user['active'] === 1 : Request::boolean('active');

        try {
            if ($user === null) {
                $problems = PasswordPolicy::problems($input['password'], [
                    $input['username'],
                    $input['display_name'],
                    'invogrid',
                ]);

                if ($problems !== []) {
                    $this->failValidation(['password' => implode(' ', $problems)], $back);
                }

                $newId = User::create(
                    $input['username'],
                    $input['password'],
                    $role,
                    $input['display_name'],
                    $input['email'],
                    // Set by an administrator, so it is a way in and not a
                    // password: the account chooses its own on first sign-in.
                    true
                );

                AuditLog::record('users.created', null, sprintf(
                    '%s created the account "%s" (%s) as a %s.',
                    Auth::displayName(),
                    $input['username'],
                    $input['display_name'],
                    $role
                ));

                Flash::success(sprintf(
                    'Created "%s". Give them the password you just set — they will be asked to '
                        . 'choose their own the first time they sign in.',
                    $input['username']
                ));

                Response::redirect('/admin/users#user-' . $newId);
            }

            $changed = User::update((int) $user['id'], [
                'display_name' => $input['display_name'],
                'email'        => $input['email'],
                'role'         => $role,
                'active'       => $active,
            ]);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Flash::old(Request::all());
            Response::redirect($back);
        }

        if ($changed === []) {
            Flash::info('Nothing was changed.');
            Response::redirect('/admin/users');
        }

        AuditLog::record('users.updated', null, sprintf(
            '%s changed %s on the account "%s"%s.',
            Auth::displayName(),
            implode(', ', array_map(
                static fn (string $c): string => str_replace('_', ' ', $c),
                $changed
            )),
            $user['username'],
            in_array('role', $changed, true) ? ' — now a ' . $role : ''
        ));

        Flash::success(in_array('role', $changed, true) || in_array('active', $changed, true)
            ? 'Saved. The change applies to their very next request, signed in or not.'
            : 'Saved.');

        Response::redirect('/admin/users');
    }

    /**
     * Set somebody else's password.
     *
     * Never your own: the account page is where you change that, and it asks
     * for the current one first. An administrator whose session has been walked
     * away from should not be able to change the password that session is
     * standing on.
     */
    public function password(string $id): void
    {
        $user = User::find((int) $id);

        if ($user === null) {
            $this->notFound('No such user.');
        }

        if ((int) $user['id'] === Auth::id()) {
            Flash::error('Change your own password from your account page — it asks for the current one first.');
            Response::redirect('/account/password');
        }

        $input = $this->validate(
            ['password' => PasswordPolicy::rule()],
            ['password' => 'Password'],
            '/admin/users/' . (int) $user['id']
        );

        $problems = PasswordPolicy::problems($input['password'], [
            (string) $user['username'],
            (string) $user['display_name'],
            'invogrid',
        ]);

        if ($problems !== []) {
            $this->failValidation(
                ['password' => implode(' ', $problems)],
                '/admin/users/' . (int) $user['id']
            );
        }

        User::setPassword((int) $user['id'], $input['password'], true);

        AuditLog::record('users.password_reset', null, sprintf(
            '%s reset the password for "%s". They must choose a new one on their next sign-in.',
            Auth::displayName(),
            $user['username']
        ));

        Flash::success(sprintf(
            'Password set for "%s". Tell them what it is; they will be asked to choose their own '
                . 'the moment they sign in.',
            $user['username']
        ));

        Response::redirect('/admin/users');
    }

    /** Take an account out of use, or put it back. */
    public function toggle(string $id): void
    {
        $user = User::find((int) $id);

        if ($user === null) {
            $this->notFound('No such user.');
        }

        if ((int) $user['id'] === Auth::id()) {
            Flash::error('You cannot deactivate your own account.');
            Response::redirect('/admin/users');
        }

        $active = (int) $user['active'] !== 1;

        try {
            User::setActive((int) $user['id'], $active);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/admin/users');
        }

        AuditLog::record($active ? 'users.activated' : 'users.deactivated', null, sprintf(
            '%s %s the account "%s".',
            Auth::displayName(),
            $active ? 'reactivated' : 'deactivated',
            $user['username']
        ));

        // Auth::user() re-reads the account on every request and drops the
        // session when it is no longer active, so this takes effect at once
        // rather than whenever they next happen to sign in.
        Flash::success($active
            ? '"' . $user['username'] . '" can sign in again.'
            : '"' . $user['username'] . '" is signed out and cannot sign in. Their history is kept.');

        Response::redirect('/admin/users');
    }
}
