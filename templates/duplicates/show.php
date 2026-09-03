<?php

use App\Models\ClearbooksInvoice;
use App\Services\DuplicateMatcher;

/**
 * One document that may already be in Clear Books, beside every record it might
 * be.
 *
 * **The whole screen is one gesture: compare, then decide.** The machine has
 * already done everything it can — if it could tell these apart, the document
 * would not be here — so the only useful thing to build is the view that lets a
 * person tell them apart in ten seconds. InvoGrid's reading of the scan in one
 * column, the Clear Books record in the next, the four things that were
 * compared between them marked agreed or not, and the scan itself underneath.
 *
 * The comparison is re-run when this page is opened rather than read off the
 * event the stage recorded — the invoice sync runs on a schedule, and an
 * hour-old opinion is what would have somebody deleting a document against a
 * record that has since changed.
 *
 * @var array<string,mixed>            $document
 * @var array<string,mixed>            $extraction
 * @var array<string,mixed>|null       $ocr
 * @var array<int,array<string,mixed>> $candidates
 * @var array<int,array<string,mixed>> $plausible
 * @var bool                           $comparable
 * @var array<int,array<string,mixed>> $events
 * @var array<int,array<string,mixed>> $pages
 * @var bool                           $hasPdf
 * @var array<string,mixed>            $synced
 */

$documentId = (int) $document['id'];

$tone = static fn (string $outcome): string => match ($outcome) {
    DuplicateMatcher::AGREED    => 'badge-ok',
    DuplicateMatcher::DISAGREED => 'badge-danger',
    default                     => 'badge-warn',
};

$latestReason = null;

foreach ($events as $event) {
    if ((string) $event['stage'] === 'dedup') {
        $latestReason = (string) $event['message'];
        break;
    }
}

// Candidates that no longer clear the bar are still shown, below the ones that
// do, and labelled. A record that was plausible when the document stopped and
// is not any more is exactly what somebody needs to see before they decide —
// hiding it would leave the queue saying "possible duplicate" with nothing
// visible to be a duplicate of.
$dismissed = array_values(array_filter($candidates, static fn (array $c): bool => !$c['plausible']));
?>

<div class="page-head">
    <div>
        <h1>Possible duplicate #<?= $documentId ?></h1>
        <p class="muted">
            <?= e($document['supplier_raw'] ?? 'Supplier not yet known') ?>
            <?php if (($document['original_filename'] ?? null) !== null): ?>
                · <?= e((string) $document['original_filename']) ?>
            <?php endif; ?>
            · <?= e(format_datetime((string) ($document['ingested_at'] ?? $document['created_at']))) ?>
        </p>
    </div>
    <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('/duplicates')) ?>">Back to the queue</a>
        <a class="btn btn-ghost" href="<?= e(url('/documents/' . $documentId)) ?>">Full document page</a>
    </div>
</div>

<div class="card <?= $plausible === [] ? 'card-ok' : 'card-warn' ?>">
    <h2>Where this stands</h2>

    <?php if ($plausible === []): ?>
        <p>
            Nothing in Clear Books now looks like this document. Something has changed since it was
            stopped — most likely the invoice sync has run, and the record it resembled has been
            edited or withdrawn. Confirm it is genuinely new below and it will go on to be reviewed
            like any other invoice.
        </p>
    <?php else: ?>
        <p><?= e($latestReason ?? 'This document may already be in Clear Books.') ?></p>
        <p class="muted">
            This document carries <strong>no handwritten Clear Books number</strong>, so nothing sent it
            down the Existing Invoice route — but a bill entered in Clear Books by hand, or a page scanned
            once before, does not carry one either. Submitting it would put the same purchase into the
            accounts twice.
        </p>
        <p class="muted">
            Nothing happens to it on its own. Either it is the same invoice and the InvoGrid document goes,
            or it is genuinely new and carries on.
        </p>
    <?php endif; ?>
</div>

<?php /* The comparison. Everything above this is context; this is the screen. */ ?>
<h2 class="section-title">
    <?= $plausible === [] ? 'What it was compared against' : 'Side by side' ?>
</h2>

<?php if (!$comparable): ?>
    <div class="card card-warn">
        <p>
            Nothing was extracted from this document that could be compared — no supplier reference and
            no gross total. It cannot be recognised as a duplicate of anything, which is not the same as
            being new.
            <a href="<?= e(url('/documents/' . $documentId)) ?>">See what was read</a>.
        </p>
    </div>
<?php endif; ?>

<?php foreach (array_merge($plausible, $dismissed) as $candidate): ?>
    <?php
    $invoice = $candidate['invoice'];
    $number  = (string) ($invoice['document_number'] ?? $invoice['clearbooks_id']);
    ?>
    <div class="card <?= $candidate['plausible'] ? '' : 'card-muted' ?>">
        <div class="page-head">
            <div>
                <h3>
                    Clear Books <?= e(ClearbooksInvoice::label((string) $invoice['purchase_type'])) ?>
                    <?= e($number) ?>
                </h3>
                <p class="muted">
                    <?php if ($candidate['plausible']): ?>
                        <?= e((string) $candidate['summary']) ?>
                    <?php else: ?>
                        <?= e((string) $candidate['summary']) ?>
                        Not enough on its own to call this a duplicate — shown because it shares the
                        total or the reference, and is worth ruling out by eye.
                    <?php endif; ?>
                </p>
            </div>
            <div class="form-actions">
                <span class="badge <?= $candidate['plausible'] ? 'badge-warn' : 'badge-muted' ?>">
                    <?= (int) $candidate['agreed'] ?> of 4 agree
                </span>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table table-compact">
                <caption class="sr-only">
                    What Clear Books holds against what InvoGrid read off this document
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="col-narrow">&nbsp;</th>
                        <th scope="col" class="col-narrow">In Clear Books</th>
                        <th scope="col" class="col-narrow">This document</th>
                        <th scope="col" class="col-grow">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($candidate['signals'] as $signal): ?>
                        <tr>
                            <th scope="row"><?= e((string) $signal['label']) ?></th>
                            <td class="nowrap"><?= e((string) $signal['recorded']) ?></td>
                            <td class="nowrap"><?= e((string) $signal['extracted']) ?></td>
                            <td>
                                <span class="badge <?= e($tone((string) $signal['outcome'])) ?>">
                                    <?= e((string) $signal['outcome']) ?>
                                </span>
                                <span class="cell-sub"><?= e((string) $signal['note']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php /* The two facts that are not signals: what Clear Books calls the
                 record, and when it was entered. Neither is compared — the
                 document number is what a duplicate lacks by definition — and
                 both are what a person needs to find the record itself. */ ?>
        <ul class="meta-list">
            <li><strong>Their number</strong> <span class="mono"><?= e($number) ?></span></li>
            <li><strong>Kind</strong> <?= e(ClearbooksInvoice::label((string) $invoice['purchase_type'])) ?></li>
            <li><strong>Due</strong> <?= e(format_date($invoice['due_date'])) ?></li>
            <li><strong>Last confirmed in Clear Books</strong> <?= e(format_datetime($invoice['synced_at'])) ?></li>
        </ul>

    </div>
<?php endforeach; ?>

<?php /* Once, under the list rather than inside it. Repeating the rule under
         every candidate made a page with three of them read as three different
         explanations of three different things. */ ?>
<?php if ($candidates !== []): ?>
    <p class="field-hint">
        Two agreements are enough to stop a document, and one of them has to be the gross total or the
        supplier's reference — a supplier and a date agree by themselves every week, and a queue that
        stopped on those would be cleared without being read. A genuine duplicate normally agrees on
        all four; anything less is usually one field the extraction misread, which is what this screen
        is for.
    </p>
<?php endif; ?>

<?php if ($candidates === [] && $comparable): ?>
    <div class="card">
        <p class="empty">
            No purchase document in Clear Books shares this document's total or reference.
            InvoGrid's copy holds <?= e((string) $synced['total']) ?> record<?= $synced['total'] === 1 ? '' : 's' ?>,
            last confirmed <?= e($synced['syncedAt'] === null ? 'never' : format_datetime((string) $synced['syncedAt'])) ?>.
        </p>
    </div>
<?php endif; ?>

<?php /* The scan, underneath rather than beside — the comparison above is the
         thing being read, and the PDF is what settles it when the tables do
         not. */ ?>
<h2 class="section-title">The scan</h2>

<div class="card">
    <?php /* The rendered pages first and the PDF on request, the same as the
             review screen: this screen is a comparison, and flicking between
             two page images is quicker than waiting for a PDF viewer to boot. */ ?>
    <?= partial('partials/scan', [
        'documentId' => $documentId,
        'pages'      => $pages,
        'hasPdf'     => $hasPdf,
        'missing'    => 'Neither the PDF nor any rendered page is on disk. Retry this document from'
            . ' Received on the document page to fetch it again, or delete it below if it is a'
            . ' duplicate anyway.',
    ]) ?>

    <p class="field-hint">
        This document was read, extracted and matched like any other — the same stages, the same
        prompts, everything stored in the same places. Only the last step is outstanding.
        <a href="<?= e(url('/documents/' . $documentId)) ?>">See everything that was read</a>.
    </p>
</div>

<h2 class="section-title">What happens to it</h2>

<div class="card-grid">
    <?php /* 1 — it is genuinely new. */ ?>
    <div class="card <?= $plausible === [] ? 'card-ok' : '' ?>">
        <h3>It is genuinely new</h3>
        <p class="field-hint">
            For a supplier who invoices the same amount every month, a reference that two suppliers
            happen to share, or a record in Clear Books that only looks like this one. Nothing is re-read
            or re-extracted: the document keeps everything it already has and goes straight into the
            ordinary review queue to be submitted as a new invoice.
        </p>
        <p class="field-hint">
            The decision is recorded on the document, so it will <strong>not</strong> be stopped for this
            again — a re-match after an edit will not send it back here.
        </p>

        <form method="post" action="<?= e(url('/duplicates/' . $documentId . '/not-duplicate')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label class="label" for="reason">Why, if it is worth saying</label>
                <input class="input" type="text" id="reason" name="reason"
                       placeholder="Monthly retainer — same amount every month, different invoice"
                       value="<?= e(old($old ?? [], 'reason', '')) ?>">
                <p class="field-hint">Optional. It goes on the activity log beside the decision.</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" <?= can('review.resolve') ? '' : 'disabled' ?>>
                    Confirm it is new, and carry on
                </button>
            </div>

            <?php if (!can('review.resolve')): ?>
                <p class="field-hint">Your account can look at this queue but not act on it.</p>
            <?php endif; ?>
        </form>
    </div>

    <?php /* 2 — it is the same invoice. Behind a <details>, and it says what it does. */ ?>
    <div class="card card-danger">
        <h3>It is the same invoice</h3>
        <p class="field-hint">
            The purchase is already in Clear Books, so there is nothing to post and nothing to keep. This
            <strong>cannot be undone</strong>: the document, its transcription, everything extracted from
            it, its page images and the stored PDF are all removed. Only the activity log will remember
            it — with the Clear Books document it duplicated named in the entry, which is why the reason
            is required.
        </p>
        <p class="field-hint">
            <strong>Nothing in Clear Books is touched either way.</strong> The record was entered by a
            person and is not InvoGrid's to edit; deleting here removes only InvoGrid's copy of the scan.
        </p>

        <?php if (can('documents.delete')): ?>
            <details>
                <summary class="btn btn-danger btn-inline">Delete this document</summary>

                <form method="post" action="<?= e(url('/duplicates/' . $documentId . '/delete')) ?>">
                    <?= csrf_field() ?>

                    <div class="field">
                        <label class="label" for="delete_reason">Why</label>
                        <input class="input" type="text" id="delete_reason" name="reason" required minlength="3"
                               placeholder="Already posted as PUR0080421 in July"
                               value="<?= e(old($old ?? [], 'reason', '')) ?>">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-danger">Delete permanently</button>
                    </div>
                </form>
            </details>
        <?php else: ?>
            <p class="field-hint">Your account cannot delete documents.</p>
        <?php endif; ?>
    </div>
</div>
