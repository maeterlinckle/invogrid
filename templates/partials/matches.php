<?php

use App\Models\EntityMatch;

/**
 * What each thing on the document resolved to in Clear Books.
 *
 * The point of showing this separately from the extracted fields is that these
 * are the rows standing between the document and a submission. "Needs review"
 * on its own tells a person nothing; "the account code on line 2 is not in the
 * chart of accounts" tells them what to go and do.
 *
 * Unresolved rows are listed first and marked, because they are the reason
 * anybody opened the page.
 *
 * @var array<int,array<string,mixed>> $matches
 */
$unresolved = array_values(array_filter(
    $matches,
    static fn (array $row): bool => in_array((string) $row['status'], [EntityMatch::UNMATCHED, EntityMatch::REJECTED], true)
));

/** How a match was arrived at, said plainly. */
$via = static function (?string $value): string {
    return match ($value) {
        EntityMatch::VIA_LLM      => 'read from the document',
        EntityMatch::VIA_FALLBACK => 'matched on the name',
        EntityMatch::VIA_MANUAL   => 'chosen by a person',
        default                   => '',
    };
};
?>

<div class="card <?= $unresolved === [] ? 'card-ok' : 'card-warn' ?>">
    <h3>
        <?php if ($unresolved === []): ?>
            Everything resolved
        <?php else: ?>
            <?= count($unresolved) ?> of <?= count($matches) ?> still unresolved
        <?php endif; ?>
    </h3>
    <p class="muted">
        <?php if ($unresolved === []): ?>
            Every supplier, account code and VAT rate on this document exists in Clear Books as it
            stands. Nothing here needs a decision.
        <?php else: ?>
            Each of these has to point at something real in Clear Books before the document can be
            submitted. Nothing is created automatically — that is a decision for a person.
        <?php endif; ?>
    </p>
</div>

<div class="table-wrap">
    <table class="table table-compact">
        <caption class="sr-only">Entities on this document and what each resolved to in Clear Books</caption>
        <thead>
            <tr>
                <th scope="col">What</th>
                <th scope="col">Read as</th>
                <th scope="col">Resolved to</th>
                <th scope="col">How</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($matches as $row): ?>
                <?php $resolved = (string) $row['status'] === EntityMatch::MATCHED || (string) $row['status'] === EntityMatch::CREATED; ?>
                <tr>
                    <th scope="row">
                        <?= e(EntityMatch::label((string) $row['entity_type'])) ?>
                        <?php if ($row['line_index'] !== null): ?>
                            <span class="muted">line <?= e((string) ((int) $row['line_index'] + 1)) ?></span>
                        <?php endif; ?>
                    </th>
                    <td class="break"><?= e((string) $row['raw_value']) ?></td>
                    <td class="break">
                        <?php if ($resolved): ?>
                            <span class="badge badge-ok"><?= e((string) $row['status']) ?></span>
                            <?= e((string) ($row['matched_name'] ?? '')) ?>
                            <span class="mono muted"><?= e((string) ($row['matched_id'] ?? '')) ?></span>
                        <?php else: ?>
                            <span class="badge badge-danger">not matched</span>
                            <?php if ($row['note'] !== null): ?>
                                <span class="muted"><?= e((string) $row['note']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $how = $via($row['matched_via'] === null ? null : (string) $row['matched_via']); ?>
                        <?php if ($how !== ''): ?>
                            <span class="muted"><?= e($how) ?></span>
                            <?php /* Anything short of certain is worth saying so on the row rather
                                     than only in the review notes: the looser name pass ignores
                                     word boundaries, and that is where a wrong match would come
                                     from if one ever did. */ ?>
                            <?php if ($row['confidence'] !== null && (float) $row['confidence'] < 1.0): ?>
                                <span class="badge badge-warn">not certain</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
