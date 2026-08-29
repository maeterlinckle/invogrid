<?php
/**
 * Minimal layout for signed-out pages.
 *
 * @var string $content
 * @var string $pageTitle
 * @var string $appName
 */
$pageTitle = $pageTitle ?? '';
?>
<!doctype html>
<html lang="en-GB" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff">
    <title><?= e($pageTitle !== '' ? $pageTitle . ' · ' . $appName : $appName) ?></title>
    <link rel="icon" href="<?= e(asset_url('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('theme');
                if (!stored) {
                    var match = document.cookie.match(/(?:^|;\s*)theme=(light|dark)/);
                    stored = match ? match[1] : null;
                }
                if (!stored) {
                    stored = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-theme', stored);

                // The browser's own chrome — the address bar on a phone —
                // takes its colour from this. app.js keeps it in step when the
                // theme is toggled; without setting it here too, a dark-mode
                // visitor gets a white address bar above a near-black page
                // until the first time they happen to press the toggle.
                var meta = document.querySelector('meta[name="theme-color"]');
                if (meta) { meta.setAttribute('content', stored === 'dark' ? '#0b1120' : '#ffffff'); }
            } catch (e) { /* theme stays light */ }
        })();
    </script>
</head>
<body class="body-centred">

<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-brand brand">
            <?= partial('partials/brand', ['appName' => $appName]) ?>
        </div>

        <?= partial('partials/flash') ?>
        <?= $content ?>
    </div>

    <button type="button" class="btn btn-ghost theme-toggle-standalone" data-theme-toggle>
        <span class="theme-icon" aria-hidden="true"></span>
        <span data-theme-label>Dark mode</span>
    </button>
</div>

<?= partial('partials/footer') ?>

<script src="<?= e(asset_url('js/app.js')) ?>" defer></script>
</body>
</html>
