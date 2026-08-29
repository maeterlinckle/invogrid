<?php

use App\Models\CustomField;

/**
 * The custom fields the pipeline reads off a page.
 *
 * @var array<int,array<string,mixed>> $fields
 * @var array<int,array<string,mixed>> $paperlessFields  Keyed by Paperless id
 * @var string|null                    $paperlessError
 */
?>

<div class="page-head">
    <h1>Custom fields</h1>
    <p class="muted">
        Values read off the page that are not part of an invoice's ordinary structure — a
        handwritten reference, a circled project code. Adding one here means the next document
        extracted is asked about it. No deploy, and nothing to add to a prompt by hand.
    </p>
</div>

<div class="form-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/fields/new')) ?>">Add a field</a>
</div>

<?php if ($paperlessError !== null): ?>
    <div class="card card-warn">
        <p><?= e($paperlessError) ?></p>
        <p class="field-hint">
            Fields can still be defined; they simply cannot be paired with a Paperless field until
            it can be reached, and an unpaired field is not written back on submission.
        </p>
    </div>
<?php endif; ?>

<?php
$extracted  = array_values(array_filter($fields, static fn (array $f): bool => (string) $f['source'] === CustomField::EXTRACTED));
$produced   = array_values(array_filter($fields, static fn (array $f): bool => (string) $f['source'] === CustomField::SUBMISSION));

/** One table of fields — used for both origins. */
$table = static function (array $rows) use ($paperlessFields): string {
    ob_start();
    ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">Custom fields, their types and where they are written back</caption>
            <thead>
                <tr>
                    <th scope="col">Field</th>
                    <th scope="col">Type</th>
                    <th scope="col">In Paperless</th>
                    <th scope="col">Hint given to the model</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $field): ?>
                    <?php
                    $id          = (int) $field['id'];
                    $isActive    = (int) $field['active'] === 1;
                    $paperlessId = $field['paperless_field_id'] === null ? null : (int) $field['paperless_field_id'];
                    $paired      = $paperlessId === null ? null : ($paperlessFields[$paperlessId] ?? null);
                    ?>
                    <tr class="<?= $isActive ? '' : 'row-muted' ?>">
                        <th scope="row">
                            <?= e((string) $field['label']) ?>
                            <?php if (!$isActive): ?>
                                <span class="badge badge-muted">out of use</span>
                            <?php endif; ?>
                            <span class="cell-sub mono"><?= e((string) $field['field_key']) ?></span>
                        </th>
                        <td class="nowrap"><?= e(CustomField::DATA_TYPES[$field['data_type']] ?? (string) $field['data_type']) ?></td>
                        <td>
                            <?php if ($paperlessId === null): ?>
                                <span class="badge badge-warn">not paired</span>
                                <span class="cell-sub">Not written back.</span>
                            <?php elseif ($paired === null): ?>
                                <span class="badge badge-danger">field <?= e((string) $paperlessId) ?> is gone</span>
                                <span class="cell-sub">Paperless has no field with that id any more.</span>
                            <?php else: ?>
                                <?= e((string) ($paired['name'] ?? '')) ?>
                                <span class="cell-sub mono"><?= e((string) $paperlessId) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="break">
                            <?php if ($field['prompt_hint'] === null || trim((string) $field['prompt_hint']) === ''): ?>
                                <span class="muted">None — the model gets only the label to go on.</span>
                            <?php else: ?>
                                <?= e(str_limit((string) $field['prompt_hint'], 160)) ?>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <a class="btn btn-sm" href="<?= e(url('/admin/fields/' . $id)) ?>">Edit</a>
                            <form method="post" action="<?= e(url('/admin/fields/' . $id . '/toggle')) ?>" class="inline-form"
                                  <?= $isActive ? 'data-confirm="Take this field out of use? Values already read off documents are kept."' : '' ?>>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-ghost">
                                    <?= $isActive ? 'Take out of use' : 'Bring back' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php

    return (string) ob_get_clean();
};
?>

<h2 class="section-title">Read off the page</h2>

<p class="muted">
    These are offered to the extraction prompt as <code>{{ customFields }}</code>, with their hints,
    every time a document runs. The hint is what does the work — it is passed to the model verbatim.
</p>

<?php if ($extracted === []): ?>
    <div class="card"><p class="empty">No fields are defined.</p></div>
<?php else: ?>
    <?= $table($extracted) ?>
<?php endif; ?>

<?php if ($produced !== []): ?>
    <h2 class="section-title">Filled in on submission</h2>

    <p class="muted">
        Produced by the submission rather than read off the page — the Clear Books id, the number
        Clear Books assigned. They are <strong>never</strong> offered to the extraction prompt:
        asking a model to find a Clear Books bill id on a supplier's invoice asks it to invent a
        number that does not exist yet, and it will oblige.
    </p>

    <?= $table($produced) ?>
<?php endif; ?>
