<?php

use App\Models\ClearbooksCache;

/**
 * The Clear Books connection and the state of the cached lists.
 *
 * Deliberately narrow: this is not the Settings screen, which arrives with the
 * rest of the administration in a later stage. It covers the two things that
 * cannot wait — completing the consent flow, without which nothing downstream
 * has anything to match against, and being able to see whether the lists the
 * extraction prompts are built from are actually there.
 *
 * @var bool                                                                        $configured
 * @var bool                                                                        $connected
 * @var int|null                                                                    $expiresAt
 * @var string                                                                      $redirectUri
 * @var string                                                                      $businessId
 * @var string                                                                      $scopes
 * @var array{ok:bool,message:string}                                               $connection
 * @var array<string,array{count:int,cachedAt:?string}>                             $cache
 * @var array<int,array<string,mixed>>                                              $suppliers
 * @var array<int,array<string,mixed>>                                              $creditTypes
 * @var bool                                                                        $syncOn
 * @var bool                                                                        $deleteOn
 */

$labels = [
    ClearbooksCache::SUPPLIER      => 'Suppliers',
    ClearbooksCache::ACCOUNT_CODE  => 'Account codes',
    ClearbooksCache::VAT_TREATMENT => 'VAT treatments',
    ClearbooksCache::VAT_RATE      => 'VAT rates',
];

$empty = 0;
foreach ($cache as $row) {
    if ($row['count'] === 0) {
        $empty++;
    }
}
?>

<div class="page-head">
    <h1>Clear Books</h1>
    <p class="muted">
        The supplier, account code and VAT lists every document is matched against, and the
        authorisation that lets InvoGrid read them.
    </p>
</div>

<h2 class="section-title">Connection</h2>

<div class="card <?= $connected && $connection['ok'] ? 'card-ok' : ($configured ? 'card-warn' : '') ?>">
    <?php if (!$configured): ?>
        <p>
            <span class="badge badge-warn">Not configured</span>
        </p>
        <p>
            InvoGrid needs a Clear Books application's client id and client secret before it can
            connect. Those are issued by Clear Books on request, not generated here.
        </p>
        <p class="field-hint">
            Until the Settings screen exists, they are set from the command line:
            <code>php bin/console.php settings:set clearbooks_client_id</code>, then
            <code>clearbooks_client_secret</code>, then <code>clearbooks_business_id</code>.
        </p>
    <?php elseif (!$connected): ?>
        <p>
            <span class="badge badge-warn">Not authorised</span>
        </p>
        <p>
            The credentials are in place. Somebody with a Clear Books login now has to authorise
            InvoGrid once; after that it keeps itself signed in.
        </p>

        <form method="post" action="<?= e(url('/admin/clearbooks/connect')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Connect to Clear Books</button>
        </form>
    <?php else: ?>
        <p>
            <span class="badge <?= $connection['ok'] ? 'badge-ok' : 'badge-danger' ?>">
                <?= $connection['ok'] ? 'Connected' : 'Authorised, but not answering' ?>
            </span>
        </p>
        <p><?= e($connection['message']) ?></p>

        <ul class="meta-list">
            <li>
                <strong>Business</strong>
                <span class="mono"><?= $businessId === '' ? 'not set' : e($businessId) ?></span>
            </li>
            <li>
                <strong>Access token</strong>
                <span class="mono">
                    <?php if ($expiresAt === null): ?>
                        not yet issued
                    <?php else: ?>
                        <?= $expiresAt > time() ? 'valid until' : 'expired at' ?>
                        <?= e(date('j M Y, H:i', $expiresAt)) ?>
                    <?php endif; ?>
                </span>
            </li>
        </ul>

        <p class="field-hint">
            The access token is short-lived and renews itself. The refresh token behind it is
            single use, which is why nothing but this application should be holding it.
        </p>

        <form method="post" action="<?= e(url('/admin/clearbooks/disconnect')) ?>"
              data-confirm="Disconnect from Clear Books? The cached lists are kept, but nothing will refresh until you reconnect.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost">Disconnect</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($configured && !$connected): ?>
    <div class="card">
        <h3>What Clear Books needs to know about this instance</h3>
        <p class="muted">
            These have to be registered against the application in Clear Books, or the consent
            flow comes back with an error rather than a code.
        </p>
        <div class="field field-readonly">
            <span class="label">Redirect URI</span>
            <span class="field-value mono break"><?= e($redirectUri) ?></span>
        </div>
        <div class="field field-readonly">
            <span class="label">Scopes requested</span>
            <span class="field-value mono break"><?= e($scopes) ?></span>
        </div>
        <p class="field-hint">
            Read access to the lists InvoGrid caches, and write access to the two things it
            creates: a supplier a person has confirmed, and a purchase document. Nothing for
            sales, payments, journals or bank feeds.
        </p>
    </div>
<?php endif; ?>

<h2 class="section-title">Cached lists</h2>

<?php if ($empty > 0): ?>
    <div class="card card-warn">
        <h3><?= $empty === count($cache)
            ? 'Nothing is cached yet'
            : $empty . ' of these lists ' . ($empty === 1 ? 'is' : 'are') . ' empty' ?></h3>
        <p>
            The extraction prompts are given these lists and told to choose only from them. Given
            an empty one a model has no honest answer, so it produces something that looks like a
            match. Every document extracted before a refresh will say so in its review notes.
        </p>
    </div>
<?php endif; ?>

<div class="table-wrap">
    <table class="table">
        <caption class="sr-only">What is cached from Clear Books, and when it was last refreshed</caption>
        <thead>
            <tr>
                <th scope="col">List</th>
                <th scope="col" class="amount">Cached</th>
                <th scope="col">Last refreshed</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cache as $entityType => $row): ?>
                <tr>
                    <th scope="row"><?= e($labels[$entityType] ?? $entityType) ?></th>
                    <td class="amount">
                        <?php if ($row['count'] === 0): ?>
                            <span class="badge badge-danger">none</span>
                        <?php else: ?>
                            <?= e((string) $row['count']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $row['cachedAt'] === null ? '<span class="muted">never</span>' : e(format_datetime($row['cachedAt'])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="field-hint">
    Account codes are narrowed to the ones marked for purchases — a sales-only code offered to
    the extraction prompt is a wrong answer waiting to be picked. Project codes are not listed:
    Clear Books' API has no projects endpoint, so they are set by hand from the link on a
    submitted document.
</p>

<?php if ($connected): ?>
    <div class="card">
        <h3>Refresh now</h3>
        <p class="muted">
            Cron does this twice a day. Do it here after adding a supplier or an account code in
            Clear Books, rather than waiting. It takes a few seconds.
        </p>
        <form method="post" action="<?= e(url('/admin/clearbooks/refresh')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Refresh from Clear Books</button>
        </form>
    </div>

    <h2 class="section-title">Credit notes and refunds, by supplier</h2>

    <div class="card">
        <p>
            A <strong>credit note</strong> is an amount to set against an invoice — no money moves.
            A <strong>purchase refund</strong> is money that has actually come back. Clear Books
            records them in opposite directions, and the document itself often does not say which
            it is, so a reviewer is asked on every one.
        </p>
        <p class="muted">
            Where a supplier reliably does one or the other, record it here and that answer is
            offered first. <strong>The reviewer is still asked</strong> — this only changes which
            box arrives ticked, never whether the question gets put.
        </p>

        <?php if ($suppliers === []): ?>
            <p class="empty">No suppliers are cached yet. Refresh from Clear Books first.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-compact">
                    <caption class="sr-only">Each supplier's usual route for a credit document</caption>
                    <thead>
                        <tr>
                            <th scope="col">Supplier</th>
                            <th scope="col">Usually issues</th>
                            <th scope="col"><span class="sr-only">Save</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                            <?php $route = $supplier['default_credit_route']; ?>
                            <tr>
                                <th scope="row" class="break">
                                    <?= e((string) $supplier['name']) ?>
                                    <span class="cell-sub mono"><?= e((string) $supplier['remote_id']) ?></span>
                                </th>
                                <td colspan="2">
                                    <form method="post"
                                          action="<?= e(url('/admin/clearbooks/supplier-route')) ?>"
                                          class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="remote_id" value="<?= e((string) $supplier['remote_id']) ?>">
                                        <div class="input-with-button">
                                            <select class="input" name="route"
                                                    aria-label="Usual route for <?= e((string) $supplier['name']) ?>">
                                                <option value="">Ask every time</option>
                                                <?php foreach ($creditTypes as $type): ?>
                                                    <?php if ((int) $type['requires_confirmation'] !== 1) {
                                                        continue;
                                                    } ?>
                                                    <option value="<?= e((string) $type['type_key']) ?>"
                                                        <?= (string) $route === (string) $type['type_key'] ? 'selected' : '' ?>>
                                                        <?= e((string) $type['label']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm">Save</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <h2 class="section-title">Paperless correspondents</h2>

    <div class="card">
        <p>
            Clear Books is the source of truth. A new supplier becomes a correspondent; a renamed
            supplier renames its correspondent; a supplier that has gone has its correspondent
            removed — but <strong>never while a Paperless document still points at it</strong>.
            Those documents are moved to whichever supplier Clear Books now considers correct, or
            left flagged for a person if that cannot be determined.
        </p>

        <ul class="meta-list">
            <li>
                <strong>Sync</strong>
                <span class="badge <?= $syncOn ? 'badge-ok' : 'badge-muted' ?>"><?= $syncOn ? 'on' : 'off' ?></span>
            </li>
            <li>
                <strong>May delete correspondents</strong>
                <span class="badge <?= $deleteOn ? 'badge-ok' : 'badge-muted' ?>"><?= $deleteOn ? 'yes' : 'no' ?></span>
            </li>
        </ul>

        <div class="form-actions">
            <form method="post" action="<?= e(url('/admin/clearbooks/sync')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="dry_run" value="1">
                <button type="submit" class="btn">See what would change</button>
            </form>

            <form method="post" action="<?= e(url('/admin/clearbooks/sync')) ?>"
                  data-confirm="Sync correspondents into Paperless now? This changes Paperless, not just InvoGrid.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Sync correspondents</button>
            </form>
        </div>

        <p class="field-hint">
            Every create, rename, delete, re-point and flag is written to the activity log.
        </p>
    </div>
<?php endif; ?>
