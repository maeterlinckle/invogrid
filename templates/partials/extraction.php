<?php

use App\Models\ClearbooksCache;
use App\Models\CustomField;
use App\Models\DocumentType;
use App\Models\EntityMatch;
use App\Models\Extraction;
use App\Services\FieldIssues;

/**
 * What the three extraction calls made of a document.
 *
 * Display only — this is the pipeline record, and the place to change any of
 * it is the review screen. It is laid out as read-only *fields* rather than as
 * a table of values so it reads as the same screen: same labels, same grouping,
 * same order, and now the same marks.
 *
 * **Every value the pipeline was unsure of is marked on the value itself.** The
 * card this replaces listed the review notes at the top of a page carrying
 * forty values, leaving the reader to work out which ones were meant. What is
 * left at the top is only the handful of notes that name no field at all —
 * a Clear Books list that has never been synced, say — because there is nowhere
 * honest to put those. `App\Services\FieldIssues` does the attribution and the
 * review screen uses the same object, so the two screens cannot come to
 * different conclusions about which value is doubtful.
 *
 * @var array<string,mixed>            $extraction
 * @var array<int,array<string,mixed>> $matches   Empty until the matching stage has run
 * @var App\Services\FieldIssues|null  $issues
 */
$lines    = Extraction::decode($extraction, 'line_items');
$supplier = Extraction::decode($extraction, 'supplier_match');
$treatment = Extraction::decode($extraction, 'vat_treatment');
$custom   = Extraction::decode($extraction, 'custom_field_values');

$currency = $extraction['currency'] ?? null;

// The custom fields are shown by their configured label, and every active field
// appears whether or not a value was found — a field silently missing from the
// list looks like it was never asked for.
$customFields = CustomField::extracted();

$matches = $matches ?? [];

// Built here when a caller has not passed one, so the partial is still correct
// on its own rather than quietly dropping every mark.
$issues = $issues ?? FieldIssues::build($extraction, $matches, $customFields);

/** A read-only field, shaped like the input that replaces it on the review screen. */
$field = static function (string $label, ?string $value, string $hint = '', string $key = '') use ($issues): string {
    $tone  = $key === '' ? null : $issues->tone($key);
    $notes = $key === '' ? [] : $issues->on($key);

    return '<div class="field field-readonly' . flag_class($tone) . '">'
        . '<span class="label flag-label">' . e($label) . ' ' . flag_tag($tone) . '</span>'
        . '<span class="field-value' . ($value === null || $value === '' ? ' is-empty' : '') . '">'
        . ($value === null || $value === '' ? 'not found' : e($value))
        . '</span>'
        . flag_notes($notes)
        . ($hint === '' ? '' : '<p class="field-hint">' . e($hint) . '</p>')
        . '</div>';
};

/*
 * Whether the *matching* stage settled the supplier, which is not the same
 * question as whether the extraction call did. The deterministic name pass
 * resolves plenty of the ones a model leaves open, and a card still saying "no
 * match on file" beside a table saying "matched on the name" reads as a
 * contradiction rather than as two stages.
 */
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

// The name Clear Books holds, rather than the one read off the letterhead:
// they differ often enough — "Ltd" against "Limited", a trading name against a
// legal one — that showing the wrong one makes a correct match look wrong.
$cachedSupplier = $matchedId === null
    ? null
    : ClearbooksCache::find(ClearbooksCache::SUPPLIER, $matchedId);
?>

<?php if ($issues->any()): ?>
    <div class="issue-index">
        <?php if ($issues->fieldCount() > 0): ?>
            <h3>
                <?= $issues->fieldCount() ?>
                value<?= $issues->fieldCount() === 1 ? '' : 's' ?> the pipeline was not sure of
            </h3>
            <p class="muted">
                Each is marked on the value itself below, with what was said about it. Red is
                something that stands between this document and Clear Books; amber is a judgement
                a stage made but was not fully confident in — not necessarily a mistake.
                <?php if (can('queue.view')): ?>
                    Correcting any of it is done on
                    <a href="<?= e(url('/review/' . (int) $extraction['document_id'])) ?>">the review screen</a>.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($issues->unplaced() !== []): ?>
            <?php if ($issues->fieldCount() === 0): ?>
                <h3>Nothing is flagged on a particular value</h3>
            <?php endif; ?>
            <p class="field-hint">
                <?= count($issues->unplaced()) === 1 ? 'One thing was' : count($issues->unplaced()) . ' things were' ?>
                raised that name no particular value:
            </p>
            <ul class="plain-list review-notes">
                <?php foreach ($issues->unplaced() as $issue): ?>
                    <li><?= e($issue['text']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card-grid">
    <div class="card">
        <h3>The document</h3>
        <?= $field('Title', $extraction['document_title'] ?? null, '', 'document_title') ?>
        <?= $field('Clear Books description', $extraction['cb_summary'] ?? null, '', 'cb_summary') ?>
        <?= $field('Type', DocumentType::label($extraction['doc_type'] ?? null), '', 'doc_type') ?>
        <?= $field('Reference', $extraction['invoice_number'] ?? null, 'The issuer\'s own invoice or bill number.', 'invoice_number') ?>
        <?= $field('Currency', $currency === null ? 'GBP' : (string) $currency, $currency === null ? 'No other currency was indicated.' : '', 'currency') ?>
    </div>

    <div class="card">
        <h3>Dates</h3>
        <?= $field('Invoice date', $extraction['invoice_date'] === null ? null : format_date((string) $extraction['invoice_date']), '', 'invoice_date') ?>
        <?= $field('Due', $extraction['due_date'] === null ? null : format_date((string) $extraction['due_date']), '', 'due_date') ?>
        <?= $field('Paid', $extraction['paid_date'] === null ? null : format_date((string) $extraction['paid_date']), $extraction['paid_date'] === null ? 'Nothing on the document says it has been paid.' : '', 'paid_date') ?>
    </div>

    <div class="card">
        <h3>Totals</h3>
        <?= $field('Net', $extraction['net_amount'] === null ? null : format_money($extraction['net_amount'], $currency), '', 'net_amount') ?>
        <?= $field('VAT', $extraction['vat_amount'] === null ? null : format_money($extraction['vat_amount'], $currency),
            $extraction['vat_amount'] === null ? 'Needs the cached Clear Books VAT rates to work out.' : '', 'vat_amount') ?>
        <?= $field('Gross', $extraction['gross_amount'] === null ? null : format_money($extraction['gross_amount'], $currency), '', 'gross_amount') ?>
        <?php if ($treatment !== []): ?>
            <?= $field('VAT treatment', (string) ($treatment['name'] ?? $treatment['key'] ?? ''), '', 'vat_treatment') ?>
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
            <li><strong>On file as</strong> <?= e($cachedSupplier === null ? '—' : (string) $cachedSupplier['name']) ?></li>
        </ul>

        <?= flag_notes($issues->on('supplier_name_raw')) ?>

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

        <?= flag_notes($issues->on('supplier_name_raw')) ?>

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

<h3 class="section-title">Line items <?= flag_tag($issues->tone('lines')) ?></h3>

<?= flag_notes($issues->on('lines')) ?>

<div class="table-wrap">
    <table class="table table-compact table-lines">
        <caption class="sr-only">Extracted line items with their account code and VAT rate</caption>
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
            <?php if ($lines === []): ?>
                <tr><td class="empty" colspan="6">No line items were found on this document.</td></tr>
            <?php else: ?>
                <?php foreach ($lines as $index => $line): ?>
                    <?php if (!is_array($line)) {
                        continue;
                    } ?>
                    <?php
                    // A cell's mark, and the whole-row mark drawn on the
                    // description because a line total that does not follow
                    // from the quantity belongs to no single column.
                    $cell    = static fn (string $column): ?string => $issues->tone('line.' . $index . '.' . $column);
                    $rowTone = $cell('row');
                    ?>
                    <tr>
                        <td class="break line-description col-desc<?= flag_class($cell('description') ?? $rowTone) ?>">
                            <?= nl2br(e((string) ($line['description'] ?? ''))) ?>
                            <?= flag_notes(array_merge($issues->onLine((int) $index, 'description'), $issues->onLine((int) $index, 'row'))) ?>
                        </td>
                        <td class="amount<?= flag_class($cell('quantity')) ?>"><?= ($line['quantity'] ?? null) === null ? '—' : e(rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.')) ?></td>
                        <td class="amount<?= flag_class($cell('unit_price')) ?>"><?= ($line['unitPrice'] ?? null) === null ? '—' : e(number_format((float) $line['unitPrice'], 2)) ?></td>
                        <td class="amount<?= flag_class($cell('total')) ?>">
                            <?= ($line['lineTotal'] ?? null) === null ? '—' : e(number_format((float) $line['lineTotal'], 2)) ?>
                            <?= flag_notes($issues->onLine((int) $index, 'total')) ?>
                        </td>
                        <td class="<?= trim(flag_class($cell('account_code'))) ?>">
                            <?php if (($line['accountCode'] ?? null) === null || $line['accountCode'] === ''): ?>
                                <span class="badge badge-danger">missing</span>
                            <?php else: ?>
                                <span class="badge badge-muted mono"><?= e((string) $line['accountCode']) ?></span>
                            <?php endif; ?>
                            <?= flag_notes($issues->onLine((int) $index, 'account_code')) ?>
                        </td>
                        <td class="<?= trim(flag_class($cell('vat_rate'))) ?>">
                            <?php if (($line['vatRateKey'] ?? null) === null || $line['vatRateKey'] === ''): ?>
                                <span class="badge badge-danger">missing</span>
                            <?php else: ?>
                                <span class="badge badge-muted mono"><?= e((string) $line['vatRateKey']) ?></span>
                            <?php endif; ?>
                            <?= flag_notes($issues->onLine((int) $index, 'vat_rate')) ?>
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
                    '',
                    'custom_' . $key
                ) ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<p class="muted">
    Extracted <?= e(format_datetime((string) $extraction['created_at'])) ?>
    by <?= e((string) ($extraction['llm_provider'] ?? 'unknown')) ?>
    · <?= e((string) ($extraction['llm_model'] ?? 'unknown')) ?>.
    <?php if (can('queue.view')): ?>
        Change any of it on <a href="<?= e(url('/review/' . (int) $extraction['document_id'])) ?>">the review screen</a>.
    <?php endif; ?>
</p>
