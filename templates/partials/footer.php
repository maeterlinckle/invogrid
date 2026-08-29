<?php
/**
 * Product branding, on every page.
 *
 * The product name rather than app.name: an instance can call itself whatever
 * it likes, but the thing in the footer is what the software is and who made
 * it.
 */
?>
<footer class="site-footer">
    <div class="container footer-inner">
        <div class="footer-brand">
            <span>
                <?= e(config('app.product', 'InvoGrid')) ?> — by
                <a href="<?= e(config('app.vendor_url', 'https://www.junctioninc.co.uk/')) ?>"
                   target="_blank" rel="noopener noreferrer"><?= e(config('app.vendor', 'Junction Inc Ltd')) ?></a>
            </span>
            <span class="muted"><?= e(config('app.product_tagline', 'Purchase document automation')) ?></span>
        </div>

        <?php if (auth_user() !== null): ?>
            <span class="muted">
                Signed in as <?= e(auth_user()['display_name'] ?: auth_user()['username']) ?>
                · <?= e(ucfirst((string) auth_user()['role'])) ?>
            </span>
        <?php endif; ?>
    </div>
</footer>
