<?php
/**
 * @var int    $status
 * @var string $title
 * @var string $message
 */
?>
<div class="error-page">
    <p class="error-status"><?= (int) $status ?></p>
    <h1><?= e($title) ?></h1>
    <p class="muted"><?= e($message) ?></p>
    <p><a class="btn" href="<?= e(url('/')) ?>">Back to the dashboard</a></p>
</div>
