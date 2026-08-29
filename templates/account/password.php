<?php

/**
 * Change your own password.
 *
 * @var bool   $forced  Reached because an administrator set the current one
 * @var string $policy  The password rule, as a sentence
 */

$errors = $errors ?? [];
?>

<div class="page-head">
    <h1><?= $forced ? 'Choose your password' : 'Change your password' ?></h1>
    <p class="muted">
        <?php if ($forced): ?>
            The password you signed in with was set by an administrator, who therefore knows it.
            Choose your own and the rest of the application opens up.
        <?php else: ?>
            You will stay signed in on this device. Any other session of yours is ended.
        <?php endif; ?>
    </p>
</div>

<form method="post" action="<?= e(url('/account/password')) ?>" class="form form-narrow">
    <?= csrf_field() ?>

    <div class="card">
        <div class="field">
            <label class="label" for="current_password">Current password</label>
            <input class="input<?= isset($errors['current_password']) ? ' has-error' : '' ?>"
                   id="current_password" name="current_password" type="password" required
                   autocomplete="current-password" autofocus>
            <?php if (isset($errors['current_password'])): ?>
                <p class="field-error"><?= e($errors['current_password']) ?></p>
            <?php endif; ?>
            <p class="field-hint">
                <?= $forced
                    ? 'The one you were given.'
                    : 'Asked for even though you are signed in: a session left open on an unlocked screen should not be enough to change the credential that outlives it.' ?>
            </p>
        </div>

        <div class="field">
            <label class="label" for="password">New password</label>
            <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>"
                   id="password" name="password" type="password" required
                   autocomplete="new-password">
            <?php if (isset($errors['password'])): ?>
                <p class="field-error"><?= e($errors['password']) ?></p>
            <?php endif; ?>
            <p class="field-hint"><?= e($policy) ?></p>
        </div>

        <div class="field">
            <label class="label" for="password_confirm">New password again</label>
            <input class="input<?= isset($errors['password_confirm']) ? ' has-error' : '' ?>"
                   id="password_confirm" name="password_confirm" type="password" required
                   autocomplete="new-password">
            <?php if (isset($errors['password_confirm'])): ?>
                <p class="field-error"><?= e($errors['password_confirm']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Change it</button>
        <?php if (!$forced): ?>
            <a class="btn btn-ghost" href="<?= e(url('/')) ?>">Cancel</a>
        <?php endif; ?>
    </div>
</form>
