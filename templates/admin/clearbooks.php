<?php

use App\Models\ClearbooksCache;
use App\Models\ClearbooksInvoice;
use App\Services\InvoiceSync;

/**
 * The Clear Books connection and the state of the cached lists.
 *
 * Deliberately narrow, and it stays narrow now the Settings screen exists: the
 * credentials and addresses are edited there, and this covers the two things
 * that are not settings at all — completing the consent flow, without which
 * nothing downstream has anything to match against, and seeing whether the
 * lists the extraction prompts are built from are actually there.
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
 * @var array{bill:int,creditNote:int,total:int,syncedAt:?string}                   $invoices
 * @var array<int,array<string,mixed>>                                              $recentInvoices
 * @var int                                                                         $syncInterval
 * @var array<string,mixed>|null                                                    $syncLastRun
 * @var int|null                                                                    $syncDueAt
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
            Enter them on
            <a href="<?= e(url('/admin/settings#clearbooks')) ?>">Application settings</a> —
            client id, client secret and business id. They can also be set from the command line
            with <code>php bin/console.php settings:set clearbooks_client_id</code> and so on,
            which is what an unattended install uses.
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

    <h2 class="section-title">Purchase documents already in Clear Books</h2>

    <div class="card">
        <p>
            A local copy of every bill and credit note Clear Books holds, kept so that InvoGrid can
            tell whether a document that has just been ingested has already been posted. Clear Books
            has no search endpoint and starts throttling above five requests a second, so the
            question is answered from here rather than asked of them per document.
        </p>
        <p class="muted">
            Clear Books is the source of truth: a document deleted there disappears from here on
            the next run. Nothing is ever written back — this only reads.
        </p>

        <ul class="meta-list">
            <li>
                <strong>Bills</strong>
                <span class="mono"><?= e(number_format($invoices['bill'])) ?></span>
            </li>
            <li>
                <strong>Credit notes</strong>
                <span class="mono"><?= e(number_format($invoices['creditNote'])) ?></span>
            </li>
            <li>
                <strong>Last confirmed</strong>
                <span class="mono">
                    <?= $invoices['syncedAt'] === null ? 'never' : e(format_datetime($invoices['syncedAt'])) ?>
                </span>
            </li>
        </ul>
    </div>

    <div class="card <?= $syncLastRun === null ? '' : (($syncLastRun['ok'] ?? false) ? 'card-ok' : 'card-warn') ?>">
        <h3>Last sync</h3>

        <?php if ($syncLastRun === null): ?>
            <p>
                <span class="badge badge-muted">Never run</span>
            </p>
            <p class="muted">
                Nothing has been fetched yet. Press <strong>Sync now</strong>, or wait for the
                scheduled run.
            </p>
        <?php else: ?>
            <p>
                <span class="badge <?= ($syncLastRun['ok'] ?? false) ? 'badge-ok' : 'badge-danger' ?>">
                    <?= ($syncLastRun['ok'] ?? false) ? 'Succeeded' : 'Failed' ?>
                </span>
                <?= e(format_datetime((string) ($syncLastRun['at'] ?? ''))) ?>
                <span class="muted">
                    — <?= (string) ($syncLastRun['trigger'] ?? '') === 'cron' ? 'scheduled' : 'run by hand' ?>,
                    <?= e(number_format((float) ($syncLastRun['seconds'] ?? 0), 1)) ?>s
                </span>
            </p>

            <p><?= e((string) ($syncLastRun['message'] ?? '')) ?></p>

            <?php // Empty when a run failed before either endpoint answered, in
                  // which case a table of zeroes says less than the message does.
            ?>
            <?php if (is_array($syncLastRun['types'] ?? null) && $syncLastRun['types'] !== []): ?>
                <div class="table-wrap">
                    <table class="table table-compact">
                        <caption class="sr-only">What the last sync fetched</caption>
                        <thead>
                            <tr>
                                <th scope="col">Kind</th>
                                <th scope="col" class="amount">Fetched</th>
                                <th scope="col" class="amount">New</th>
                                <th scope="col" class="amount">Changed</th>
                                <th scope="col" class="amount">Unchanged</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($syncLastRun['types'] as $type => $counts): ?>
                                <tr>
                                    <th scope="row"><?= e(ucfirst(ClearbooksInvoice::label((string) $type, true))) ?></th>
                                    <td class="amount"><?= e(number_format((int) ($counts['fetched'] ?? 0))) ?></td>
                                    <td class="amount"><?= e(number_format((int) ($counts['created'] ?? 0))) ?></td>
                                    <td class="amount"><?= e(number_format((int) ($counts['updated'] ?? 0))) ?></td>
                                    <td class="amount"><?= e(number_format((int) ($counts['unchanged'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <th scope="row">Deleted here, gone from Clear Books</th>
                                <td class="amount" colspan="4">
                                    <?= e(number_format((int) ($syncLastRun['deleted'] ?? 0))) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ((int) ($syncLastRun['derived'] ?? 0) > 0): ?>
                <p class="field-hint">
                    Clear Books returned no total of its own for
                    <?= e(number_format((int) $syncLastRun['derived'])) ?> of these, so the gross
                    amount was worked out from their line items and the cached VAT rates. That is
                    the same arithmetic Clear Books does, but if it applies to every record, the
                    field holding their total is spelled differently from what
                    <code>ClearbooksInvoice::gross()</code> looks for.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Schedule</h3>
        <p class="muted">
            Cron runs the sync script every few minutes; this is how often it actually fetches.
            Hourly suits most businesses — the copy only has to be fresher than the documents
            being uploaded. <strong>0 turns the schedule off</strong> and leaves the button below.
        </p>

        <form method="post" action="<?= e(url('/admin/clearbooks/invoice-schedule')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <div class="input-with-button compact">
                <input class="input" type="number" name="interval_minutes" inputmode="numeric"
                       min="0" max="<?= e((string) InvoiceSync::MAX_INTERVAL) ?>"
                       value="<?= e((string) $syncInterval) ?>"
                       aria-label="Sync every, in minutes">
                <button type="submit" class="btn">Save schedule</button>
            </div>
        </form>

        <p class="field-hint">
            Minutes, <?= e((string) InvoiceSync::MIN_INTERVAL) ?> to
            <?= e((string) InvoiceSync::MAX_INTERVAL) ?>, or 0.
            <?php if ($syncDueAt === null): ?>
                The schedule is off; nothing will be fetched until somebody presses Sync now.
            <?php else: ?>
                Next scheduled run
                <?= $syncDueAt <= time() ? 'is due now' : e('at ' . format_datetime(date('Y-m-d H:i:s', $syncDueAt))) ?>.
            <?php endif; ?>
        </p>

        <div class="form-actions">
            <form method="post" action="<?= e(url('/admin/clearbooks/sync-invoices')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Sync now</button>
            </form>
        </div>

        <p class="field-hint">
            The first run on an established business fetches everything and can take several
            minutes — two hundred records a page, paced to stay under Clear Books' rate limit. It
            carries on even if the browser gives up waiting; reload this page to see how it ended.
        </p>
    </div>

    <?php if ($recentInvoices !== []): ?>
        <div class="table-wrap">
            <table class="table table-compact">
                <caption class="sr-only">The most recently dated purchase documents held locally</caption>
                <thead>
                    <tr>
                        <th scope="col">Number</th>
                        <th scope="col">Kind</th>
                        <th scope="col">Supplier</th>
                        <th scope="col">Reference</th>
                        <th scope="col">Date</th>
                        <th scope="col" class="amount">Gross</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentInvoices as $invoice): ?>
                        <tr>
                            <th scope="row" class="mono"><?= e((string) ($invoice['document_number'] ?? '—')) ?></th>
                            <td><?= e(ucfirst(ClearbooksInvoice::label((string) $invoice['purchase_type']))) ?></td>
                            <td class="break">
                                <?= e((string) ($invoice['supplier_name'] ?? 'not cached')) ?>
                            </td>
                            <td class="break"><?= e(str_limit((string) ($invoice['reference'] ?? ''), 40)) ?></td>
                            <td><?= e(format_date($invoice['document_date'] ?? null)) ?></td>
                            <td class="amount"><?= e(format_money($invoice['gross_amount'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="field-hint">
            The eight most recently dated, as a sanity check that what came back is what you
            expect. Nothing reads these rows yet — matching an arriving document against them is
            the next piece of work.
        </p>
    <?php endif; ?>

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

<?php endif; ?>
