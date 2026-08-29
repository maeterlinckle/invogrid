<?php

use App\Models\Document;

/**
 * Every document, with where it has got to.
 *
 * The plumbing view rather than the review queue: it exists so the pipeline can
 * be watched while the rest of it is built, and it stays afterwards as the
 * place to go when one document has done something odd.
 *
 * @var array<int,array<string,mixed>> $documents
 * @var array<string,string>           $filters
 * @var bool                           $filtered  Any filter is in force
 * @var array<int,string>              $correspondents
 * @var array<int,array<string,mixed>> $docTypes
 * @var int                            $total
 * @var int                            $page
 * @var int                            $pages
 * @var array<string,int>              $counts
 * @var array<string,int>              $queue
 */

/** The badge tone a status reads as. */
$tone = static function (string $status): string {
    return match ($status) {
        Document::FAILED          => 'badge-danger',
        Document::NEEDS_REVIEW    => 'badge-warn',
        Document::READY_TO_SUBMIT => 'badge-info',
        Document::SUBMITTED       => 'badge-ok',
        Document::IGNORED         => 'badge-muted',
        default                   => 'badge-accent',
    };
};

/** Keep the current filters when building a page link. */
$pageUrl = static function (int $page) use ($filters): string {
    // Every filter, not a hand-picked two. The version of this that listed the
    // filters individually silently dropped the new ones on page 2, which reads
    // as "the search broke when I paged" and is very hard to see in a review.
    $query = array_filter(
        $filters + ['page' => $page > 1 ? (string) $page : ''],
        static fn ($value): bool => trim((string) $value) !== ''
    );

    return url('/documents') . ($query === [] ? '' : '?' . http_build_query($query));
};
?>
<div class="page-head">
    <div>
        <h1>Documents</h1>
        <p class="muted">Everything Paperless has told InvoGrid about.</p>
    </div>

    <?php if ($queue['queued'] > 0 || $queue['running'] > 0): ?>
        <p class="muted">
            Queue: <?= (int) $queue['queued'] ?> waiting<?= $queue['running'] > 0 ? ', ' . (int) $queue['running'] . ' running' : '' ?>
        </p>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/documents')) ?>" class="filter-bar">
    <div class="field field-wide">
        <label class="label" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
               placeholder="Supplier, invoice number, title, or a Paperless id">
        <p class="field-hint">
            A number is read as a Paperless id. Anything else is matched against the
            correspondent, and against the supplier name, invoice number and title the
            extraction read off the page.
        </p>
    </div>

    <div class="field">
        <label class="label" for="status">Stage</label>
        <select class="input" id="status" name="status">
            <option value="">Any stage</option>
            <?php foreach (Document::STATUSES as $status): ?>
                <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                    <?= e(Document::label($status)) ?> (<?= (int) $counts[$status] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label class="label" for="doc_type">Type</label>
        <select class="input" id="doc_type" name="doc_type">
            <option value="">Any type</option>
            <?php foreach ($docTypes as $type): ?>
                <option value="<?= e((string) $type['type_key']) ?>"
                    <?= $filters['doc_type'] === (string) $type['type_key'] ? 'selected' : '' ?>>
                    <?= e((string) $type['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label class="label" for="correspondent">Correspondent</label>
        <?php /* A list rather than a box: "Acme Supplies Ltd" and "Acme Supplies
                 Limited" are two different filters and only one finds anything. */ ?>
        <select class="input" id="correspondent" name="correspondent">
            <option value="">Anyone</option>
            <?php foreach ($correspondents as $name): ?>
                <option value="<?= e($name) ?>" <?= $filters['correspondent'] === $name ? 'selected' : '' ?>>
                    <?= e($name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label class="label" for="from">Dated from</label>
        <input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
    </div>

    <div class="field">
        <label class="label" for="to">to</label>
        <input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
        <p class="field-hint">
            The invoice date where one has been read, otherwise the day it arrived — so a
            document that has not been read yet is still findable by when it turned up.
        </p>
    </div>

    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>

        <?php if ($filtered): ?>
            <a class="btn btn-ghost" href="<?= e(url('/documents')) ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($filtered): ?>
    <p class="muted">
        <strong><?= (int) $total ?></strong> <?= $total === 1 ? 'document matches' : 'documents match' ?>
        these filters.
    </p>
<?php endif; ?>

<div class="table-wrap">
    <table class="table">
        <caption class="sr-only">Documents and their pipeline stage</caption>
        <thead>
            <tr>
                <th scope="col">Paperless</th>
                <th scope="col">Supplier</th>
                <th scope="col">Type</th>
                <th scope="col">Stage</th>
                <th scope="col">Received</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($documents === []): ?>
                <tr>
                    <td class="empty" colspan="6">
                        <?php if ($total === 0 && !$filtered): ?>
                            No documents yet. One appears here as soon as a Paperless workflow
                            posts to the webhook receiver.
                        <?php else: ?>
                            Nothing matches that filter.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($documents as $document): ?>
                    <?php $status = (string) $document['status']; ?>
                    <tr<?= $status === Document::IGNORED ? ' class="row-muted"' : '' ?>>
                        <td class="mono nowrap">#<?= (int) $document['paperless_doc_id'] ?></td>
                        <td>
                            <?= e($document['correspondent_raw'] ?? '—') ?>
                            <?php if ($document['error_message'] !== null): ?>
                                <div class="cell-sub text-danger"><?= e(str_limit((string) $document['error_message'], 90)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($document['doc_type'] ?? '—') ?></td>
                        <td><span class="badge <?= e($tone($status)) ?>"><?= e(Document::label($status)) ?></span></td>
                        <td class="nowrap"><?= e(format_datetime((string) $document['created_at'])) ?></td>
                        <td class="actions">
                            <a class="btn btn-sm" href="<?= e(url('/documents/' . $document['id'])) ?>">Open</a>

                            <?php if (in_array($status, [Document::NEEDS_REVIEW, Document::READY_TO_SUBMIT], true) && can('queue.view')): ?>
                                <a class="btn btn-sm" href="<?= e(url('/review/' . $document['id'])) ?>">Review</a>
                            <?php endif; ?>

                            <?php /* The only route to a Clear Books record's project
                                     code, so it belongs on the list as well as on the
                                     document. One reused window, not a tab per click. */ ?>
                            <?php if (($document['clearbooks_url'] ?? null) !== null): ?>
                                <a class="btn btn-sm btn-primary" href="<?= e((string) $document['clearbooks_url']) ?>"
                                   data-clearbooks-window
                                   title="Clear Books <?= e((string) $document['clearbooks_id']) ?>">
                                    Clear Books
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
    <div class="form-actions">
        <?php if ($page > 1): ?>
            <a class="btn" href="<?= e($pageUrl($page - 1)) ?>">Previous</a>
        <?php endif; ?>
        <span class="muted">Page <?= (int) $page ?> of <?= (int) $pages ?> · <?= (int) $total ?> documents</span>
        <?php if ($page < $pages): ?>
            <a class="btn" href="<?= e($pageUrl($page + 1)) ?>">Next</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <p class="muted"><?= (int) $total ?> document<?= $total === 1 ? '' : 's' ?>.</p>
<?php endif; ?>
