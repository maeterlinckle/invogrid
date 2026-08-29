<?php
/**
 * Main application layout.
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
        // Applied before first paint so the page never flashes the wrong theme.
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
<body>
<a class="skip-link" href="#main">Skip to content</a>

<?= partial('partials/nav') ?>

<main id="main" class="container">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>

<?= partial('partials/footer') ?>

<script src="<?= e(asset_url('js/app.js')) ?>" defer></script>
</body>
</html>
