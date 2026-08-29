<?php

use App\Models\ClearbooksCache;
use App\Models\Document;
use App\Models\EntityMatch;
use App\Models\Extraction;

/**
 * One document under review: the scan on the left, the record on the right.
 *
 * The PDF is served by an ordinary authenticated route on this same origin
 * (`/documents/{id}/pdf`), so the browser's own viewer renders it in an
 * `<object>` and that is the whole of it. The arrangement this replaces had the
 * file on a different domain and had to ship it base64-encoded inside a JSON
 * response to get around that; nothing here needs to, because InvoGrid stores
 * the file itself.
 *
 * **Every extracted value on this page is an input.** A reviewer who can see
 * that a date is wrong but can only accept or reject the document is worse off
 * than one with no machine at all.
 *
 * @var array<string,mixed>            $document
 * @var array<string,mixed>            $extraction
 * @var array<int,array<string,mixed>> $matches
 * @var array<int,array<string,mixed>> $unresolved
 * @var array<int,string>              $notes
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
?>

<div class="page-head">
    <h1>
        <?= e($extraction['paperless_title'] ?? ('Document #' . $document['paperless_doc_id'])) ?>
    </h1>
    <p class="muted">
        Paperless #<?= e((string) $document['paperless_doc_id']) ?>
        · <span class="badge <?= $ready ? 'badge-ok' : 'badge-warn' ?>"><?= e(Document::label($status)) ?></span>
        <?php if (Extraction::wasEdited($extraction)): ?>
            · <span class="badge badge-info">edited by hand</span>
        <?php endif; ?>
        · <a href="<?= e(url('/documents/' . $documentId)) ?>">the full pipeline record</a>
    </p>
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
            attaches this PDF to it, and updates the Paperless document to match.
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
     */
    $preselected = $supplierRoute;
    $guess       = (string) ($extraction['doc_type'] ?? '');
    $reason      = $extraction['doc_type_reason'] ?? null;
    ?>
    <h2 class="section-title">Which is this?</h2>

    <div class="card card-warn">
        <h3>A credit note and a refund are not the same thing</h3>
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

<?php if ($unresolved !== []): ?>
    <h2 class="section-title">Not resolved yet</h2>

    <p class="muted">
        Each of these has to point at something real in Clear Books before the document can be
        submitted. <strong>Nothing here is created automatically</strong> — every button below
        opens a form you confirm first.
    </p>

    <?php foreach ($unresolved as $row): ?>
        <?php
        $matchId  = (int) $row['id'];
        $type     = (string) $row['entity_type'];
        $lineWord = $row['line_index'] === null ? '' : ' on line ' . ((int) $row['line_index'] + 1);

        $choices = match ($type) {
            EntityMatch::SUPPLIER      => $suppliers,
            EntityMatch::ACCOUNT_CODE  => $accountCodes,
            EntityMatch::VAT_RATE      => $vatRates,
            EntityMatch::VAT_TREATMENT => $vatTreatments,
            default                    => [],
        };
        ?>
        <div class="card card-warn">
            <h3><?= e(EntityMatch::label($type) . $lineWord) ?></h3>
            <p>
                Read off the document as <strong><?= e((string) $row['raw_value']) ?></strong>.
                <?php if ($row['note'] !== null): ?>
                    <span class="muted"><?= e((string) $row['note']) ?></span>
                <?php endif; ?>
            </p>

            <?php /* Picking something already on file is the common case and is
                     therefore first: most unmatched suppliers are on file under
                     a name the matching could not see. */ ?>
            <form method="post" action="<?= e(url('/review/' . $documentId . '/entity/' . $matchId . '/pick')) ?>"
                  class="inline-form">
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="pick-<?= e((string) $matchId) ?>">Use one already on file</label>
                    <div class="input-with-button">
                        <select class="input" id="pick-<?= e((string) $matchId) ?>" name="remote_id" required
                                <?= can('review.resolve') ? '' : 'disabled' ?>>
                            <?= $options($choices, null, '— choose —') ?>
                        </select>
                        <button type="submit" class="btn" <?= can('review.resolve') ? '' : 'disabled' ?>>Use this</button>
                    </div>
                    <?php if ($choices === []): ?>
                        <p class="field-hint text-danger">
                            Nothing of this kind is cached from Clear Books yet. Refresh the lists from
                            <a href="<?= e(url('/admin/clearbooks')) ?>">Settings → Clear Books</a> first.
                        </p>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($type === EntityMatch::SUPPLIER): ?>
                <details class="fieldset">
                    <summary class="btn btn-warning btn-inline">Create in Clear Books instead</summary>

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
            <?php elseif ($type === EntityMatch::VAT_RATE || $type === EntityMatch::VAT_TREATMENT): ?>
                <p class="field-hint">
                    VAT rates and treatments are defined by Clear Books and cannot be created through
                    its API — there is no endpoint for either. Pick the right one above, or add it in
                    Clear Books and refresh the cached lists.
                </p>
            <?php else: ?>
                <p class="field-hint">
                    A nominal code is part of the chart of accounts rather than a property of this
                    invoice, so it is not created from here. Add it in Clear Books, refresh the
                    cached lists, then pick it above.
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($notes !== []): ?>
    <div class="card card-warn">
        <h3><?= count($notes) ?> thing<?= count($notes) === 1 ? '' : 's' ?> to check</h3>
        <p class="muted">
            Raised by the pipeline. Each is a judgement it made but was not fully confident in.
            Correcting the field below clears the note on the next save; a note that is simply
            right needs no action beyond a look.
        </p>
        <ul class="plain-list review-notes">
            <?php foreach ($notes as $note): ?>
                <li><?= e($note) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<h2 class="section-title">The document</h2>

<div class="doc-split">
    <div class="doc-pane">
        <h3>The scan</h3>
        <?php if ($hasPdf): ?>
            <object class="pdf-frame" data="<?= e(url('/documents/' . $documentId . '/pdf')) ?>#view=FitH"
                    type="application/pdf">
                <p>
                    Your browser will not display the PDF here.
                    <a href="<?= e(url('/documents/' . $documentId . '/pdf')) ?>">Open it in a new tab</a>.
                </p>
            </object>
        <?php else: ?>
            <p class="empty">
                The stored PDF is missing from disk. Retry the document from
                <a href="<?= e(url('/documents/' . $documentId)) ?>">its pipeline record</a> to fetch it again.
            </p>
        <?php endif; ?>

        <?php if ($ocr !== null): ?>
            <h3>What was read</h3>
            <p class="field-hint">
                InvoGrid's own transcription, annotations included. This is what the extraction was
                worked out from, and what replaces Paperless's OCR text on submission.
            </p>
            <div class="ocr-text"><?= e(\App\Models\OcrResult::text($ocr)) ?></div>
        <?php endif; ?>
    </div>

    <div class="doc-pane">
        <form method="post" action="<?= e(url('/review/' . $documentId . '/save')) ?>" class="form">
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

            <div class="field">
                <label class="label" for="paperless_title">Title</label>
                <input class="input" id="paperless_title" name="paperless_title" maxlength="255"
                       value="<?= e((string) ($extraction['paperless_title'] ?? '')) ?>">
                <p class="field-hint">What the Paperless document gets renamed to on submission.</p>
            </div>

            <div class="field">
                <label class="label" for="cb_summary">Clear Books description</label>
                <input class="input" id="cb_summary" name="cb_summary" maxlength="255"
                       value="<?= e((string) ($extraction['cb_summary'] ?? '')) ?>">
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="doc_type">Type</label>
                    <select class="input" id="doc_type" name="doc_type">
                        <option value="">— not classified —</option>
                        <?php foreach ($docTypes as $type): ?>
                            <option value="<?= e((string) $type['type_key']) ?>"
                                <?= (string) ($extraction['doc_type'] ?? '') === (string) $type['type_key'] ? 'selected' : '' ?>>
                                <?= e((string) $type['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="label" for="invoice_number">Reference</label>
                    <input class="input" id="invoice_number" name="invoice_number" maxlength="100"
                           value="<?= e((string) ($extraction['invoice_number'] ?? '')) ?>">
                    <p class="field-hint">The issuer's own invoice number.</p>
                </div>
            </div>

            <div class="field">
                <label class="label" for="supplier_name_raw">Supplier</label>
                <input class="input" id="supplier_name_raw" name="supplier_name_raw" maxlength="255"
                       value="<?= e((string) ($extraction['supplier_name_raw'] ?? '')) ?>">
                <?php
                $supplierRow = null;
                foreach ($matches as $row) {
                    if ((string) $row['entity_type'] === EntityMatch::SUPPLIER) {
                        $supplierRow = $row;
                        break;
                    }
                }
                ?>
                <p class="field-hint">
                    <?php if ($supplierRow !== null && in_array((string) $supplierRow['status'], [EntityMatch::MATCHED, EntityMatch::CREATED], true)): ?>
                        <span class="badge badge-ok"><?= e((string) $supplierRow['status']) ?></span>
                        Clear Books <span class="mono"><?= e((string) $supplierRow['matched_id']) ?></span>
                        — <?= e((string) $supplierRow['matched_name']) ?>.
                        Editing this box changes the label only; use the picker above to point it elsewhere.
                    <?php else: ?>
                        Not resolved to a Clear Books supplier yet.
                    <?php endif; ?>
                </p>
            </div>

            <h3>Dates</h3>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="invoice_date">Invoice date</label>
                    <input class="input" id="invoice_date" name="invoice_date" type="date"
                           value="<?= e((string) ($extraction['invoice_date'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="due_date">Due</label>
                    <input class="input" id="due_date" name="due_date" type="date"
                           value="<?= e((string) ($extraction['due_date'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="paid_date">Paid</label>
                    <input class="input" id="paid_date" name="paid_date" type="date"
                           value="<?= e((string) ($extraction['paid_date'] ?? '')) ?>">
                </div>
            </div>

            <h3>Line items</h3>

            <p class="field-hint">
                Clear the description and amounts of a row to drop it — a scan that read the
                remittance slip as a line is the usual reason. The totals below recalculate from
                these unless you type one in yourself.
            </p>

            <div class="table-wrap">
                <table class="table table-compact">
                    <caption class="sr-only">Editable line items</caption>
                    <thead>
                        <tr>
                            <th scope="col">Description</th>
                            <th scope="col" class="amount">Qty</th>
                            <th scope="col" class="amount">Unit</th>
                            <th scope="col" class="amount">Net</th>
                            <th scope="col">Account code</th>
                            <th scope="col">VAT rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $index => $line): ?>
                            <?php if (!is_array($line)) {
                                continue;
                            } ?>
                            <tr>
                                <td>
                                    <textarea class="input" name="line_description[]" rows="2"
                                              aria-label="Line <?= e((string) ($index + 1)) ?> description"><?= e((string) ($line['description'] ?? '')) ?></textarea>
                                </td>
                                <td class="amount">
                                    <input class="input" name="line_quantity[]" inputmode="decimal" size="5"
                                           aria-label="Line <?= e((string) ($index + 1)) ?> quantity"
                                           value="<?= $line['quantity'] === null ? '' : e(rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.')) ?>">
                                </td>
                                <td class="amount">
                                    <input class="input" name="line_unit_price[]" inputmode="decimal" size="8"
                                           aria-label="Line <?= e((string) ($index + 1)) ?> unit price"
                                           value="<?= $line['unitPrice'] === null ? '' : e(number_format((float) $line['unitPrice'], 2, '.', '')) ?>">
                                </td>
                                <td class="amount">
                                    <input class="input" name="line_total[]" inputmode="decimal" size="8"
                                           aria-label="Line <?= e((string) ($index + 1)) ?> net"
                                           value="<?= $line['lineTotal'] === null ? '' : e(number_format((float) $line['lineTotal'], 2, '.', '')) ?>">
                                </td>
                                <td>
                                    <select class="input" name="line_account_code[]"
                                            aria-label="Line <?= e((string) ($index + 1)) ?> account code">
                                        <?= $options($accountCodes, $line['accountCode'] ?? null) ?>
                                    </select>
                                </td>
                                <td>
                                    <select class="input" name="line_vat_rate[]"
                                            aria-label="Line <?= e((string) ($index + 1)) ?> VAT rate">
                                        <?= $options($vatRates, $line['vatRateKey'] ?? null) ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php /* One blank row, so adding a missed line does not need
                                 JavaScript or a second round trip. */ ?>
                        <tr>
                            <td>
                                <textarea class="input" name="line_description[]" rows="2"
                                          placeholder="Add a line…" aria-label="New line description"></textarea>
                            </td>
                            <td class="amount"><input class="input" name="line_quantity[]" inputmode="decimal" size="5" aria-label="New line quantity"></td>
                            <td class="amount"><input class="input" name="line_unit_price[]" inputmode="decimal" size="8" aria-label="New line unit price"></td>
                            <td class="amount"><input class="input" name="line_total[]" inputmode="decimal" size="8" aria-label="New line net"></td>
                            <td><select class="input" name="line_account_code[]" aria-label="New line account code"><?= $options($accountCodes, null) ?></select></td>
                            <td><select class="input" name="line_vat_rate[]" aria-label="New line VAT rate"><?= $options($vatRates, null) ?></select></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3>Totals</h3>

            <div class="field">
                <label class="label" for="vat_treatment">VAT treatment</label>
                <select class="input" id="vat_treatment" name="vat_treatment">
                    <?= $options($vatTreatments, $treatment['key'] ?? null) ?>
                </select>
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="net_amount">Net</label>
                    <input class="input" id="net_amount" name="net_amount" inputmode="decimal"
                           value="<?= $extraction['net_amount'] === null ? '' : e(number_format((float) $extraction['net_amount'], 2, '.', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="vat_amount">VAT</label>
                    <input class="input" id="vat_amount" name="vat_amount" inputmode="decimal"
                           value="<?= $extraction['vat_amount'] === null ? '' : e(number_format((float) $extraction['vat_amount'], 2, '.', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="gross_amount">Gross</label>
                    <input class="input" id="gross_amount" name="gross_amount" inputmode="decimal"
                           value="<?= $extraction['gross_amount'] === null ? '' : e(number_format((float) $extraction['gross_amount'], 2, '.', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="currency">Currency</label>
                    <input class="input" id="currency" name="currency" maxlength="3" size="4"
                           value="<?= e((string) ($currency ?? '')) ?>" placeholder="GBP">
                </div>
            </div>

            <?php if ($customFields !== []): ?>
                <h3>Custom fields</h3>
                <p class="field-hint">Read off the page — usually handwritten. Blank is a normal answer.</p>

                <?php foreach ($customFields as $field): ?>
                    <?php
                    $key   = (string) $field['field_key'];
                    $value = $customValues[$key] ?? null;
                    $type  = (string) $field['data_type'];
                    ?>
                    <div class="field">
                        <label class="label" for="custom_<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                        <input class="input" id="custom_<?= e($key) ?>" name="custom_<?= e($key) ?>"
                               type="<?= $type === 'date' ? 'date' : 'text' ?>"
                               value="<?= is_scalar($value) ? e((string) $value) : '' ?>">
                        <?php if ($field['paperless_field_id'] === null): ?>
                            <p class="field-hint">Not yet paired with a Paperless field, so it will not be written back.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
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

<div class="card">
    <p>
        A duplicate, a statement rather than an invoice, a delivery note that came in on the same
        scan run. Skipping takes it out of the queue for good — it stays in Paperless and can be put
        back from its pipeline record.
    </p>

    <form method="post" action="<?= e(url('/review/' . $documentId . '/ignore')) ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label class="label" for="reason">Why</label>
            <input class="input" id="reason" name="reason" required minlength="3" maxlength="900"
                   placeholder="e.g. duplicate of Paperless #412"
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
