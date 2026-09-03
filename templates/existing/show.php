<?php

use App\Models\ClearbooksInvoice;
use App\Models\OcrResult;
use App\Services\InvoiceMatcher;

/**
 * One document waiting to be linked: the scan on the left, the checksum on the
 * right, and the three things a person may do about it.
 *
 * The lookup shown here is run when the page is opened rather than read off the
 * event the stage recorded, so a record entered in Clear Books since — and
 * synced since — appears without anybody having to work out that that is what
 * happened.
 *
 * @var array<string,mixed>            $document
 * @var array<string,mixed>|null       $ocr
 * @var array<string,mixed>|null       $extraction
 * @var string|null                    $number
 * @var array<string,mixed>|null       $lookup
 * @var array<string,mixed>|null       $check
 * @var array<int,array<string,mixed>> $events
 * @var array<int,array<string,mixed>> $pages
 * @var bool                           $hasPdf
 * @var array<string,mixed>            $synced
 */

$documentId = (int) $document['id'];
$matched    = $lookup !== null && $lookup['outcome'] === InvoiceMatcher::MATCHED ? $lookup['invoice'] : null;

// Whether pressing Link now would go straight through, which is what decides
// how the button describes itself.
$wouldLink = $matched !== null && $check !== null && $check['ok'];

$tone = static fn (string $outcome): string => match ($outcome) {
    InvoiceMatcher::AGREED    => 'badge-ok',
    InvoiceMatcher::DISAGREED => 'badge-danger',
    default                   => 'badge-warn',
};

$latestReason = null;

foreach ($events as $event) {
    if ((string) $event['stage'] === 'link') {
        $latestReason = (string) $event['message'];
        break;
    }
}
?>

<div class="page-head">
    <div>
        <h1>Existing invoice #<?= $documentId ?></h1>
        <p class="muted">
            <?= e($document['supplier_raw'] ?? 'Supplier not yet known') ?>
            <?php if (($document['original_filename'] ?? null) !== null): ?>
                · <?= e((string) $document['original_filename']) ?>
            <?php endif; ?>
            · <?= e(format_datetime((string) ($document['ingested_at'] ?? $document['created_at']))) ?>
        </p>
    </div>
    <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('/existing')) ?>">Back to the queue</a>
        <a class="btn btn-ghost" href="<?= e(url('/documents/' . $documentId)) ?>">Full document page</a>
    </div>
</div>

<?php /* Why the stage stopped, in its own words, before anything else. */ ?>
<div class="card <?= $wouldLink ? 'card-ok' : 'card-warn' ?>">
    <h2>Where this stands</h2>
    <?php if ($wouldLink): ?>
        <p>
            The number now resolves to a single Clear Books record, and its date and total both agree
            with what was extracted. Something changed since it was last matched — most likely the
            invoice sync has run. Press <strong>Link this document</strong> below.
        </p>
    <?php else: ?>
        <p><?= e($latestReason ?? 'This document is waiting to be linked.') ?></p>
        <p class="muted">
            Nothing will happen to it on its own. Link it to the right record, treat it as a new
            invoice, or delete it.
        </p>
    <?php endif; ?>
</div>

<div class="doc-split doc-split-review">
    <div class="doc-pane doc-pane-scan">
        <?php /* The rendered pages first and the PDF on request, the same as the
                 review screen — the number this queue is about is handwritten on
                 the page, and reading handwriting is what the actual-size control
                 on the image viewer is for. */ ?>
        <?= partial('partials/scan', [
            'documentId' => $documentId,
            'pages'      => $pages,
            'hasPdf'     => $hasPdf,
            'missing'    => 'Neither the PDF nor any rendered page is on disk, so there is nothing'
                . ' to attach to a Clear Books record. Retry this document from Received on the'
                . ' document page to fetch it again.',
        ]) ?>

        <?php if (!$hasPdf): ?>
            <p class="field-hint text-danger">
                Without the source PDF there is nothing to attach, so this document cannot be
                linked until it has been fetched again.
            </p>
        <?php endif; ?>
    </div>

    <div class="doc-pane">
        <h3>What InvoGrid read</h3>

        <div class="card">
            <ul class="meta-list">
                <li>
                    <strong>Clearbooks Number</strong>
                    <?php if ($number === null): ?>
                        <span class="muted">none found on the page</span>
                    <?php else: ?>
                        <span class="mono">#<?= e($number) ?></span>
                    <?php endif; ?>
                </li>
                <li><strong>Project</strong> <?= e($ocr === null ? '—' : (OcrResult::projectCode($ocr) ?? '—')) ?></li>
                <?php if ($extraction !== null): ?>
                    <li><strong>Supplier</strong> <?= e($extraction['supplier_name_raw'] ?? $document['supplier_raw'] ?? '—') ?></li>
                    <li><strong>Their reference</strong> <?= e($extraction['invoice_number'] ?? '—') ?></li>
                    <li><strong>Invoice date</strong> <?= e(format_date($extraction['invoice_date'])) ?></li>
                    <li><strong>Gross total</strong> <?= e(format_money($extraction['gross_amount'], $extraction['currency'])) ?></li>
                <?php endif; ?>
            </ul>

            <?php if ($extraction === null): ?>
                <p class="field-hint text-danger">
                    Nothing has been extracted from this document, so there is no date or total to check a
                    Clear Books record against. Treat it as a new invoice to put it back through the pipeline.
                </p>
            <?php else: ?>
                <p class="field-hint">
                    This document was read and extracted like any other — the same stages, the same prompts,
                    everything stored in the same places. Only the last step is different: it is matched to a
                    Clear Books record rather than creating one, and <strong>nothing on that record is
                    changed</strong> beyond attaching the scan.
                    <a href="<?= e(url('/documents/' . $documentId)) ?>">See everything that was read</a>.
                </p>
            <?php endif; ?>
        </div>

        <?php /* The lookup, and what the checksum made of it. */ ?>
        <div class="card">
            <h3>The Clear Books record</h3>

            <?php if ($lookup === null): ?>
                <p class="muted">There is no number to look up.</p>

            <?php elseif ($lookup['outcome'] === InvoiceMatcher::NONE): ?>
                <p>
                    No purchase document in Clear Books answers to
                    <span class="mono">#<?= e((string) $lookup['number']) ?></span>.
                </p>
                <p class="field-hint">
                    InvoGrid's copy holds <?= e((string) $synced['total']) ?> record<?= $synced['total'] === 1 ? '' : 's' ?>,
                    last confirmed <?= e($synced['syncedAt'] === null ? 'never' : format_datetime((string) $synced['syncedAt'])) ?>.
                </p>

            <?php elseif ($lookup['outcome'] === InvoiceMatcher::AMBIGUOUS): ?>
                <p>
                    <span class="mono">#<?= e((string) $lookup['number']) ?></span> matches
                    <?= $lookup['truncated'] ? 'more than ' : '' ?><?= e((string) count($lookup['candidates'])) ?>
                    records, so InvoGrid will not guess between them.
                </p>
                <ul class="meta-list">
                    <?php foreach ($lookup['candidates'] as $candidate): ?>
                        <li>
                            <strong><?= e((string) ($candidate['document_number'] ?? $candidate['clearbooks_id'])) ?></strong>
                            <span>
                                <?= e(ClearbooksInvoice::label((string) $candidate['purchase_type'])) ?>,
                                <?= e(format_date($candidate['document_date'])) ?>,
                                <?= e(format_money($candidate['gross_amount'])) ?>
                                <?= $candidate['supplier_name'] === null ? '' : e(' — ' . $candidate['supplier_name']) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="field-hint">Type the exact Clear Books number of the right one below.</p>

            <?php else: ?>
                <ul class="meta-list">
                    <li><strong>Number</strong> <?= e((string) ($matched['document_number'] ?? $matched['clearbooks_id'])) ?></li>
                    <li><strong>Kind</strong> <?= e(ClearbooksInvoice::label((string) $matched['purchase_type'])) ?></li>
                    <li><strong>Supplier</strong> <?= e($matched['supplier_name'] ?? 'not in the cached list') ?></li>
                    <li><strong>Their reference</strong> <?= e($matched['reference'] ?? '—') ?></li>
                </ul>

                <?php if ($check !== null): ?>
                    <h4>The checksum</h4>

                    <div class="table-wrap">
                        <table class="table table-compact">
                            <caption class="sr-only">What Clear Books holds against what was extracted</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="col-narrow">&nbsp;</th>
                                    <th scope="col" class="col-narrow">In Clear Books</th>
                                    <th scope="col" class="col-narrow">Extracted</th>
                                    <th scope="col" class="col-grow">&nbsp;</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($check['signals'] as $signal): ?>
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

                    <p class="field-hint">
                        The Clearbooks Number is the key; the date and the total are the checksum on it.
                        <strong>Both have to agree exactly</strong> — there is no tolerance on either,
                        because a hit on the number with a date or a total that does not agree is exactly
                        what a misread digit looks like. A record keyed in on a different day, or rounded
                        when it was entered, is a real case and is what this screen is for.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<h2 class="section-title">What happens to it</h2>

<div class="card-grid">
    <?php /* 1 — link it. */ ?>
    <div class="card <?= $wouldLink ? 'card-ok' : '' ?>">
        <h3>Link it to a Clear Books record</h3>
        <p class="field-hint">
            The field holds the number read off the page. Correct it if a digit was misread, or leave it
            alone to look the same number up again — the invoice sync runs on a schedule, and a record
            entered in Clear Books since this document was read will not have been there before.
        </p>

        <form method="post" action="<?= e(url('/existing/' . $documentId . '/link')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label class="label" for="clearbooks_number">Clear Books number</label>
                <input class="input" type="text" id="clearbooks_number" name="clearbooks_number"
                       inputmode="numeric" required
                       value="<?= e(old($old ?? [], 'clearbooks_number', $number ?? '')) ?>">
                <p class="field-hint">Digits only, as written on the page. A leading “#” is ignored.</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"
                    <?= can('review.resolve') && $hasPdf && $extraction !== null ? '' : 'disabled' ?>>
                    Link this document
                </button>
            </div>

            <?php if ($extraction === null): ?>
                <p class="field-hint text-danger">
                    There is nothing extracted to check a record against.
                </p>
            <?php elseif (!$hasPdf): ?>
                <p class="field-hint text-danger">
                    There is no PDF to attach, so there is nothing to link. Fetch it again first.
                </p>
            <?php elseif (!can('review.resolve')): ?>
                <p class="field-hint">Your account can look at this queue but not act on it.</p>
            <?php elseif (!$wouldLink && $matched !== null): ?>
                <p class="field-hint">
                    The checksum does not hold, so InvoGrid would not have linked this on its own.
                    Pressing the button anyway links it, and records that you overrode the checksum:
                    you have the scan in front of you and the record beside it, which is more than the
                    checksum has.
                </p>
            <?php endif; ?>
        </form>
    </div>

    <?php /* 2 — it is not an existing invoice at all. */ ?>
    <div class="card">
        <h3>Treat it as a new invoice</h3>
        <p class="field-hint">
            For when the number was not a Clear Books reference after all — a stock code, a purchase order,
            somebody else's reference. Nothing is re-read or re-extracted: the document keeps everything it
            already has and goes straight into the ordinary review queue, to be posted as a new bill.
        </p>

        <form method="post" action="<?= e(url('/existing/' . $documentId . '/new-invoice')) ?>" class="form-actions">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-warning" <?= can('review.resolve') ? '' : 'disabled' ?>>
                Post it as a new invoice
            </button>
        </form>
    </div>

    <?php /* 3 — delete. Behind a <details>, and it says what it does. */ ?>
    <div class="card card-danger">
        <h3>Delete it</h3>
        <p class="field-hint">
            For a duplicate scan, or a page that is nobody's. This <strong>cannot be undone</strong>: the
            document, its transcription, everything extracted from it, its page images and the stored PDF
            are all removed. Only the activity log will remember it, which is why the reason is required.
        </p>

        <?php if (can('documents.delete')): ?>
            <details>
                <summary class="btn btn-danger btn-inline">Delete this document</summary>

                <form method="post" action="<?= e(url('/existing/' . $documentId . '/delete')) ?>">
                    <?= csrf_field() ?>

                    <div class="field">
                        <label class="label" for="reason">Why</label>
                        <input class="input" type="text" id="reason" name="reason" required minlength="3"
                               placeholder="Duplicate of the scan filed on 14 July"
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
