<?php

use App\Core\Upload;

/**
 * The two logo variants.
 *
 * @var array{url:string|null,dimensions:array{width:int,height:int}|null,bytes:int|null} $light
 * @var array{url:string|null,dimensions:array{width:int,height:int}|null,bytes:int|null} $dark
 * @var int                $maxBytes
 * @var array<int,string>  $extensions
 */

$accept = implode(',', array_map(static fn (string $e): string => '.' . $e, $extensions));

/*
 * The header renders the logo at 36px tall on a desktop. Anything under twice
 * that is soft on the retina displays most people are actually reading this on,
 * and "why does the logo look fuzzy" is a question worth answering before it is
 * asked rather than after.
 */
$displayHeight = 36;

/** One slot: the preview, what is known about the file, and how to replace it. */
$slot = static function (string $variant, array $state) use ($accept, $maxBytes, $extensions, $displayHeight): string {
    ob_start();

    $label   = $variant === 'light' ? 'Light mode' : 'Dark mode';
    $ground  = $variant === 'light' ? 'a white background' : 'a dark background';
    $height  = $state['dimensions']['height'] ?? null;
    $tooSoft = $height !== null && $height < $displayHeight * 2;
    ?>
    <div class="card">
        <h2><?= e($label) ?></h2>

        <p class="muted">
            Shown against <?= e($ground) ?>.
            <?php if ($variant === 'light'): ?>
                This is also the one used on printed summaries, because paper is white.
            <?php endif; ?>
        </p>

        <?php /* The preview sits on the ground the variant is *for*, not on the
                 page's current theme. A dark logo previewed on a light card is
                 an invisible logo, and somebody would reasonably conclude the
                 upload had failed. */ ?>
        <div class="logo-preview logo-preview-<?= e($variant) ?>">
            <?php if ($state['url'] !== null): ?>
                <img src="<?= e($state['url']) ?>" alt="The <?= e(strtolower($label)) ?> logo">
            <?php else: ?>
                <span class="brand-mark" aria-hidden="true"><?= e(config('app.mark', 'IG')) ?></span>
                <span class="muted">Nothing uploaded — the monogram stands in.</span>
            <?php endif; ?>
        </div>

        <?php if ($state['url'] !== null): ?>
            <ul class="meta-list">
                <?php if ($state['dimensions'] !== null): ?>
                    <li>
                        <strong>Size</strong>
                        <?= (int) $state['dimensions']['width'] ?> × <?= (int) $state['dimensions']['height'] ?> pixels
                    </li>
                <?php endif; ?>
                <?php if ($state['bytes'] !== null): ?>
                    <li><strong>File</strong> <?= e(Upload::formatBytes((int) $state['bytes'])) ?></li>
                <?php endif; ?>
            </ul>

            <?php if ($tooSoft): ?>
                <p class="field-hint text-danger">
                    Only <?= (int) $height ?> pixels tall. The header draws it at
                    <?= (int) $displayHeight ?>, so on a high-resolution screen this will look
                    soft — <?= (int) ($displayHeight * 2) ?> or more is worth uploading.
                </p>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/admin/branding/' . $variant . '/remove')) ?>"
                  data-confirm="Remove the <?= e(strtolower($label)) ?> logo?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-ghost">Remove it</button>
            </form>
        <?php endif; ?>

        <div class="field">
            <label class="label" for="logo_<?= e($variant) ?>">
                <?= $state['url'] === null ? 'Upload one' : 'Replace it' ?>
            </label>
            <input class="input" type="file" id="logo_<?= e($variant) ?>" name="logo_<?= e($variant) ?>"
                   accept="<?= e($accept) ?>">
            <p class="field-hint">
                <?= e(implode(', ', array_map('strtoupper', $extensions))) ?>,
                up to <?= e(Upload::formatBytes($maxBytes)) ?>.
                Any shape — it is scaled to fit the header without distortion.
            </p>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
};
?>

<div class="page-head">
    <h1>Branding</h1>
    <p class="muted">
        The logo shown in the header, on the sign-in page and at the top of a printed document
        summary. Two variants, because a logo that reads on white usually disappears on a dark
        background — and the theme can be switched without a page load, so both are sent and the
        stylesheet picks.
    </p>
</div>

<?php /* One form, two file inputs. They are saved independently: choosing a file
         for one and leaving the other empty replaces one and leaves the other
         alone, which is what somebody fixing a single variant expects. */ ?>
<form method="post" action="<?= e(url('/admin/branding')) ?>" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>

    <div class="card-grid">
        <?= $slot('light', $light) ?>
        <?= $slot('dark', $dark) ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save the logos</button>
    </div>
</form>

<div class="card">
    <h2>Why PNG and not SVG</h2>
    <p class="muted">
        An SVG is a document that can carry script. Serving one from this origin would let anybody
        who can reach this page run code in everybody else's browser, so the whitelist is raster
        formats only. A PNG at twice the display height is indistinguishable in a
        <?= (int) $displayHeight ?>-pixel-tall header.
    </p>
    <p class="muted">
        Uploads are checked three ways: the extension, the real content type sniffed from the file
        itself rather than believed from the browser, and whether it decodes as an image at all.
        A script wearing a PNG header fails the third.
    </p>
</div>
