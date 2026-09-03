<?php

use App\Models\Document;
use App\Models\EntityMatch;
use App\Models\Extraction;
use App\Services\FieldIssues;

/**
 * One document under review: the scan on the left, the record on the right.
 *
 * **The scan pane shows the rendered page images, not the PDF.** They are
 * already on disk — every document is rendered to one image per page before a
 * model is shown it — and they are the very images the extraction was worked
 * out from, so if the reading is wrong this is what it was wrong about. The
 * PDF is a button underneath, and opens beneath the images rather than instead
 * of them. See `templates/partials/scan.php`.
 *
 * **Every extracted value on this page is an input.** A reviewer who can see
 * that a date is wrong but can only accept or reject the document is worse off
 * than one with no machine at all.
 *
 * **Every problem is drawn on the field it belongs to.** What used to be one
 * card at the top saying "4 things to check", above forty inputs, is now a mark
 * against the four inputs concerned: the label carries a word, the box carries
 * a coloured edge, and the note itself sits under the box. `FieldIssues` does
 * the attribution; the index at the top of the form is a list of links to what
 * it found, not a substitute for it. The notes that name no field — a Clear
 * Books list that has never been synced, say — are listed in that index, which
 * is the only thing left in it.
 *
 * @var array<string,mixed>            $document
 * @var array<string,mixed>            $extraction
 * @var array<int,array<string,mixed>> $matches
 * @var array<int,array<string,mixed>> $unresolved
 * @var array<int,string>              $notes
 * @var App\Services\FieldIssues       $issues
 * @var array<int,array<string,mixed>> $pages
 * @var array<int,array<string,mixed>> $lines
 * @var array<string,mixed>            $supplierMatch
 * @var array<string,mixed>            $treatment
 * @var array<string,mixed>            $customValues
 * @var array<int,array<string,mixed>> $customFields
 * @var array<int,array<string,mixed>> $docTypes
 * @var bool                           $needsAgreement
 * @var array<int,array<string,mixed>> $confirmable
 * @var string|null                    $supplierRoute
 * @var array<int,array<string,mixed>> $suppliers
 * @var array<int,array<string,mixed>> $accountCodes
 * @var array<int,array<string,mixed>> $vatRates
 * @var array<int,array<string,mixed>> $vatTreatments
 * @var array<string,mixed>|null       $ocr
 * @var array<string,mixed>|null       $submission
 * @var bool                           $hasPdf
 */

$documentId = (int) $document['id'];
$status     = (string) $document['status'];
$ready      = $status === Document::READY_TO_SUBMIT && $unresolved === [] && $notes === [];
$currency   = $extraction['currency'] ?? null;

/** A `<select>` over cached Clear Books entities. */
$options = static function (array $rows, mixed $selected, string $empty = '— not set —'): string {
    $html = '<option value="">' . e($empty) . '</option>';

    foreach ($rows as $row) {
        $value    = (string) $row['remote_id'];
        $isChosen = $selected !== null && (string) $selected === $value;

        $html .= '<option value="' . e($value) . '"' . ($isChosen ? ' selected' : '') . '>'
            . e($row['name'] . ' (' . $value . ')') . '</option>';
    }

    // A value the document carries that is not on file any more still has to
    // appear, or saving the form would silently drop it and the reviewer would
    // never see what was wrong.
    if ($selected !== null && (string) $selected !== '' && !in_array((string) $selected, array_map(
        static fn (array $row): string => (string) $row['remote_id'],
        $rows
    ), true)) {
        $html .= '<option value="' . e((string) $selected) . '" selected>'
            . e((string) $selected) . ' — not on file in Clear Books</option>';
    }

    return $html;
};

/*
 * The order the index lists flagged fields in, and what to call each one.
 *
 * The form's own order, so following the list top to bottom walks down the
 * screen rather than jumping about it. Line items are appended afterwards
 * because their labels are built per row.
 */
$fieldLabels = [
    'document_title'    => 'Title',
    'cb_summary'        => 'Clear Books description',
    'doc_type'          => 'Type',
    'invoice_number'    => 'Reference',
    'supplier_name_raw' => 'Supplier',
    'invoice_date'      => 'Invoice date',
    'due_date'          => 'Due date',
    'paid_date'         => 'Paid date',
    'lines'             => 'Line items',
    'vat_treatment'     => 'VAT treatment',
    'net_amount'        => 'Net',
    'vat_amount'        => 'VAT',
    'gross_amount'      => 'Gross',
    'currency'          => 'Currency',
];

foreach ($customFields as $field) {
    $fieldLabels['custom_' . (string) $field['field_key']] = (string) $field['label'];
}

// Every cell of the line table that carries something, named the way a person
// would say it: "Line 2 — account code".
$lineColumns = [
    'row'          => '',
    'description'  => ' — description',
    'quantity'     => ' — quantity',
    'unit_price'   => ' — unit price',
    'total'        => ' — net',
    'account_code' => ' — account code',
    'vat_rate'     => ' — VAT rate',
];

foreach (array_keys($lines) as $index) {
    foreach ($lineColumns as $column => $suffix) {
        $fieldLabels['line.' . $index . '.' . $column] = 'Line ' . ((int) $index + 1) . $suffix;
    }
}

/** The supplier's own match row, which decides whether the field can be fixed by typing. */
$supplierRow = null;

foreach ($matches as $row) {
    if ((string) $row['entity_type'] === EntityMatch::SUPPLIER) {
        $supplierRow = $row;
        break;
    }
}

$supplierUnresolved = null;

foreach ($unresolved as $row) {
    if ((string) $row['entity_type'] === EntityMatch::SUPPLIER) {
        $supplierUnresolved = $row;
        break;
    }
}
?>

<div class="page-head">
    <div>
        <h1><?= e($extraction['document_title'] ?? ('Document #' . $documentId)) ?></h1>
        <p class="muted">
            Document #<?= $documentId ?>
            · <span class="badge <?= $ready ? 'badge-ok' : 'badge-warn' ?>"><?= e(Document::label($status)) ?></span>
            <?php if (Extraction::wasEdited($extraction)): ?>
                · <span class="badge badge-info">edited by hand</span>
            <?php endif; ?>
            <?php if ($extraction['supplier_name_raw'] !== null): ?>
                · <?= e((string) $extraction['supplier_name_raw']) ?>
            <?php endif; ?>
            <?php if ($extraction['gross_amount'] !== null): ?>
                · <?= e(format_money($extraction['gross_amount'], $currency)) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('/review')) ?>">Back to the queue</a>
        <a class="btn btn-ghost" href="<?= e(url('/documents/' . $documentId)) ?>">The full pipeline record</a>
    </div>
</div>

<?php /* The submit control sits at the top, above the fold, because when a
         document is ready this is the only thing anybody came here to do. */ ?>
<?php if ($submission !== null && $submission['status'] === 'success'): ?>
    <div class="card card-ok">
        <h2>Already submitted</h2>
        <p>
            This document is in Clear Books as
            <strong><?= e((string) $submission['clearbooks_type']) ?>
                <?= e((string) $submission['clearbooks_id']) ?></strong>,
            sent <?= e(format_datetime((string) $submission['submitted_at'])) ?>.
        </p>
        <?php if ($submission['clearbooks_url'] !== null): ?>
            <p>
                <a class="btn btn-primary" href="<?= e((string) $submission['clearbooks_url']) ?>"
                   data-clearbooks-window>Open in Clear Books</a>
            </p>
        <?php endif; ?>
    </div>
<?php elseif ($ready): ?>
    <div class="card card-ok">
        <h2>Ready to submit</h2>
        <p>
            Everything on this document resolves against Clear Books and nothing is flagged.
            Submitting creates the <?= e(strtolower(\App\Models\DocumentType::label($extraction['doc_type'] ?? null))) ?>,
            and attaches this PDF to it.
        </p>
        <form method="post" action="<?= e(url('/review/' . $documentId . '/submit')) ?>"
              data-confirm="Submit this to Clear Books? It creates a real record in the accounts and cannot be undone from here.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary btn-lg" <?= can('documents.submit') ? '' : 'disabled' ?>>
                Submit to Clear Books
            </button>
        </form>
        <?php if (!can('documents.submit')): ?>
            <p class="field-hint">Your account can review documents but not submit them.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($needsAgreement): ?>
    <?php
    /*
     * What is pre-selected, and what deliberately is not.
     *
     * A **supplier default** pre-selects. It is established local knowledge that
     * an administrator recorded on purpose, and re-answering a settled question
     * every month is how a confirmation step turns into a habit of clicking
     * through.
     *
     * The **model's guess does not**. Confirming it would be one click on an
     * already-correct-looking form, which is precisely the wave-through this
     * step exists to prevent — and the two answers are opposite entries in
     * somebody's accounts. So the box starts empty and the reviewer chooses.
     * The guess is still shown, with its reasoning, right beside the choice.
     *
     * This stays a card of its own rather than becoming a mark on the Type
     * field, because it is not a correction: it is a question with two answers
     * that are opposite entries in the accounts, and it needs the room to say
     * why. The Type field is flagged as well, and points here.
     */
    $preselected = $supplierRoute;
    $guess       = (string) ($extraction['doc_type'] ?? '');
    $reason      = $extraction['doc_type_reason'] ?? null;
    ?>
    <div class="card card-warn" id="confirm-the-type">
        <h2>A credit note and a refund are not the same thing</h2>
        <p>
            A <strong>credit note</strong> gives Junction an amount to set against an invoice —
            <em>no money has moved</em>. A <strong>purchase refund</strong> is money that has
            actually come back. Clear Books records them completely differently, and a document
            headed "Credit Note" that describes a refund payment made <em>is a refund</em>.
        </p>
        <p class="muted">
            The page often does not settle it, because the arrangement was agreed on the telephone.
            That is why this is a question rather than a guess.
        </p>

        <?php if ($guess !== ''): ?>
            <div class="field field-readonly">
                <span class="label">What the reading said</span>
                <span class="field-value">
                    <strong><?= e(\App\Models\DocumentType::label($guess)) ?>.</strong>
                    <?php if ($reason !== null && trim((string) $reason) !== ''): ?>
                        <?= e((string) $reason) ?>
                    <?php else: ?>
                        <span class="muted">No reasoning was recorded for that.</span>
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/review/' . $documentId . '/confirm-type')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label class="label" for="confirm_type">This document is</label>
                <select class="input" id="confirm_type" name="doc_type" required
                        <?= can('review.resolve') ? '' : 'disabled' ?>>
                    <?php if ($preselected === null): ?>
                        <?php /* Empty, selected and disabled: the form cannot be
                                 submitted until somebody actually chooses. */ ?>
                        <option value="" selected disabled>— choose —</option>
                    <?php endif; ?>
                    <?php foreach ($confirmable as $type): ?>
                        <option value="<?= e((string) $type['type_key']) ?>"
                            <?= $preselected !== null && $preselected === (string) $type['type_key'] ? 'selected' : '' ?>>
                            <?= e((string) $type['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <p class="field-hint">
                    <?php if ($supplierRoute !== null): ?>
                        Pre-selected because an administrator recorded that this supplier normally
                        issues <strong><?= e(\App\Models\DocumentType::label($supplierRoute)) ?></strong>.
                        Change it if this one is different.
                    <?php else: ?>
                        Deliberately not pre-selected. The reading above is a guess, and confirming a
                        guess with one click on an already-filled form is the mistake this step exists
                        to prevent — the two answers are opposite entries in the accounts.
                    <?php endif; ?>
                </p>
            </div>

            <label class="checkbox">
                <input type="checkbox" name="remember_for_supplier" value="1">
                <span>
                    Remember this as the usual route for this supplier
                    <span class="field-hint">
                        The next one arrives with this already selected. It is still a question
                        every time — this only changes which answer is offered first.
                    </span>
                </span>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" <?= can('review.resolve') ? '' : 'disabled' ?>>
                    Confirm
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="doc-split doc-split-review">
    <div class="doc-pane doc-pane-scan">
        <?= partial('partials/scan', [
            'documentId' => $documentId,
            'pages'      => $pages,
            'hasPdf'     => $hasPdf,
            'missing'    => 'Neither the PDF nor any rendered page is on disk. Retry this document'
                . ' from its pipeline record to fetch and render it again.',
        ]) ?>

        <?php if ($ocr !== null): ?>
            <?php /* Folded away. It is InvoGrid's own transcription and it is
                     several screens of monospace; the scan beside the form is
                     what the job is actually done against, and a reviewer who
                     wants the text wants it deliberately. */ ?>
            <details class="ocr-panel">
                <summary>What was read off the page</summary>
                <p class="field-hint">
                    InvoGrid's own transcription, annotations included. This is what the extraction
                    was worked out from.
                </p>
                <div class="ocr-text"><?= e(\App\Models\OcrResult::text($ocr)) ?></div>
            </details>
        <?php endif; ?>
    </div>

    <div class="doc-pane">
        <?php
        /*
         * The index: one chip per flagged field, in the order they appear in
         * the form below. Not a replacement for the marks on the fields — it
         * is a list of links to them, so "what needs a look" is answered
         * without scrolling and every answer is one click from the box.
         */
        $flagged = [];
        $worst   = null;

        foreach ($fieldLabels as $key => $label) {
            $tone = $issues->tone($key);

            if ($tone !== null) {
                $flagged[$key] = ['label' => $label, 'tone' => $tone];

                if ($tone === FieldIssues::DANGER) {
                    $worst = $tone;
                }
            }
        }

        $clear = $flagged === [] && $issues->unplaced() === [];
        ?>

        <div class="issue-index<?= $clear ? ' is-clear' : flag_class($worst ?? FieldIssues::WARN) ?>">
            <?php if ($clear): ?>
                <h3>Nothing is flagged</h3>
                <p class="muted">
                    Every value below was read confidently and everything on the document resolves
                    against Clear Books. Read it against the scan anyway — that is the job — but
                    nothing here is asking for a decision.
                </p>
            <?php else: ?>
                <?php if ($flagged !== []): ?>
                    <h3><?= count($flagged) ?> field<?= count($flagged) === 1 ? '' : 's' ?> to look at</h3>
                    <p class="muted">
                        Each is marked on the field itself below. Red must be resolved before this can
                        be submitted; amber is a judgement the pipeline made but was not certain of, and
                        is frequently right.
                    </p>

                    <ul class="issue-jumps">
                        <?php foreach ($flagged as $key => $flag): ?>
                            <li>
                                <?php /* A whole-line problem is drawn on that
                                         line's description cell — it belongs to
                                         no one column — so that is where the
                                         link has to land. */ ?>
                                <a class="issue-jump<?= flag_class($flag['tone']) ?>"
                                   href="#field-<?= e(str_replace(['.row', '.'], ['.description', '-'], $key)) ?>">
                                    <?= e($flag['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($issues->unplaced() !== []): ?>
                    <?php /* The only things left in this panel: notes that name
                             no field, so there is nowhere else honest to put
                             them. Attributing them to a field by guesswork
                             would have somebody correcting a value that was
                             never wrong. */ ?>
                    <?php if ($flagged === []): ?>
                        <h3>Nothing is flagged on a particular field</h3>
                    <?php endif; ?>
                    <p class="field-hint">
                        <?= count($issues->unplaced()) === 1 ? 'One thing' : count($issues->unplaced()) . ' things' ?>
                        raised that name no particular field:
                    </p>
                    <ul class="plain-list review-notes">
                        <?php foreach ($issues->unplaced() as $issue): ?>
                            <li><?= e($issue['text']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($supplierUnresolved !== null): ?>
            <?php
            /*
             * The supplier is the one entity the form cannot fix by itself.
             *
             * An account code or a VAT rate is a `<select>` in the form below,
             * and saving it re-runs the match — so picking the right one *is*
             * the resolution and no second control is needed. The supplier box
             * is a free-text label read off the letterhead; typing in it
             * changes what the document says, not what it points at. So the
             * two things that do point it somewhere live here, next to the
             * field, and the field's own mark links to them.
             */
            $matchId = (int) $supplierUnresolved['id'];
            ?>
            <div class="card card-warn" id="resolve-supplier">
                <h3>The supplier does not resolve yet</h3>
                <p>
                    Read off the document as <strong><?= e((string) $supplierUnresolved['raw_value']) ?></strong>.
                    <?php if ($supplierUnresolved['note'] !== null): ?>
                        <span class="muted"><?= e((string) $supplierUnresolved['note']) ?></span>
                    <?php endif; ?>
                </p>
                <p class="muted">
                    It has to point at a real Clear Books supplier before this document can be
                    submitted. <strong>Nothing here is created automatically</strong> — the second
                    option opens a form you confirm first.
                </p>

                <?php /* Picking something already on file is the common case and is
                         therefore first: most unmatched suppliers are on file under
                         a name the matching could not see. */ ?>
                <form method="post" action="<?= e(url('/review/' . $documentId . '/entity/' . $matchId . '/pick')) ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="pick-supplier">Use one already on file</label>
                        <div class="input-with-button">
                            <select class="input" id="pick-supplier" name="remote_id" required
                                    <?= can('review.resolve') ? '' : 'disabled' ?>>
                                <?= $options($suppliers, null, '— choose —') ?>
                            </select>
                            <button type="submit" class="btn" <?= can('review.resolve') ? '' : 'disabled' ?>>Use this</button>
                        </div>
                        <?php if ($suppliers === []): ?>
                            <p class="field-hint text-danger">
                                No suppliers are cached from Clear Books yet. Refresh the lists from
                                <a href="<?= e(url('/admin/clearbooks')) ?>">Settings → Clear Books</a> first.
                            </p>
                        <?php endif; ?>
                    </div>
                </form>

                <details class="fieldset">
                    <summary class="btn btn-warning btn-inline">Create it in Clear Books instead</summary>

                    <p class="field-hint">
                        Pre-filled from what was read off the document. Check it before confirming —
                        <strong>what gets created is what is in these boxes</strong>, and a supplier
                        created here is a permanent record in the accounts.
                    </p>

                    <form method="post"
                          action="<?= e(url('/review/' . $documentId . '/entity/' . $matchId . '/create')) ?>">
                        <?= csrf_field() ?>

                        <fieldset class="form-body" <?= can('entities.create') ? '' : 'disabled' ?>>

                        <div class="field">
                            <label class="label" for="cb-name">Name</label>
                            <input class="input" id="cb-name" name="name" required maxlength="255"
                                   value="<?= e((string) ($supplierMatch['name'] ?? $extraction['supplier_name_raw'] ?? '')) ?>">
                        </div>

                        <div class="field-row">
                            <div class="field">
                                <label class="label" for="cb-vat">VAT number</label>
                                <input class="input" id="cb-vat" name="vatNumber" maxlength="64"
                                       value="<?= e((string) ($supplierMatch['vatNumber'] ?? '')) ?>">
                            </div>
                            <div class="field">
                                <label class="label" for="cb-company">Company number</label>
                                <input class="input" id="cb-company" name="companyNumber" maxlength="64"
                                       value="<?= e((string) ($supplierMatch['companyNumber'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="field-row">
                            <div class="field">
                                <label class="label" for="cb-email">Email</label>
                                <input class="input" id="cb-email" name="email" type="email" maxlength="255" value="">
                            </div>
                            <div class="field">
                                <label class="label" for="cb-phone">Phone</label>
                                <input class="input" id="cb-phone" name="phone" maxlength="64" value="">
                            </div>
                        </div>

                        <?php /* The model returns an address as one string; Clear
                                 Books wants it in parts. Rather than guess where
                                 the commas belong, the whole thing goes in line 1
                                 for a person to break up. */ ?>
                        <div class="field">
                            <label class="label" for="cb-line1">Address</label>
                            <input class="input" id="cb-line1" name="address_line1" maxlength="255"
                                   value="<?= e((string) ($supplierMatch['address'] ?? '')) ?>">
                            <p class="field-hint">As read off the document — split the town and postcode out below.</p>
                        </div>

                        <div class="field-row">
                            <div class="field">
                                <label class="label" for="cb-town">Town</label>
                                <input class="input" id="cb-town" name="address_town" maxlength="128" value="">
                            </div>
                            <div class="field">
                                <label class="label" for="cb-postcode">Postcode</label>
                                <input class="input" id="cb-postcode" name="address_postcode" maxlength="16" value="">
                            </div>
                        </div>

                        <?php $trading = array_values(array_filter(
                            is_array($supplierMatch['tradingNames'] ?? null) ? $supplierMatch['tradingNames'] : [],
                            'is_string'
                        )); ?>
                        <?php if ($trading !== []): ?>
                            <p class="field-hint">
                                Also trades as <strong><?= e(implode(', ', $trading)) ?></strong>. Clear Books
                                keeps one name per supplier, so pick the one the accounts should show.
                            </p>
                        <?php endif; ?>

                        </fieldset>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-warning"
                                    <?= can('entities.create') ? '' : 'disabled' ?>
                                    data-confirm="Create this supplier in Clear Books? It is a permanent record in the accounts.">
                                Create supplier in Clear Books
                            </button>
                        </div>
                    </form>
                </details>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/review/' . $documentId . '/save')) ?>" class="form form-full">
            <?= csrf_field() ?>

            <?php /* A viewer may read every one of these and change none of them. One
                     disabled <fieldset> rather than a `disabled` on forty controls: an
                     attribute repeated forty times is thirty-nine chances to forget it,
                     and the one that gets forgotten is a box somebody types into for a
                     minute before finding out it was never going to save. The server
                     refuses the POST either way — this is only about not wasting their
                     time. */ ?>
            <?php if (!can('review.resolve')): ?>
                <div class="card card-warn">
                    <p>
                        <strong>Read-only.</strong> Everything below is what the pipeline made of
                        the document. Changing any of it needs the reviewer role.
                    </p>
                </div>
            <?php endif; ?>

            <fieldset class="form-body" <?= can('review.resolve') ? '' : 'disabled' ?>>

            <h3>What it says</h3>

            <?php $tone = $issues->tone('document_title'); ?>
            <div class="field<?= flag_class($tone) ?>" id="field-document_title">
                <label class="label flag-label" for="document_title">Title <?= flag_tag($tone) ?></label>
                <input class="input" id="document_title" name="document_title" maxlength="255"
                       value="<?= e((string) ($extraction['document_title'] ?? '')) ?>">
                <?= flag_notes($issues->on('document_title')) ?>
                <p class="field-hint">A short description of what was bought. Heads this screen and the printable summary.</p>
            </div>

            <?php $tone = $issues->tone('cb_summary'); ?>
            <div class="field<?= flag_class($tone) ?>" id="field-cb_summary">
                <label class="label flag-label" for="cb_summary">Clear Books description <?= flag_tag($tone) ?></label>
                <input class="input" id="cb_summary" name="cb_summary" maxlength="255"
                       value="<?= e((string) ($extraction['cb_summary'] ?? '')) ?>">
                <?= flag_notes($issues->on('cb_summary')) ?>
            </div>

            <div class="field-row">
                <?php $tone = $issues->tone('doc_type'); ?>
                <div class="field<?= flag_class($tone) ?>" id="field-doc_type">
                    <label class="label flag-label" for="doc_type">Type <?= flag_tag($tone) ?></label>
                    <select class="input" id="doc_type" name="doc_type">
                        <option value="">— not classified —</option>
                        <?php foreach ($docTypes as $type): ?>
                            <option value="<?= e((string) $type['type_key']) ?>"
                                <?= (string) ($extraction['doc_type'] ?? '') === (string) $type['type_key'] ? 'selected' : '' ?>>
                                <?= e((string) $type['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= flag_notes($issues->on('doc_type')) ?>
                    <?php if ($needsAgreement): ?>
                        <p class="field-hint">
                            <a href="#confirm-the-type">Somebody has to confirm which this is</a> before it can
                            be submitted — saving a type here does not answer that question.
                        </p>
                    <?php endif; ?>
                </div>

                <?php $tone = $issues->tone('invoice_number'); ?>
                <div class="field<?= flag_class($tone) ?>" id="field-invoice_number">
                    <label class="label flag-label" for="invoice_number">Reference <?= flag_tag($tone) ?></label>
                    <input class="input" id="invoice_number" name="invoice_number" maxlength="100"
                           value="<?= e((string) ($extraction['invoice_number'] ?? '')) ?>">
                    <?= flag_notes($issues->on('invoice_number')) ?>
                    <p class="field-hint">The issuer's own invoice number.</p>
                </div>
            </div>

            <?php $tone = $issues->tone('supplier_name_raw'); ?>
            <div class="field<?= flag_class($tone) ?>" id="field-supplier_name_raw">
                <label class="label flag-label" for="supplier_name_raw">Supplier <?= flag_tag($tone) ?></label>
                <input class="input" id="supplier_name_raw" name="supplier_name_raw" maxlength="255"
                       value="<?= e((string) ($extraction['supplier_name_raw'] ?? '')) ?>">
                <?= flag_notes($issues->on('supplier_name_raw')) ?>
                <p class="field-hint">
                    <?php if ($supplierRow !== null && in_array((string) $supplierRow['status'], [EntityMatch::MATCHED, EntityMatch::CREATED], true)): ?>
                        <span class="badge badge-ok"><?= e((string) $supplierRow['status']) ?></span>
                        Clear Books <span class="mono"><?= e((string) $supplierRow['matched_id']) ?></span>
                        — <?= e((string) $supplierRow['matched_name']) ?>.
                        Editing this box changes the label only.
                    <?php elseif ($supplierUnresolved !== null): ?>
                        Typing here changes the label, not what it points at —
                        <a href="#resolve-supplier">pick or create the Clear Books supplier above</a>.
                    <?php else: ?>
                        Not resolved to a Clear Books supplier yet.
                    <?php endif; ?>
                </p>
            </div>

            <h3>Dates</h3>

            <div class="field-row">
                <?php $tone = $issues->tone('invoice_date'); ?>
                <div class="field<?= flag_class($tone) ?>" id="field-invoice_date">
                    <label class="label flag-label" for="invoice_date">Invoice date <?= flag_tag($tone) ?></label>
                    <input class="input" id="invoice_date" name="invoice_date" type="date"
                           value="<?= e((string) ($extraction['invoice_date'] ?? '')) ?>">
                    <?= flag_notes($issues->on('invoice_date')) ?>
                </div>
                <?php $tone = $issues->tone('due_date'); ?>
                <div class="field<?= flag_class($tone) ?>" id="field-due_date">
                    <label class="label flag-label" for="due_date">Due <?= flag_tag($tone) ?></label>
                    <input class="input" id="due_date" name="due_date" type="date"
                           value="<?= e((string) ($extraction['due_date'] ?? '')) ?>">
                    <?= flag_notes($issues->on('due_date')) ?>
                </div>
                <?php $tone = $issues->tone('paid_date'); ?>
                <div class="field<?= flag_class($tone) ?>" id="field-paid_date">
                    <label class="label flag-label" for="paid_date">Paid <?= flag_tag($tone) ?></label>
                    <input class="input" id="paid_date" name="paid_date" type="date"
                           value="<?= e((string) ($extraction['paid_date'] ?? '')) ?>">
                    <?= flag_notes($issues->on('paid_date')) ?>
                </div>
            </div>

            <h3 id="field-lines">Line items <?= flag_tag($issues->tone('lines')) ?></h3>

            <?= flag_notes($issues->on('lines')) ?>

            <p class="field-hint">
                Clear the description and amounts of a row to drop it — a scan that read the
                remittance slip as a line is the usual reason. The totals below recalculate from
                these unless you type one in yourself. A marked cell is one the pipeline could not
                settle; choosing the right value here and saving is what resolves it.
            </p>

            <div class="table-wrap">
                <table class="table table-compact table-lines">
                    <caption class="sr-only">Editable line items</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="col-desc">Description</th>
                            <th scope="col" class="amount col-qty">Qty</th>
                            <th scope="col" class="amount col-money">Unit</th>
                            <th scope="col" class="amount col-money">Net</th>
                            <th scope="col" class="col-picker">Account code</th>
                            <th scope="col" class="col-picker">VAT rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $index => $line): ?>
                            <?php if (!is_array($line)) {
                                continue;
                            } ?>
                            <?php
                            /*
                             * A cell's mark is the row's mark as well: a note
                             * about the line as a whole — a total that does not
                             * follow from the quantity — has no one column, and
                             * is drawn on the description so it is read first.
                             */
                            $cell = static fn (string $column): ?string => $issues->tone('line.' . $index . '.' . $column);
                            $rowTone = $cell('row');
                            ?>
                            <tr>
                                <td class="<?= trim('col-desc' . flag_class($cell('description') ?? $rowTone)) ?>"
                                    id="field-line-<?= $index ?>-description">
                                    <textarea class="input" name="line_description[]" rows="2"
                                              aria-label="Line <?= e((string) ($index + 1)) ?> description"><?= e((string) ($line['description'] ?? '')) ?></textarea>
                                    <?= flag_notes(array_merge($issues->onLine($index, 'description'), $issues->onLine($index, 'row'))) ?>
                                </td>
                                <td class="amount<?= flag_class($cell('quantity')) ?>" id="field-line-<?= $index ?>-quantity">
                                    <input class="input" name="line_quantity[]" inputmode="decimal"
                                           aria-label="Line <?= e((string) ($index + 1)) ?> quantity"
                                           value="<?= $line['quantity'] === null ? '' : e(rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.')) ?>">
                                    <?= flag_notes($issues->onLine($index, 'quantity')) ?>
                                </td>
                                <td class="amount<?= flag_class($cell('unit_price')) ?>" id="field-line-<?= $index ?>-unit_price">
                                    <input class="input" name="line_unit_price[]" inputmode="decimal"
                                           aria-label="Line <?= e((string) ($index + 1)) ?> unit price"
                                           value="<?= $line['unitPrice'] === null ? '' : e(number_format((float) $line['unitPrice'], 2, '.', '')) ?>">
                                    <?= flag_notes($issues->onLine($index, 'unit_price')) ?>
                                </td>
                                <td class="amount<?= flag_class($cell('total')) ?>" id="field-line-<?= $index ?>-total">
                                    <input class="input" name="line_total[]" inputmode="decimal"
                                           aria-label="Line <?= e((string) ($index + 1)) ?> net"
                                           value="<?= $line['lineTotal'] === null ? '' : e(number_format((float) $line['lineTotal'], 2, '.', '')) ?>">
                                    <?= flag_notes($issues->onLine($index, 'total')) ?>
                                </td>
                                <td class="<?= trim(flag_class($cell('account_code'))) ?>" id="field-line-<?= $index ?>-account_code">
                                    <select class="input" name="line_account_code[]"
                                            aria-label="Line <?= e((string) ($index + 1)) ?> account code">
                                        <?= $options($accountCodes, $line['accountCode'] ?? null) ?>
                                    </select>
                                    <?= flag_notes($issues->onLine($index, 'account_code')) ?>
                                </td>
                                <td class="<?= trim(flag_class($cell('vat_rate'))) ?>" id="field-line-<?= $index ?>-vat_rate">
                                    <select class="input" name="line_vat_rate[]"
                                            aria-label="Line <?= e((string) ($index + 1)) ?> VAT rate">
                                        <?= $options($vatRates, $line['vatRateKey'] ?? null) ?>
                                    </select>
                                    <?= flag_notes($issues->onLine($index, 'vat_rate')) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php /* One blank row, so adding a missed line does not need
                                 JavaScript or a second round trip. */ ?>
                        <tr>
                            <td class="col-desc">
                                <textarea class="input" name="line_description[]" rows="2"
                                          placeholder="Add a line…" aria-label="New line description"></textarea>
                            </td>
                            <td class="amount"><input class="input" name="line_quantity[]" inputmode="decimal" aria-label="New line quantity"></td>
                            <td class="amount"><input class="input" name="line_unit_price[]" inputmode="decimal" aria-label="New line unit price"></td>
                            <td class="amount"><input class="input" name="line_total[]" inputmode="decimal" aria-label="New line net"></td>
                            <td><select class="input" name="line_account_code[]" aria-label="New line account code"><?= $options($accountCodes, null) ?></select></td>
                            <td><select class="input" name="line_vat_rate[]" aria-label="New line VAT rate"><?= $options($vatRates, null) ?></select></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3>Totals</h3>

            <?php $tone = $issues->tone('vat_treatment'); ?>
            <div class="field<?= flag_class($tone) ?>" id="field-vat_treatment">
                <label class="label flag-label" for="vat_treatment">VAT treatment <?= flag_tag($tone) ?></label>
                <select class="input" id="vat_treatment" name="vat_treatment">
                    <?= $options($vatTreatments, $treatment['key'] ?? null) ?>
                </select>
                <?= flag_notes($issues->on('vat_treatment')) ?>
            </div>

            <div class="field-row">
                <?php $tone = $issues->tone('net_amount'); ?>
                <div class="field<?= flag_class($tone) ?>" id="field-net_amount">
                    <label class="label flag-label" for="net_amount">Net <?= flag_tag($tone) ?></label>
                    <input class="input" id="net_amount" name="net_amount" inputmode="decimal"
                           value="<?= $extraction['net_amount'] === null ? '' : e(number_format((float) $extraction['net_amount'], 2, '.', '')) ?>">
                    <?= flag_notes($issues->on('net_amount')) ?>
                </div>
                <?php $tone = $issues->tone('vat_amount'); ?>
                <div class="field<?= flag_class($tone) ?>" id="field-vat_amount">
                    <label class="label flag-label" for="vat_amount">VAT <?= flag_tag($tone) ?></label>
                    <input class="input" id="vat_amount" name="vat_amount" inputmode="decimal"
                           value="<?= $extraction['vat_amount'] === null ? '' : e(number_format((float) $extraction['vat_amount'], 2, '.', '')) ?>">
                    <?= flag_notes($issues->on('vat_amount')) ?>
                </div>
                <?php $tone = $issues->tone('gross_amount'); ?>
                <div class="field<?= flag_class($tone) ?>" id="field-gross_amount">
                    <label class="label flag-label" for="gross_amount">Gross <?= flag_tag($tone) ?></label>
                    <input class="input" id="gross_amount" name="gross_amount" inputmode="decimal"
                           value="<?= $extraction['gross_amount'] === null ? '' : e(number_format((float) $extraction['gross_amount'], 2, '.', '')) ?>">
                    <?= flag_notes($issues->on('gross_amount')) ?>
                </div>
                <?php $tone = $issues->tone('currency'); ?>
                <div class="field<?= flag_class($tone) ?>" id="field-currency">
                    <label class="label flag-label" for="currency">Currency <?= flag_tag($tone) ?></label>
                    <input class="input" id="currency" name="currency" maxlength="3"
                           value="<?= e((string) ($currency ?? '')) ?>" placeholder="GBP">
                    <?= flag_notes($issues->on('currency')) ?>
                </div>
            </div>

            <?php if ($customFields !== []): ?>
                <h3>Custom fields</h3>
                <p class="field-hint">Read off the page — usually handwritten. Blank is a normal answer.</p>

                <div class="field-row">
                    <?php foreach ($customFields as $field): ?>
                        <?php
                        $key   = (string) $field['field_key'];
                        $value = $customValues[$key] ?? null;
                        $type  = (string) $field['data_type'];
                        $tone  = $issues->tone('custom_' . $key);
                        ?>
                        <div class="field<?= flag_class($tone) ?>" id="field-custom_<?= e($key) ?>">
                            <label class="label flag-label" for="custom_<?= e($key) ?>">
                                <?= e((string) $field['label']) ?> <?= flag_tag($tone) ?>
                            </label>
                            <input class="input" id="custom_<?= e($key) ?>" name="custom_<?= e($key) ?>"
                                   type="<?= $type === 'date' ? 'date' : 'text' ?>"
                                   value="<?= is_scalar($value) ? e((string) $value) : '' ?>">
                            <?= flag_notes($issues->on('custom_' . $key)) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            </fieldset>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" <?= can('review.resolve') ? '' : 'disabled' ?>>
                    Save changes
                </button>
                <a class="btn btn-ghost" href="<?= e(url('/review')) ?>">Back to the queue</a>
            </div>
        </form>
    </div>
</div>

<h2 class="section-title">Not one of ours</h2>

<div class="card form-narrow">
    <p>
        A duplicate, a statement rather than an invoice, a delivery note that came in on the same
        scan run. Skipping takes it out of the queue for good — the document and its PDF are kept,
        and it can be put back from its pipeline record.
    </p>

    <form method="post" action="<?= e(url('/review/' . $documentId . '/ignore')) ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label class="label" for="reason">Why</label>
            <input class="input" id="reason" name="reason" required minlength="3" maxlength="900"
                   placeholder="e.g. duplicate of #412"
                   <?= can('review.resolve') ? '' : 'disabled' ?>>
            <p class="field-hint">
                Required, and kept in the activity log. Six months from now somebody will ask why
                this one was skipped, and a name and a timestamp will not answer them.
            </p>
        </div>
        <button type="submit" class="btn btn-danger" <?= can('review.resolve') ? '' : 'disabled' ?>
                data-confirm="Take this document out of the queue?">
            Skip this document
        </button>
    </form>
</div>
