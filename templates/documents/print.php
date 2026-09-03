<?php

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EntityMatch;

/**
 * One document, summarised for paper.
 *
 * What was read off the page, and what Clear Books did with it. Deliberately
 * one sheet: this is the thing somebody prints and staples to an invoice, or
 * hands to an accountant asking why a bill was coded the way it was. Anything
 * that needs a second page is a screen, not a summary.
 *
 * @var array<string,mixed>            $document
 * @var array<string,mixed>            $extraction
 * @var array<int,array<string,mixed>> $lines
 * @var array<string,mixed>            $customValues
 * @var array<int,array<string,mixed>> $customFields
 * @var array<int,array<string,mixed>> $matches
 * @var array<string,mixed>|null       $submitted
 * @var array<int,string>              $reviewNotes
 */

$currency = (string) ($extraction['currency'] ?? 'GBP');

/** Money, with the document's own currency. */
$money = static function (mixed $value) use ($currency): string {
    if ($value === null || $value === '') {
        return '—';
    }

    $symbol = match (strtoupper($currency)) {
        'GBP'   => '£',
        'EUR'   => '€',
        'USD'   => '$',
        default => '',
    };

    return $symbol . number_format((float) $value, 2)
        . ($symbol === '' ? ' ' . strtoupper($currency) : '');
};

$date = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '—';
    }

    $time = strtotime((string) $value);

    return $time === false ? (string) $value : date('j M Y', $time);
};

$plain = static fn (mixed $value): string => ($value === null || trim((string) $value) === '')
    ? '—'
    : (string) $value;

/** The matched name for an entity, so a line reads "Office costs" not "112". */
$matchedName = static function (string $type, ?int $lineIndex) use ($matches): ?string {
    foreach ($matches as $match) {
        if ((string) $match['entity_type'] !== $type) {
            continue;
        }

        $index = $match['line_index'] === null ? null : (int) $match['line_index'];

        if ($index === $lineIndex && $match['matched_name'] !== null) {
            return (string) $match['matched_name'];
        }
    }

    return null;
};
?>

<h1 class="print-title">
    <?= e($plain($extraction['document_title'] ?? null)) ?>
</h1>

<p class="print-subtitle">
    <?= e(DocumentType::label($extraction['doc_type'] ?? null)) ?>
    · Document #<?= (int) $document['id'] ?>
    · <?= e(Document::label((string) $document['status'])) ?>
</p>

<?php /* The Clear Books outcome first, because it is what somebody printing
         this actually wants to be able to point at. */ ?>
<?php if ($submitted !== null): ?>
    <section class="print-block print-block-key">
        <h2>In Clear Books</h2>
        <dl class="print-pairs">
            <div><dt>Record</dt><dd><?= e((string) $submitted['clearbooks_type']) ?> <?= e((string) $submitted['clearbooks_id']) ?></dd></div>
            <div><dt>Submitted</dt><dd><?= e(format_datetime((string) $submitted['submitted_at'])) ?></dd></div>
            <?php /* Omitted rather than dashed when nobody is recorded. A printed
                     sheet reading "By —" invites the question "by whom?", which is
                     exactly the question it cannot answer; leaving the row out says
                     the same thing without pretending to. */ ?>
            <?php if (trim((string) ($submitted['display_name'] ?? '')) !== ''): ?>
                <div><dt>By</dt><dd><?= e((string) $submitted['display_name']) ?></dd></div>
            <?php endif; ?>
        </dl>
    </section>
<?php else: ?>
    <section class="print-block print-block-pending">
        <h2>Not yet in Clear Books</h2>
        <p>
            This document has not been submitted. Everything below is what InvoGrid read off the
            page, not what any accounting record says.
        </p>
    </section>
<?php endif; ?>

<section class="print-block">
    <h2>The document</h2>
    <dl class="print-pairs">
        <div><dt>Supplier</dt><dd><?= e($plain($matchedName(EntityMatch::SUPPLIER, null) ?? $extraction['supplier_name_raw'] ?? null)) ?></dd></div>
        <div><dt>Their reference</dt><dd><?= e($plain($extraction['invoice_number'] ?? null)) ?></dd></div>
        <div><dt>Dated</dt><dd><?= e($date($extraction['invoice_date'] ?? null)) ?></dd></div>
        <div><dt>Due</dt><dd><?= e($date($extraction['due_date'] ?? null)) ?></dd></div>
        <?php if (($extraction['paid_date'] ?? null) !== null): ?>
            <div><dt>Paid</dt><dd><?= e($date($extraction['paid_date'])) ?></dd></div>
        <?php endif; ?>
        <div><dt>Description</dt><dd><?= e($plain($extraction['cb_summary'] ?? null)) ?></dd></div>
    </dl>
</section>

<?php if ($lines !== []): ?>
    <section class="print-block">
        <h2>Lines</h2>

        <table class="table table-print">
            <thead>
                <tr>
                    <th scope="col">Description</th>
                    <th scope="col">Account</th>
                    <th scope="col">VAT</th>
                    <th scope="col" class="amount">Net</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $index => $line): ?>
                    <tr>
                        <td><?= e($plain($line['description'] ?? null)) ?></td>
                        <td><?= e($plain($matchedName(EntityMatch::ACCOUNT_CODE, $index) ?? ($line['accountCode'] ?? null))) ?></td>
                        <td><?= e($plain($matchedName(EntityMatch::VAT_RATE, $index) ?? ($line['vatRate'] ?? null))) ?></td>
                        <?php /* `lineTotal` is the key the extraction prompt asks for and
                                 the one every other reader uses; the fallbacks are for a
                                 row written by an older prompt version. */ ?>
                        <td class="amount"><?= e($money($line['lineTotal'] ?? $line['total'] ?? $line['net'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<section class="print-block">
    <h2>Totals</h2>
    <dl class="print-pairs print-pairs-totals">
        <div><dt>Net</dt><dd><?= e($money($extraction['net_amount'] ?? null)) ?></dd></div>
        <div><dt>VAT</dt><dd><?= e($money($extraction['vat_amount'] ?? null)) ?></dd></div>
        <div class="print-total"><dt>Gross</dt><dd><?= e($money($extraction['gross_amount'] ?? null)) ?></dd></div>
    </dl>

    <?php $treatment = $matchedName(EntityMatch::VAT_TREATMENT, null); ?>
    <?php if ($treatment !== null): ?>
        <p class="print-note-line">VAT treatment: <?= e($treatment) ?>.</p>
    <?php endif; ?>
</section>

<?php
// Only fields that actually have a value: a printed sheet listing eight empty
// rows tells the reader nothing and costs them the page.
$filled = [];

foreach ($customFields as $field) {
    $value = $customValues[(string) $field['field_key']] ?? null;

    if ($value !== null && trim((string) $value) !== '') {
        $filled[] = ['label' => (string) $field['label'], 'value' => (string) $value];
    }
}
?>
<?php if ($filled !== []): ?>
    <section class="print-block">
        <h2>Also read off the page</h2>
        <dl class="print-pairs">
            <?php foreach ($filled as $field): ?>
                <div><dt><?= e($field['label']) ?></dt><dd><?= e($field['value']) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endif; ?>

<?php if ($reviewNotes !== []): ?>
    <section class="print-block">
        <h2>Flagged when read</h2>
        <p class="print-note-line">
            Things the pipeline was not fully confident about. Each was seen by a person before
            this document was submitted.
        </p>
        <ul class="print-list">
            <?php foreach ($reviewNotes as $note): ?>
                <li><?= e((string) $note) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
