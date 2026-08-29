<?php

use App\Models\ClearbooksCache;
use App\Models\CustomField;
use App\Models\DocumentType;
use App\Models\EntityMatch;
use App\Models\Extraction;

/**
 * What the three extraction calls made of a document.
 *
 * Display only — editing lands in a later stage. It is laid out as read-only
 * *fields* rather than as a table of values so that turning it into a form is
 * swapping each `.field-value` for an input, not rebuilding the screen: the
 * labels, grouping and order are already the ones a person will edit in.
 *
 * @var array<string,mixed>           $extraction
 * @var array<int,array<string,mixed>> $matches   Empty until the matching stage has run
 */
$notes    = Extraction::reviewNotes($extraction);
$lines    = Extraction::decode($extraction, 'line_items');
$supplier = Extraction::decode($extraction, 'supplier_match');
$treatment = Extraction::decode($extraction, 'vat_treatment');
$custom   = Extraction::decode($extraction, 'custom_field_values');

$currency = $extraction['currency'] ?? null;

/** A read-only field, shaped like the input that will replace it. */
$field = static function (string $label, ?string $value, string $hint = '') use (&$field): string {
    return '<div class="field field-readonly">'
        . '<span class="label">' . e($label) . '</span>'
        . '<span class="field-value' . ($value === null || $value === '' ? ' is-empty' : '') . '">'
        . ($value === null || $value === '' ? 'not found' : e($value))
        . '</span>'
        . ($hint === '' ? '' : '<p class="field-hint">' . e($hint) . '</p>')
        . '</div>';
};

// The custom fields are shown by their configured label, and every active field
// appears whether or not a value was found — a field silently missing from the
// list looks like it was never asked for.
$customFields = CustomField::extracted();

/*
 * Whether the *matching* stage settled the supplier, which is not the same
 * question as whether the extraction call did. The deterministic name pass
 * resolves plenty of the ones a model leaves open, and a card still saying "no
 * match on file" beside a table saying "matched on the name" reads as a
 * contradiction rather than as two stages.
 */
$matches         = $matches ?? [];
$supplierMatched = !empty($supplier['supplierMatched']);
$matchedVia      = null;
$matchedId       = is_scalar($supplier['cbId'] ?? null) ? (string) $supplier['cbId'] : null;
$matchedName     = null;

foreach ($matches as $row) {
    if ((string) $row['entity_type'] !== EntityMatch::SUPPLIER) {
        continue;
    }

    $supplierMatched = in_array((string) $row['status'], [EntityMatch::MATCHED, EntityMatch::CREATED], true);
    $matchedVia      = $row['matched_via'] === null ? null : (string) $row['matched_via'];
    $matchedId       = $row['matched_id'] === null ? null : (string) $row['matched_id'];
    $matchedName     = $row['matched_name'] === null ? null : (string) $row['matched_name'];
    break;
}

// The Paperless correspondent comes from the cache rather than from what the
// extraction call reported: a supplier the code fallback matched has no
// `paperlessId` in the model's answer, and showing "—" beside a supplier that
// does have a correspondent reads as "there isn't one".
$correspondentId = is_scalar($supplier['paperlessId'] ?? null) ? (string) $supplier['paperlessId'] : null;

if ($matchedId !== null) {
    $cachedSupplier  = ClearbooksCache::find(ClearbooksCache::SUPPLIER, $matchedId);
    $correspondentId = $cachedSupplier === null || $cachedSupplier['paperless_correspondent_id'] === null
        ? $correspondentId
        : (string) $cachedSupplier['paperless_correspondent_id'];
}
?>

<?php if ($notes !== []): ?>
    <?php /* Above everything else: these are the reason the document is in the
             queue at all, and burying them under the fields they refer to means
             they get scrolled past. */ ?>
    <div class="card card-warn">
        <h2><?= count($notes) ?> thing<?= count($notes) === 1 ? '' : 's' ?> to check</h2>
        <p class="muted">
            Raised by the extraction calls themselves. Each one is a judgement the
            model made but was not fully confident in — not necessarily a mistake.
        </p>
        <ul class="plain-list review-notes">
            <?php foreach ($notes as $note): ?>
                <li><?= e($note) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card-grid">
    <div class="card">
        <h3>The document</h3>
        <?= $field('Paperless title', $extraction['paperless_title'] ?? null) ?>
        <?= $field('Clear Books description', $extraction['cb_summary'] ?? null) ?>
        <?= $field('Type', DocumentType::label($extraction['doc_type'] ?? null)) ?>
        <?= $field('Reference', $extraction['invoice_number'] ?? null, 'The issuer\'s own invoice or bill number.') ?>
        <?= $field('Currency', $currency === null ? 'GBP' : (string) $currency, $currency === null ? 'No other currency was indicated.' : '') ?>
    </div>

    <div class="card">
        <h3>Dates</h3>
        <?= $field('Invoice date', $extraction['invoice_date'] === null ? null : format_date((string) $extraction['invoice_date'])) ?>
        <?= $field('Due', $extraction['due_date'] === null ? null : format_date((string) $extraction['due_date'])) ?>
        <?= $field('Paid', $extraction['paid_date'] === null ? null : format_date((string) $extraction['paid_date']), $extraction['paid_date'] === null ? 'Nothing on the document says it has been paid.' : '') ?>
    </div>

    <div class="card">
        <h3>Totals</h3>
        <?= $field('Net', $extraction['net_amount'] === null ? null : format_money($extraction['net_amount'], $currency)) ?>
        <?= $field('VAT', $extraction['vat_amount'] === null ? null : format_money($extraction['vat_amount'], $currency),
            $extraction['vat_amount'] === null ? 'Needs the cached Clear Books VAT rates to work out.' : '') ?>
        <?= $field('Gross', $extraction['gross_amount'] === null ? null : format_money($extraction['gross_amount'], $currency)) ?>
        <?php if ($treatment !== []): ?>
            <?= $field('VAT treatment', (string) ($treatment['name'] ?? $treatment['key'] ?? '')) ?>
        <?php endif; ?>
    </div>
</div>

<h3 class="section-title">Supplier</h3>

<div class="card <?= $supplierMatched ? 'card-ok' : 'card-warn' ?>">
    <?php if ($supplierMatched): ?>
        <p>
            <span class="badge badge-ok">Matched</span>
            <strong><?= e($matchedName ?? $extraction['supplier_name_raw'] ?? 'on file') ?></strong>
        </p>
        <ul class="meta-list">
            <li><strong>Clear Books id</strong> <span class="mono"><?= e($matchedId ?? '—') ?></span></li>
            <li><strong>Paperless correspondent</strong> <span class="mono"><?= e($correspondentId ?? '—') ?></span></li>
        </ul>

        <?php if ($matchedVia === EntityMatch::VIA_FALLBACK): ?>
            <p class="field-hint">
                The extraction call found nothing; this was settled afterwards by comparing the
                names with case, punctuation and "Ltd"/"Limited" ignored.
            </p>
        <?php elseif ($matchedVia === EntityMatch::VIA_MANUAL): ?>
            <p class="field-hint">Chosen by a person, and kept through any re-match.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>
            <span class="badge badge-warn">No match on file</span>
            <strong><?= e($extraction['supplier_name_raw'] ?? 'the issuer') ?></strong>
        </p>
        <p class="muted">
            Nothing in the cached Clear Books supplier list matched this issuer. Creating it is
            a decision for a person — nothing is created automatically.
        </p>

        <div class="card-grid">
            <div>
                <?= $field('Legal name', is_string($supplier['name'] ?? null) ? $supplier['name'] : null) ?>
                <?= $field('VAT number', is_string($supplier['vatNumber'] ?? null) ? $supplier['vatNumber'] : null) ?>
                <?= $field('Company number', is_string($supplier['companyNumber'] ?? null) ? $supplier['companyNumber'] : null) ?>
            </div>
            <div>
                <?= $field('Address', is_string($supplier['address'] ?? null) ? $supplier['address'] : null) ?>
                <?php
                $trading = is_array($supplier['tradingNames'] ?? null) ? $supplier['tradingNames'] : [];
                $trading = array_values(array_filter($trading, 'is_string'));
                ?>
                <?= $field(
                    'Also trading as',
                    $trading === [] ? null : implode(', ', $trading),
                    $trading === [] ? '' : 'Names this issuer also uses. Worth adding as aliases.'
                ) ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<h3 class="section-title">Line items</h3>

<div class="table-wrap">
    <table class="table table-compact">
        <caption class="sr-only">Extracted line items with their account code and VAT rate</caption>
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
            <?php if ($lines === []): ?>
                <tr><td class="empty" colspan="6">No line items were found on this document.</td></tr>
            <?php else: ?>
                <?php foreach ($lines as $line): ?>
                    <?php if (!is_array($line)) {
                        continue;
                    } ?>
                    <tr>
                        <td class="break line-description"><?= nl2br(e((string) ($line['description'] ?? ''))) ?></td>
                        <td class="amount"><?= $line['quantity'] === null ? '—' : e(rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.')) ?></td>
                        <td class="amount"><?= $line['unitPrice'] === null ? '—' : e(number_format((float) $line['unitPrice'], 2)) ?></td>
                        <td class="amount"><?= $line['lineTotal'] === null ? '—' : e(number_format((float) $line['lineTotal'], 2)) ?></td>
                        <td>
                            <?php if (($line['accountCode'] ?? null) === null || $line['accountCode'] === ''): ?>
                                <span class="badge badge-danger">missing</span>
                            <?php else: ?>
                                <span class="badge badge-muted mono"><?= e((string) $line['accountCode']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($line['vatRateKey'] ?? null) === null || $line['vatRateKey'] === ''): ?>
                                <span class="badge badge-danger">missing</span>
                            <?php else: ?>
                                <span class="badge badge-muted mono"><?= e((string) $line['vatRateKey']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <?php if ($extraction['net_amount'] !== null): ?>
            <tfoot>
                <tr>
                    <th scope="row" colspan="3">Net total</th>
                    <td class="amount"><?= e(number_format((float) $extraction['net_amount'], 2)) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php if ($customFields !== []): ?>
    <h3 class="section-title">Custom fields</h3>

    <div class="card">
        <p class="muted">
            Read off the page rather than from the invoice body — usually handwritten.
            A blank one is a normal and expected answer; these are never guessed at.
        </p>
        <div class="card-grid">
            <?php foreach ($customFields as $configured): ?>
                <?php
                $key   = (string) $configured['field_key'];
                $value = $custom[$key] ?? null;
                ?>
                <?= $field(
                    (string) $configured['label'],
                    is_scalar($value) ? (string) $value : null,
                    $configured['paperless_field_id'] === null
                        ? 'Not yet paired with a Paperless field.'
                        : ''
                ) ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<p class="muted">
    Extracted <?= e(format_datetime((string) $extraction['created_at'])) ?>
    by <?= e((string) ($extraction['llm_provider'] ?? 'unknown')) ?>
    · <?= e((string) ($extraction['llm_model'] ?? 'unknown')) ?>.
    Editing lands in a later build; for now this is what was read.
</p>
