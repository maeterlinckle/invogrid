<?php

/**
 * The Existing Invoice queue.
 *
 * Documents carrying a handwritten Clearbooks Number whose match did not
 * settle. Every one of them needs a person, and there is no filter or tab here
 * because there is nothing to filter: one status, one question, three answers.
 *
 * Each row carries the number that was read, the two values the checksum
 * compares, and the reason the match stopped — so the queue can be worked in
 * order of what is actually wrong with it. A dozen rows saying "matches
 * nothing" is a sync that has not run; one saying the total does not agree is a
 * misread digit or a record somebody rounded.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var int                            $total
 * @var int                            $page
 * @var int                            $pages
 * @var int                            $looking
 * @var array<string,mixed>            $synced
 */
?>

<div class="page-head">
    <div>
        <h1>Existing invoices</h1>
        <p class="muted">
            <?php if ($total === 0): ?>
                Nothing is waiting. A document arrives here when the Clearbooks Number written on it
                does not resolve to exactly one record in Clear Books whose date and total agree with it.
            <?php else: ?>
                <?= e((string) $total) ?> waiting for a decision<?php
                if ($looking > 0): ?>, <?= e((string) $looking) ?> still being matched<?php
                endif; ?>.
            <?php endif; ?>
        </p>
    </div>
</div>

<?php /* The state of the local copy, because it is the commonest explanation
         for a queue that has suddenly filled up: an invoice entered in Clear
         Books after the last sync cannot be found by anything. */ ?>
<div class="card">
    <p class="muted">
        Numbers are matched against InvoGrid's copy of Clear Books —
        <strong><?= e((string) $synced['total']) ?></strong> purchase document<?= $synced['total'] === 1 ? '' : 's' ?>,
        last confirmed <?= e($synced['syncedAt'] === null ? 'never' : format_datetime((string) $synced['syncedAt'])) ?>.
        <?php if (can('settings.manage')): ?>
            A record entered since then will not be there yet —
            <a href="<?= e(url('/admin/clearbooks')) ?>">sync it now</a>.
        <?php else: ?>
            A record entered since then will not be there yet.
        <?php endif; ?>
    </p>
</div>

<?php if ($rows === []): ?>
    <div class="card">
        <p class="empty">Nothing here.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">Documents whose Clearbooks Number did not resolve</caption>
            <thead>
                <tr>
                    <th scope="col" class="col-name">Document</th>
                    <th scope="col" class="col-narrow">Clearbooks Number</th>
                    <th scope="col" class="col-date">Dated</th>
                    <th scope="col" class="amount col-narrow">Gross</th>
                    <th scope="col" class="col-grow">Why it is here</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $documentId = (int) $row['id']; ?>
                    <tr>
                        <th scope="row">
                            <a href="<?= e(url('/existing/' . $documentId)) ?>">
                                <?= e($row['document_title'] ?? $row['supplier_raw'] ?? ('Document #' . $documentId)) ?>
                            </a>
                            <span class="cell-sub">
                                #<?= $documentId ?>
                                <?php if (($row['supplier_raw'] ?? null) !== null): ?>
                                    · <?= e((string) $row['supplier_raw']) ?>
                                <?php endif; ?>
                                <?php if (($row['invoice_number'] ?? null) !== null): ?>
                                    · <?= e((string) $row['invoice_number']) ?>
                                <?php endif; ?>
                            </span>
                        </th>
                        <td class="nowrap">
                            <?php if ($row['clearbooks_number'] === null): ?>
                                <span class="badge badge-muted">none read</span>
                            <?php else: ?>
                                <span class="mono">#<?= e((string) $row['clearbooks_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e(format_date($row['invoice_date'])) ?></td>
                        <td class="amount nowrap"><?= e(format_money($row['gross_amount'], $row['currency'])) ?></td>
                        <td class="break"><?= e($row['link_message'] ?? 'Not matched yet.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="form-actions">
            <?php if ($page > 1): ?>
                <a class="btn" href="<?= e(url('/existing?page=' . ($page - 1))) ?>">Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?= e((string) $page) ?> of <?= e((string) $pages) ?>, <?= e((string) $total) ?> in all</span>
            <?php if ($page < $pages): ?>
                <a class="btn" href="<?= e(url('/existing?page=' . ($page + 1))) ?>">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
