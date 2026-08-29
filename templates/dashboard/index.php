<?php

use App\Models\Document;

/**
 * The pipeline at a glance.
 *
 * @var array<string,int>                                        $counts
 * @var array<int,array{status:string,label:string,count:int,tone:string,caption:string}> $attention
 * @var int                                                      $inFlight
 * @var array<int,array<string,mixed>>                           $recent
 * @var int                                                      $total
 * @var array<int,array{label:string,done:bool,hint:string}>      $setupGaps
 * @var array<int,array<string,mixed>>                           $stuck
 * @var int                                                      $stuckPipeline    minutes
 * @var int                                                      $stuckReviewDays
 * @var array<int,array<string,mixed>>                           $activity
 * @var array<int,array<string,mixed>>                           $failures
 */
$outstanding = array_filter($setupGaps, static fn (array $check): bool => !$check['done']);
?>
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted">Purchase documents from Paperless, on their way into Clear Books.</p>
    </div>
</div>

<?php if (can('settings.manage') && $outstanding !== []): ?>
    <?php /* Shown to administrators only: there is nothing a reviewer could do
             about a missing API key, so telling them is just noise. */ ?>
    <div class="card notice-card">
        <h2>Finish setting up</h2>
        <p class="muted">
            <?= count($outstanding) === 1 ? 'One thing is' : count($outstanding) . ' things are' ?>
            still needed before a document can get all the way through.
        </p>
        <ul class="plain-list">
            <?php foreach ($outstanding as $check): ?>
                <li><strong><?= e($check['label']) ?></strong> — <span class="muted"><?= e($check['hint']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<h2 class="section-title">Waiting on someone</h2>

<div class="stat-grid">
    <?php foreach ($attention as $card): ?>
        <div class="stat-card stat-<?= e($card['tone']) ?>">
            <span class="stat-value"><?= (int) $card['count'] ?></span>
            <span class="stat-label"><?= e($card['label']) ?></span>
            <span class="stat-label muted"><?= e($card['caption']) ?></span>
        </div>
    <?php endforeach; ?>

    <div class="stat-card">
        <span class="stat-value"><?= (int) $inFlight ?></span>
        <span class="stat-label">In the pipeline</span>
        <span class="stat-label muted">Reading, extracting, matching</span>
    </div>

    <div class="stat-card">
        <span class="stat-value"><?= (int) $counts[Document::SUBMITTED] ?></span>
        <span class="stat-label">Submitted</span>
        <span class="stat-label muted">Sent to Clear Books</span>
    </div>
</div>

<?php if ($stuck !== []): ?>
    <h2 class="section-title">Not moving</h2>

    <div class="card card-warn">
        <p>
            These have sat in the same place longer than they should have — more than
            <strong><?= (int) $stuckPipeline ?> minutes</strong> in a pipeline stage, or more than
            <strong><?= (int) $stuckReviewDays ?> days</strong> waiting on a person.
        </p>
        <p class="field-hint">
            Nothing else complains about these. A document that has run out of retries is not
            marked <em>failed</em> and does not appear in any count — it simply stops, which is
            why this list exists.
        </p>

        <div class="table-wrap">
            <table class="table table-compact">
                <caption class="sr-only">Documents that have not moved recently</caption>
                <thead>
                    <tr>
                        <th scope="col">Document</th>
                        <th scope="col">Stuck at</th>
                        <th scope="col">Waiting</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stuck as $item): ?>
                        <?php
                        $minutes = (int) $item['waiting_minutes'];
                        $waited  = $minutes < 120
                            ? $minutes . ' minutes'
                            : ($minutes < 2880
                                ? intdiv($minutes, 60) . ' hours'
                                : intdiv($minutes, 1440) . ' days');
                        ?>
                        <tr>
                            <th scope="row">
                                <a href="<?= e(url('/documents/' . (int) $item['id'])) ?>">
                                    #<?= e((string) $item['paperless_doc_id']) ?>
                                </a>
                                <?php if ($item['correspondent_raw'] !== null): ?>
                                    <span class="cell-sub"><?= e((string) $item['correspondent_raw']) ?></span>
                                <?php endif; ?>
                            </th>
                            <td><?= e(Document::label((string) $item['status'])) ?></td>
                            <td class="nowrap"><?= e($waited) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<h2 class="section-title">Every stage</h2>

<div class="table-wrap">
    <table class="table table-compact">
        <caption class="sr-only">Documents at each stage of the pipeline</caption>
        <thead>
            <tr>
                <th scope="col">Stage</th>
                <th scope="col">Documents</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (Document::STATUSES as $status): ?>
                <tr<?= $counts[$status] === 0 ? ' class="row-muted"' : '' ?>>
                    <th scope="row"><?= e(Document::label($status)) ?></th>
                    <td><?= (int) $counts[$status] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2 class="section-title">Recently updated</h2>

<div class="table-wrap">
    <table class="table">
        <caption class="sr-only">The ten most recently updated documents</caption>
        <thead>
            <tr>
                <th scope="col">Paperless</th>
                <th scope="col">Supplier</th>
                <th scope="col">Type</th>
                <th scope="col">Stage</th>
                <th scope="col">Updated</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($recent === []): ?>
                <tr>
                    <td class="empty" colspan="5">
                        No documents yet. One appears here as soon as a Paperless workflow
                        posts to the webhook receiver.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($recent as $document): ?>
                    <tr>
                        <td class="mono">#<?= (int) $document['paperless_doc_id'] ?></td>
                        <td><?= e($document['correspondent_raw'] ?? '—') ?></td>
                        <td><?= e($document['doc_type'] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= $document['status'] === Document::FAILED ? 'badge-danger' : ($document['status'] === Document::NEEDS_REVIEW ? 'badge-warn' : 'badge-muted') ?>">
                                <?= e(Document::label((string) $document['status'])) ?>
                            </span>
                        </td>
                        <td class="nowrap"><?= e(format_datetime((string) $document['updated_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<p class="muted"><?= (int) $total ?> document<?= $total === 1 ? '' : 's' ?> in total.</p>

<?php if ($failures !== []): ?>
    <h2 class="section-title">What the machine tripped over</h2>

    <p class="muted">
        The last few stage failures, newest first. A stage that failed and was retried
        successfully still appears here — it is a record of what happened, not a list of
        things to do.
    </p>

    <div class="table-wrap">
        <table class="table table-compact">
            <caption class="sr-only">Recent pipeline stage failures</caption>
            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Document</th>
                    <th scope="col">Stage</th>
                    <th scope="col">What it said</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($failures as $failure): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_datetime((string) $failure['created_at'])) ?></td>
                        <th scope="row">
                            <a href="<?= e(url('/documents/' . (int) $failure['document_id'])) ?>">
                                #<?= e((string) $failure['paperless_doc_id']) ?>
                            </a>
                        </th>
                        <td class="nowrap"><?= e((string) $failure['stage']) ?></td>
                        <td class="break"><?= e(str_limit((string) ($failure['message'] ?? ''), 140)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($activity !== []): ?>
    <h2 class="section-title">Who did what</h2>

    <p class="muted">
        People, not the pipeline. Every entry here is somebody's deliberate action —
        a document corrected, a supplier created, a setting changed.
    </p>

    <ul class="feed">
        <?php foreach ($activity as $entry): ?>
            <li class="feed-item">
                <span class="feed-when"><?= e(format_datetime((string) $entry['created_at'])) ?></span>
                <span class="feed-what">
                    <?php
                    /*
                     * Most entries are written as a sentence that already names
                     * the person — "Nick created the account …" — but the ones
                     * the framework writes are not, and "Signed in" with no name
                     * against it is the least useful line an activity log can
                     * carry. Prefix only when the sentence does not already
                     * begin with them, so nothing reads "Nick Nick created …".
                     */
                    $who     = trim((string) ($entry['display_name'] ?? $entry['username'] ?? ''));
                    $details = trim((string) ($entry['details'] ?? $entry['action']));

                    // No lcfirst: it turns "Priya Shah edited …" into "priya",
                    // and a log that mangles people's names is worse than one
                    // that repeats a capital letter.
                    if ($who !== '' && !str_starts_with($details, $who)) {
                        $details = $who . ' — ' . $details;
                    }
                    ?>
                    <?= e($details) ?>

                    <?php if ($who === '' && $entry['user_id'] === null): ?>
                        <?php /* Either the pipeline itself, or an account since deleted.
                                 The log outlives the account, which is the point of
                                 ON DELETE SET NULL — but it must not silently read as
                                 though nobody did it. */ ?>
                        <span class="badge badge-muted">no account</span>
                    <?php endif; ?>

                    <?php if ($entry['document_id'] !== null): ?>
                        <a href="<?= e(url('/documents/' . (int) $entry['document_id'])) ?>">the document</a>
                    <?php endif; ?>
                    <span class="cell-sub mono"><?= e((string) $entry['action']) ?></span>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="muted">
        The last <?= count($activity) ?>. A full, searchable activity log is a later stage;
        everything is kept in the meantime, including entries belonging to accounts that have
        since been deactivated.
    </p>
<?php endif; ?>
