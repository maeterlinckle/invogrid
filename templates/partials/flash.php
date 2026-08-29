<?php

use App\Core\Flash;
use App\Models\Setting;

/**
 * One-shot messages.
 *
 * Only confirmations time out. A success banner says "the thing you just did
 * worked", and its result is on the page behind it. An error, a warning or a
 * piece of information is the opposite: it is usually the only place the
 * problem is stated, and a warning that removes itself before it is read is
 * worse than no warning at all. So the timer is attached per message, not to
 * the stack. The dismiss button stays on every message whatever the timer says.
 */
$messages = Flash::messages();

if ($messages === []) {
    return;
}

// The sign-in page renders before the database is necessarily reachable, and a
// settings lookup that throws there would replace "wrong password" with a stack
// trace. A missing setting simply means the default.
try {
    $seconds = Setting::int('flash_auto_hide_seconds', 6);
} catch (Throwable) {
    $seconds = 6;
}

// 0 = stay until dismissed. Clamped: a one-second banner cannot be read, and a
// ten-minute one is not really auto-hiding.
$seconds = $seconds <= 0 ? 0 : max(2, min(120, $seconds));
?>
<div class="flash-stack" role="status" aria-live="polite">
    <?php foreach ($messages as $message): ?>
        <?php $autoHide = $seconds > 0 && $message['type'] === 'success'; ?>
        <div class="flash flash-<?= e($message['type']) ?>"
            <?= $autoHide ? 'data-flash-autohide="' . (int) $seconds . '"' : '' ?>>
            <span class="flash-text"><?= e($message['message']) ?></span>
            <button type="button" class="flash-close" data-dismiss aria-label="Dismiss">&times;</button>
        </div>
    <?php endforeach; ?>
</div>
