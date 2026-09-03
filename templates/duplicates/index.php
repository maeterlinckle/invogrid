<?php

/**
 * The duplicate queue.
 *
 * New Invoice documents that look like a purchase document Clear Books already
 * holds. Every one of them needs a person, and there is no filter or tab here
 * because there is nothing to filter: one status, one question, two answers.
 *
 * Each row carries the four values the comparison was made on — supplier, their
 * reference, the date, the total — and the sentence naming the Clear Books
 * records it was stopped against. A queue that makes somebody open every row to
 * find out which record is at issue is a queue that gets worked in the wrong
 * order.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var int                            $total
 * @var int                            $page
 * @var int                            $pages
 * @var array<string,mixed>            $synced
 */
?>

<div class="page-head">
    <div>
        <h1>Possible duplicates</h1>
        <p class="muted">
            <?php if ($total === 0): ?>
                Nothing is waiting. A document arrives here when what was extracted from it looks like a
                purchase document already in Clear Books — the same supplier, reference, date or total —
                even though nobody wrote a Clear Books number on the page.
            <?php else: ?>
                <?= e((string) $total) ?> document<?= $total === 1 ? '' : 's' ?> waiting for a decision.
            <?php endif; ?>
        </p>
    </div>
</div>

<?php /* The state of the local copy, because it is the whole basis of the
         comparison — and because a table nobody has synced flags nothing at
         all, which is the honest reason a queue can be empty. */ ?>
<div class="card">
    <p class="muted">
        Documents are compared against InvoGrid's copy of Clear Books —
        <strong><?= e((string) $synced['total']) ?></strong> purchase document<?= $synced['total'] === 1 ? '' : 's' ?>,
        last confirmed <?= e($synced['syncedAt'] === null ? 'never' : format_datetime((string) $synced['syncedAt'])) ?>.
        <?php if ($synced['total'] === 0): ?>
            <strong>Nothing has been synced yet</strong>, so nothing can be recognised as a duplicate.
            <?php if (can('settings.manage')): ?>
                <a href="<?= e(url('/admin/clearbooks')) ?>">Run the invoice sync</a>.
            <?php endif; ?>
        <?php else: ?>
            An invoice entered in Clear Books since then will not be there to compare against yet.
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
            <caption class="sr-only">Documents that may already be in Clear Books</caption>
            <thead>
                <tr>
                    <th scope="col" class="col-name">Document</th>
                    <th scope="col" class="col-narrow">Their reference</th>
                    <th scope="col" class="col-date">Dated</th>
                    <th scope="col" class="amount col-narrow">Gross</th>
                    <th scope="col" class="col-grow">What it looks like</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $documentId = (int) $row['id']; ?>
                    <tr>
                        <th scope="row">
                            <a href="<?= e(url('/duplicates/' . $documentId)) ?>">
                                <?= e($row['document_title'] ?? $row['supplier_raw'] ?? ('Document #' . $documentId)) ?>
                            </a>
                            <span class="cell-sub">
                                #<?= $documentId ?>
                                <?php if (($row['supplier_raw'] ?? null) !== null): ?>
                                    · <?= e((string) $row['supplier_raw']) ?>
                                <?php endif; ?>
                            </span>
                        </th>
                        <td class="nowrap">
                            <?php if (($row['invoice_number'] ?? null) === null): ?>
                                <span class="badge badge-muted">none read</span>
                            <?php else: ?>
                                <span class="mono"><?= e((string) $row['invoice_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e(format_date($row['invoice_date'])) ?></td>
                        <td class="amount nowrap"><?= e(format_money($row['gross_amount'], $row['currency'])) ?></td>
                        <td class="break"><?= e($row['dedup_message'] ?? 'Not compared yet.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="form-actions">
            <?php if ($page > 1): ?>
                <a class="btn" href="<?= e(url('/duplicates?page=' . ($page - 1))) ?>">Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?= e((string) $page) ?> of <?= e((string) $pages) ?>, <?= e((string) $total) ?> in all</span>
            <?php if ($page < $pages): ?>
                <a class="btn" href="<?= e(url('/duplicates?page=' . ($page + 1))) ?>">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
