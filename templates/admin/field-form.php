<?php

use App\Models\CustomField;

/**
 * Add or edit one custom field.
 *
 * @var array<string,mixed>|null $field Null when adding
 */

$isNew  = $field === null;
$old    = $old ?? [];
$errors = $errors ?? [];

/** The submitted value if the form is coming back with an error, else the stored one. */
$value = static function (string $name, mixed $fallback = '') use ($old, $field): string {
    if (array_key_exists($name, $old)) {
        return (string) $old[$name];
    }

    if ($field !== null && array_key_exists($name, $field)) {
        return (string) ($field[$name] ?? '');
    }

    return (string) $fallback;
};

$dataType = $value('data_type', 'string');
?>

<div class="page-head">
    <h1><?= $isNew ? 'Add a custom field' : 'Edit ' . e((string) $field['label']) ?></h1>
    <p class="muted">
        <?php if ($isNew): ?>
            Once saved, the next document extracted is asked about it.
        <?php else: ?>
            Changes take effect on the next document extracted. Values already read off documents
            are kept whatever happens here.
        <?php endif; ?>
    </p>
</div>

<form method="post" action="<?= e(url('/admin/fields/' . ($isNew ? '' : (int) $field['id']))) ?>" class="form">
    <?= csrf_field() ?>

    <div class="card">
        <h2>What it is</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="label">Label</label>
                <input class="input" id="label" name="label" required maxlength="120"
                       value="<?= e($value('label')) ?>">
                <p class="field-hint">What a reviewer sees beside the value.</p>
            </div>

            <div class="field">
                <label class="label" for="field_key">Key</label>
                <?php if ($isNew): ?>
                    <input class="input mono" id="field_key" name="field_key" required maxlength="64"
                           value="<?= e($value('field_key')) ?>" placeholder="e.g. purchase_order_number">
                    <p class="field-hint">
                        Letters, numbers and underscores. <strong>It cannot be changed later</strong> —
                        every value read off a document is stored under it.
                    </p>
                <?php else: ?>
                    <span class="field-value mono"><?= e((string) $field['field_key']) ?></span>
                    <p class="field-hint">
                        Fixed. Values already read off documents are stored under this key; changing
                        it would orphan every one of them. Take this field out of use and add
                        another if the key is wrong.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label class="label" for="data_type">Type</label>
            <select class="input" id="data_type" name="data_type">
                <?php foreach (CustomField::DATA_TYPES as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $dataType === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                A value that will not fit the type is stored as nothing rather than as something
                malformed — "not found" is a legitimate answer everywhere in this pipeline.
            </p>
        </div>

        <div class="field">
            <label class="label" for="select_options">Choices</label>
            <textarea class="input" id="select_options" name="select_options" rows="4"
                      placeholder="One per line"><?= e(array_key_exists('select_options', $old)
                          ? (string) $old['select_options']
                          : CustomField::optionLines($field['select_options'] ?? null)) ?></textarea>
            <p class="field-hint">
                One per line, and only used when the type is "one of a list". Each line becomes a
                choice with a stable id of its own, so a choice can be renamed later without
                orphaning the documents already stored against it.
            </p>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="active" value="1"
                <?= $isNew || (int) ($field['active'] ?? 1) === 1 ? 'checked' : '' ?>>
            <span>
                In use
                <span class="field-hint">
                    Only fields in use are offered to the extraction prompt.
                </span>
            </span>
        </label>
    </div>

    <div class="card">
        <h2>What the model is told</h2>

        <div class="field">
            <label class="label" for="prompt_hint">Hint</label>
            <textarea class="input" id="prompt_hint" name="prompt_hint" rows="6"><?= e($value('prompt_hint')) ?></textarea>
            <p class="field-hint">
                Passed to the model <strong>verbatim</strong>, so write it as an instruction. This is
                the part that does the work: where on the page to look, what it looks like, and —
                just as important — what <em>not</em> to mistake for it.
            </p>
            <p class="field-hint">
                The Clearbooks Number hint is the pattern to follow: <em>"a handwritten number,
                almost always in RED pen, purely numeric… frequently absent — never substitute a
                printed number such as the supplier's own invoice number."</em> Saying what it is
                not is what stops a model finding one on every page.
            </p>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Add the field' : 'Save' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/fields')) ?>">Cancel</a>
    </div>
</form>
