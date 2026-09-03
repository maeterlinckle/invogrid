<?php

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Extraction;

/**
 * The review queue.
 *
 * Documents needing a person and documents ready to submit, in one list. They
 * are two halves of the same job: somebody who has just resolved a document
 * should be able to finish it without going to look for it somewhere else.
 *
 * Each row carries enough to decide whether to open it. A queue that shows only
 * a status makes a reviewer open every document to find out which is a minute's
 * work and which is not.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var string                         $filter
 * @var int                            $total
 * @var int                            $page
 * @var int                            $pages
 * @var array<string,int>              $counts
 */

$tabs = [
    'open'   => 'Everything waiting',
    'review' => 'Needs review',
    'ready'  => 'Ready to submit',
];
?>

<div class="page-head">
    <h1>Review queue</h1>
    <p class="muted">
        <?php if ($counts[Document::NEEDS_REVIEW] === 0 && $counts[Document::READY_TO_SUBMIT] === 0): ?>
            Nothing is waiting. Documents arrive here when something could not be resolved on its own,
            or when everything resolved and they are ready to go to Clear Books.
        <?php else: ?>
            <?= e((string) $counts[Document::NEEDS_REVIEW]) ?> needing a decision,
            <?= e((string) $counts[Document::READY_TO_SUBMIT]) ?> ready to submit.
        <?php endif; ?>
    </p>
</div>

<div class="subnav">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="subnav-link<?= $filter === $key ? ' is-active' : '' ?>"
           href="<?= e(url('/review?show=' . $key)) ?>"
            <?= $filter === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($rows === []): ?>
    <div class="card">
        <p class="empty">Nothing here.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">Documents waiting for a reviewer</caption>
            <thead>
                <?php /* Stated widths rather than whatever the browser
                         negotiates: left to itself the last column takes two
                         thirds of a wide screen and the supplier — the thing
                         being scanned for — wraps onto three lines. */ ?>
                <tr>
                    <th scope="col" class="col-name">Document</th>
                    <th scope="col" class="col-name">Supplier</th>
                    <th scope="col" class="col-narrow">Type</th>
                    <th scope="col" class="amount col-narrow">Amount</th>
                    <th scope="col" class="col-date">Dated</th>
                    <th scope="col" class="col-grow">What is outstanding</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $documentId  = (int) $row['id'];
                    $unresolved  = (int) ($row['unresolved'] ?? 0);
                    $ready       = (string) $row['status'] === Document::READY_TO_SUBMIT;

                    // The notes are on the joined extraction, so they are read
                    // here rather than fetched per row.
                    $noteCount = count(Extraction::reviewNotes($row));
                    ?>
                    <tr>
                        <th scope="row">
                            <a href="<?= e(url('/review/' . $documentId)) ?>">
                                <?= e($row['document_title'] ?? ('#' . (int) $row['id'])) ?>
                            </a>
                            <span class="cell-sub">
                                #<?= (int) $row['id'] ?>
                                <?php if ($row['invoice_number'] !== null): ?>
                                    · <?= e((string) $row['invoice_number']) ?>
                                <?php endif; ?>
                                <?php if ($row['edited_at'] !== null): ?>
                                    · <span class="badge badge-info">edited</span>
                                <?php endif; ?>
                            </span>
                        </th>
                        <td class="break"><?= e($row['supplier_raw'] ?? '—') ?></td>
                        <td><?= e(DocumentType::label($row['doc_type'] ?? null)) ?></td>
                        <td class="amount nowrap">
                            <?= $row['gross_amount'] === null
                                ? ($row['net_amount'] === null ? '—' : e(format_money($row['net_amount'], $row['currency'])) . ' net')
                                : e(format_money($row['gross_amount'], $row['currency'])) ?>
                        </td>
                        <td class="nowrap"><?= e(format_date($row['invoice_date'])) ?></td>
                        <td class="col-grow">
                            <?php if ($ready && $unresolved === 0 && $noteCount === 0): ?>
                                <span class="badge badge-ok">ready to submit</span>
                            <?php else: ?>
                                <?php if ($unresolved > 0): ?>
                                    <span class="badge badge-danger">
                                        <?= e((string) $unresolved) ?> unresolved
                                    </span>
                                <?php endif; ?>
                                <?php if ($noteCount > 0): ?>
                                    <span class="badge badge-warn">
                                        <?= e((string) $noteCount) ?> to check
                                    </span>
                                <?php endif; ?>
                                <?php if ($unresolved === 0 && $noteCount === 0): ?>
                                    <span class="badge badge-muted"><?= e(Document::label((string) $row['status'])) ?></span>
                                <?php endif; ?>

                                <?php /* And what the first of them actually says.
                                         A count answers "how much work is this";
                                         the sentence answers "is it my work" —
                                         a supplier nobody has created and a due
                                         date read off a rubber stamp want
                                         different people, and on a wide screen
                                         there is room to say which. */ ?>
                                <?php $first = Extraction::reviewNotes($row)[0] ?? null; ?>
                                <?php if ($first !== null): ?>
                                    <span class="cell-sub"><?= e(str_limit($first, 120)) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="form-actions">
            <?php if ($page > 1): ?>
                <a class="btn" href="<?= e(url('/review?show=' . $filter . '&page=' . ($page - 1))) ?>">Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?= e((string) $page) ?> of <?= e((string) $pages) ?>, <?= e((string) $total) ?> in all</span>
            <?php if ($page < $pages): ?>
                <a class="btn" href="<?= e(url('/review?show=' . $filter . '&page=' . ($page + 1))) ?>">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
