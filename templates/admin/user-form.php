<?php

use App\Core\Auth;

/**
 * Add or edit one account.
 *
 * @var array<string,mixed>|null        $user       Null when adding
 * @var bool                            $isSelf     Editing your own account
 * @var bool                            $lastAdmin  The only active administrator
 * @var string                          $policy     The password rule, as a sentence
 * @var array<string,array<int,string>> $capabilities
 */

$isNew  = $user === null;
$old    = $old ?? [];
$errors = $errors ?? [];

/** The submitted value if the form is coming back with an error, else the stored one. */
$value = static function (string $name, mixed $fallback = '') use ($old, $user): string {
    if (array_key_exists($name, $old)) {
        return (string) $old[$name];
    }

    if ($user !== null && array_key_exists($name, $user)) {
        return (string) ($user[$name] ?? '');
    }

    return (string) $fallback;
};

$role     = $value('role', 'reviewer');
$isActive = $isNew ? true : (int) $user['active'] === 1;

$roleNames = [
    'viewer'   => 'Viewer',
    'reviewer' => 'Reviewer',
    'admin'    => 'Administrator',
];

$roleBlurbs = [
    'viewer'   => 'Reads documents, the dashboard and the review queue. Changes nothing.',
    'reviewer' => 'Everything a viewer can do, plus correcting a document, creating a supplier in Clear Books and submitting.',
    'admin'    => 'Everything, including settings, the prompts, the custom fields and these accounts.',
];
?>

<div class="page-head">
    <h1><?= $isNew ? 'Add a user' : 'Edit ' . e((string) $user['display_name']) ?></h1>
    <p class="muted">
        <?php if ($isNew): ?>
            You set the first password here and tell them what it is. They are asked to choose
            their own the moment they sign in, so it is a way in rather than a password you know.
        <?php else: ?>
            A change to the role or to whether the account is active applies to their very next
            request — they do not have to sign out and back in for it to take hold.
        <?php endif; ?>
    </p>
</div>

<form method="post" action="<?= e(url('/admin/users/' . ($isNew ? '' : (int) $user['id']))) ?>" class="form">
    <?= csrf_field() ?>

    <div class="card">
        <h2>Who they are</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="display_name">Name</label>
                <input class="input<?= isset($errors['display_name']) ? ' has-error' : '' ?>"
                       id="display_name" name="display_name" required maxlength="120"
                       value="<?= e($value('display_name')) ?>">
                <?php if (isset($errors['display_name'])): ?>
                    <p class="field-error"><?= e($errors['display_name']) ?></p>
                <?php endif; ?>
                <p class="field-hint">What appears against everything they do.</p>
            </div>

            <div class="field">
                <label class="label" for="username">Username</label>
                <?php if ($isNew): ?>
                    <input class="input mono<?= isset($errors['username']) ? ' has-error' : '' ?>"
                           id="username" name="username" required maxlength="64"
                           autocomplete="off" value="<?= e($value('username')) ?>">
                    <?php if (isset($errors['username'])): ?>
                        <p class="field-error"><?= e($errors['username']) ?></p>
                    <?php endif; ?>
                    <p class="field-hint">
                        Letters, numbers, dots, dashes or underscores. <strong>It cannot be changed
                        later</strong> — the activity log refers to it.
                    </p>
                <?php else: ?>
                    <span class="field-value mono"><?= e((string) $user['username']) ?></span>
                    <p class="field-hint">
                        Fixed. Every line in the activity log refers to it, and renaming one makes
                        a year of history point at somebody who no longer exists under that name.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label class="label" for="email">Email <span class="muted">(optional)</span></label>
            <input class="input<?= isset($errors['email']) ? ' has-error' : '' ?>"
                   id="email" name="email" type="email" maxlength="190"
                   value="<?= e($value('email')) ?>">
            <?php if (isset($errors['email'])): ?>
                <p class="field-error"><?= e($errors['email']) ?></p>
            <?php endif; ?>
            <p class="field-hint">
                Kept only so somebody can be contacted about a document. Nothing is sent to it —
                InvoGrid does not send email.
            </p>
        </div>
    </div>

    <div class="card">
        <h2>What they may do</h2>

        <?php if ($isSelf): ?>
            <div class="card card-warn">
                <p>
                    This is your own account, so the role and the active switch are fixed here.
                    Demoting yourself takes away the screen you would need to undo it, and it is
                    one mis-click away from an application nobody can administer.
                </p>
                <p class="field-hint">
                    Another administrator can change them for you.
                </p>
            </div>
            <p class="field-value"><?= e($roleNames[(string) $user['role']] ?? (string) $user['role']) ?></p>
        <?php else: ?>
            <?php if ($lastAdmin): ?>
                <div class="card card-warn">
                    <p>
                        This is the only active administrator. The role and the active switch are
                        fixed until somebody else is made an administrator — an application with
                        none can only be rescued from the server itself.
                    </p>
                </div>
            <?php endif; ?>

            <fieldset class="fieldset">
                <legend class="label">Role</legend>

                <?php foreach (Auth::ROLES as $option): ?>
                    <label class="checkbox">
                        <input type="radio" name="role" value="<?= e($option) ?>"
                               <?= $role === $option ? 'checked' : '' ?>
                               <?= $lastAdmin ? 'disabled' : '' ?>>
                        <span>
                            <strong><?= e($roleNames[$option] ?? $option) ?></strong>
                            <span class="cell-sub"><?= e($roleBlurbs[$option] ?? '') ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>

                <?php if ($lastAdmin): ?>
                    <input type="hidden" name="role" value="admin">
                <?php endif; ?>
            </fieldset>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="active" value="1"
                           <?= $isActive ? 'checked' : '' ?>
                           <?= $lastAdmin ? 'disabled' : '' ?>>
                    <span>
                        <strong>Active</strong>
                        <span class="cell-sub">
                            A deactivated account is signed out on its next request and cannot sign
                            in. Everything it did is kept — accounts are never deleted, because the
                            activity log refers to them.
                        </span>
                    </span>
                </label>

                <?php if ($lastAdmin): ?>
                    <input type="hidden" name="active" value="1">
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($isNew): ?>
        <div class="card">
            <h2>First password</h2>

            <div class="field">
                <label class="label" for="password">Password</label>
                <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>"
                       id="password" name="password" type="password" required
                       autocomplete="new-password">
                <?php if (isset($errors['password'])): ?>
                    <p class="field-error"><?= e($errors['password']) ?></p>
                <?php endif; ?>
                <p class="field-hint"><?= e($policy) ?></p>
                <p class="field-hint">
                    Tell them what it is by whatever means you would tell them anything else.
                    They are made to choose their own before they can do anything, so it does not
                    matter that you know this one.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create the account' : 'Save' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/users')) ?>">Cancel</a>
    </div>
</form>

<?php if (!$isNew && !$isSelf): ?>
    <div class="card">
        <h2>Set a new password</h2>

        <p class="muted">
            For somebody who has forgotten theirs. They are asked to choose their own the next time
            they sign in, so this is a way back in rather than a password you hold.
        </p>

        <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/password')) ?>" class="form">
            <?= csrf_field() ?>

            <div class="field">
                <label class="label" for="reset_password">New password</label>
                <input class="input" id="reset_password" name="password" type="password" required
                       autocomplete="new-password">
                <p class="field-hint"><?= e($policy) ?></p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn">Set it</button>
            </div>
        </form>
    </div>
<?php elseif (!$isNew && $isSelf): ?>
    <div class="card">
        <h2>Your password</h2>
        <p class="muted">
            Change it from <a href="<?= e(url('/account/password')) ?>">your account page</a>, which
            asks for the current one first. A session left open on an unlocked screen should not be
            enough to change the credential that outlives it.
        </p>
    </div>
<?php endif; ?>
