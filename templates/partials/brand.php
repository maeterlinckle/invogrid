<?php

use App\Services\Branding;

/**
 * The logo (or the IG monogram fallback) plus the wordmark.
 *
 * Both variants are rendered when both exist and CSS picks by theme: the theme
 * lives in a data-theme attribute the user can flip without a page load, so a
 * server-side choice would show the wrong logo until the next navigation.
 *
 * A logo replaces the monogram; the wordmark stays either way.
 *
 * $homeHref makes the *logo* the link home. The wordmark sits outside it, so
 * there is one link with one accessible name — the image inside it is marked
 * decorative rather than repeating the name to a screen reader.
 *
 * @var string      $appName
 * @var string|null $homeHref
 */
$light    = Branding::url('light');
$dark     = Branding::url('dark');
$name     = (string) ($appName ?? config('app.name', 'InvoGrid'));
$homeHref = isset($homeHref) ? (string) $homeHref : null;
$hasLogo  = $light !== null || $dark !== null;
?>
<?php /* The stack wrapper is always emitted, and says whether it holds a logo,
         so the layout can differ between the two without a :has() selector. */ ?>
<span class="brand-stack<?= $hasLogo ? ' brand-stack-logo' : '' ?>">
    <?php if ($homeHref !== null): ?>
        <a class="brand-home" href="<?= e(url($homeHref)) ?>" aria-label="<?= e($name) ?> — dashboard">
    <?php endif; ?>

    <?php if ($hasLogo): ?>
        <span class="brand-logo-wrap">
            <?php if ($light !== null): ?>
                <img class="brand-logo brand-logo-light" src="<?= e($light) ?>"
                     alt="<?= $homeHref !== null ? '' : e($name) ?>" <?= $homeHref !== null ? 'aria-hidden="true"' : '' ?>>
            <?php endif; ?>
            <?php if ($dark !== null && $dark !== $light): ?>
                <img class="brand-logo brand-logo-dark" src="<?= e($dark) ?>"
                     alt="<?= $homeHref !== null ? '' : e($name) ?>" <?= $homeHref !== null ? 'aria-hidden="true"' : '' ?>>
            <?php endif; ?>
        </span>
    <?php else: ?>
        <span class="brand-mark" aria-hidden="true"><?= e(config('app.mark', 'IG')) ?></span>
    <?php endif; ?>

    <?php if ($homeHref !== null): ?>
        </a>
    <?php endif; ?>

    <span class="brand-name"><?= e($name) ?></span>
</span>
