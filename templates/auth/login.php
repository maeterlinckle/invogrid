<?php
/**
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 * @var bool                 $expired
 */
?>
<h1 class="auth-title">Sign in</h1>
<p class="auth-subtitle">InvoGrid processes purchase documents into Clear Books. Access is restricted to authorised users.</p>

<?php if (!empty($expired)): ?>
    <div class="flash flash-warning"><span class="flash-text">Your session timed out. Please sign in again.</span></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/login')) ?>" class="form" autocomplete="on" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="username">Username</label>
        <input class="input<?= isset($errors['username']) ? ' has-error' : '' ?>"
               type="text" id="username" name="username"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               value="<?= e(old($old, 'username')) ?>" required autofocus>
        <?php if (isset($errors['username'])): ?>
            <p class="field-error"><?= e($errors['username']) ?></p>
        <?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="password">Password</label>
        <div class="input-with-button">
            <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>"
                   type="password" id="password" name="password"
                   autocomplete="current-password" required>
            <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="password">Show</button>
        </div>
        <?php if (isset($errors['password'])): ?>
            <p class="field-error"><?= e($errors['password']) ?></p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
</form>

<?php /* No self-service reset: InvoGrid has no outbound email of its own, and a
         forgotten-password page that cannot send anything is worse than none.
         An administrator resets a password with bin/create-admin.php. */ ?>
<p class="auth-help muted">Forgotten your password? Ask an administrator to reset it.</p>
