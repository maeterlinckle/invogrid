<?php

use App\Services\Branding;

/**
 * The shell for a page meant to end up on paper.
 *
 * Kitwell's approach, and for its reasons: a *separate layout* rather than the
 * ordinary one with everything hidden by `@media print`. Hiding the navigation
 * still ships it — it is in the DOM, it is read aloud by a screen reader, and
 * every future nav change is a chance to break a printed document nobody looks
 * at until a supplier queries an invoice.
 *
 * The page is **always in the light palette**, whatever the viewer's theme.
 * Paper is white; a dark-mode summary either wastes a cartridge or comes out as
 * pale grey on white, and nobody chooses dark mode meaning "print it dark".
 * That is also why the light logo variant is the one used here.
 *
 * @var string $content
 * @var string $pageTitle
 * @var string $appName
 */
$pageTitle = $pageTitle ?? '';
$logo      = Branding::url('light') ?? Branding::url('dark');
?>
<!doctype html>
<html lang="en-GB" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php /* Not "light dark": this page has one palette on purpose, and telling
             the browser otherwise invites it to recolour form controls and
             scrollbars to a scheme the rest of the page is not using. */ ?>
    <meta name="color-scheme" content="light">
    <title><?= e($pageTitle !== '' ? $pageTitle . ' · ' . $appName : $appName) ?></title>
    <link rel="icon" href="<?= e(asset_url('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
</head>
<body class="print-body">

<?php /* The one piece of chrome, and it is .no-print so it does not appear on
         the paper it exists to produce. */ ?>
<div class="print-toolbar no-print">
    <a class="btn btn-ghost" href="<?= e(url($backHref ?? '/documents')) ?>">Back</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">Print this</button>
</div>

<main class="print-sheet">
    <header class="print-head">
        <div class="print-brand">
            <?php if ($logo !== null): ?>
                <?php /* Decorative: the organisation is named in the text beside
                         it, so an alt attribute would read the same thing twice. */ ?>
                <img class="print-logo" src="<?= e($logo) ?>" alt="" aria-hidden="true">
            <?php else: ?>
                <span class="brand-mark" aria-hidden="true"><?= e(config('app.mark', 'IG')) ?></span>
            <?php endif; ?>
            <span class="print-wordmark"><?= e($appName) ?></span>
        </div>

        <div class="print-meta">
            <span><?= e(config('app.vendor', 'Junction Inc Ltd')) ?></span>
            <span>Printed <?= e(date('j F Y, H:i')) ?></span>
        </div>
    </header>

    <?= $content ?>

    <footer class="print-foot">
        <?= e($appName) ?> — purchase document automation by
        <?= e(config('app.vendor', 'Junction Inc Ltd')) ?>.
        This summary is generated from what InvoGrid read and submitted; the record in Clear Books
        is the authority.
    </footer>
</main>

</body>
</html>
