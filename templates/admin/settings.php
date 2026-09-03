<?php

use App\Models\SettingSchema;

/**
 * Everything set once and rarely revisited.
 *
 * One form per card rather than one for the page: a mistyped Clear Books
 * address should not throw away what was just typed into the model boxes, and
 * a save that says "12 settings changed" when one was touched is a save nobody
 * trusts.
 *
 * **Nothing here prints a secret.** A secret arrives as `configured` and
 * nothing else; the box is always empty, and an empty box means "leave it
 * alone". `tests/smoke.php` asserts that no template calls `Setting::secret()`
 * or `Setting::get()` on a secret key, and this is the template it was written
 * for.
 *
 * @var array<string,array<string,mixed>> $sections
 * @var bool                              $keyUsable       APP_KEY can encrypt
 * @var bool                              $connected       Clear Books is authorised
 * @var array<int,array<string,mixed>>    $docTypes
 */

$old    = $old ?? [];
$errors = $errors ?? [];

/**
 * Did this form just come back with an error?
 *
 * The hidden `section` field is what makes this answerable. Without it, a
 * failed save on one card would restore every *other* card's checkboxes as
 * unticked — a browser posts nothing at all for an unticked box, so "absent
 * from the old input" cannot be told from "not this form" any other way.
 */
$cameBack = static fn (string $section): bool => ($old['section'] ?? null) === $section;

/** The value to show: what was typed if the form bounced, else what is stored. */
$value = static function (string $section, string $key, string $stored) use ($old, $cameBack): string {
    return $cameBack($section) && array_key_exists($key, $old)
        ? (string) $old[$key]
        : $stored;
};
?>

<div class="page-head">
    <div>
        <h1>Application settings</h1>
        <p class="muted">
            The addresses, credentials and thresholds this instance runs on. Everything here can
            also be set with <code>php bin/console.php settings:set</code>, which is how the first
            administrator gets in before there is a browser session to do it from.
        </p>
    </div>
</div>

<nav class="subnav" aria-label="Settings sections">
    <?php foreach ($sections as $name => $section): ?>
        <a class="subnav-link" href="#<?= e($name) ?>"><?= e((string) $section['title']) ?></a>
    <?php endforeach; ?>
    <a class="subnav-link" href="#document-types">Document types</a>
</nav>

<?php if (!$keyUsable): ?>
    <div class="card card-danger">
        <h2>APP_KEY is missing, so no credential can be saved</h2>
        <p>
            Secrets are encrypted before they are written, and without a usable key that is not
            possible. Rather than storing a token in the clear, InvoGrid refuses the save — the
            ordinary settings on this page still work.
        </p>
        <p class="field-hint">
            Run <code>php bin/console.php key:generate</code> and put the result in <code>.env</code>
            as <code>APP_KEY</code>. Back it up with the database: replacing it later makes every
            stored secret unreadable, and they all have to be entered again.
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h2>What is not on this page</h2>
    <ul class="plain-list">
        <li>
            <a href="<?= e(url('/admin/branding')) ?>">Branding</a> — the logo. It is a file, not a
            path to be typed.
        </li>
        <li>
            <a href="<?= e(url('/admin/clearbooks')) ?>">Clear Books</a> — authorising the
            connection, the cached lists, and the local copy of what Clear Books already
            holds. The credentials it needs first are below.
        </li>
        <li>
            <a href="<?= e(url('/admin/prompts')) ?>">Prompts</a> and
            <a href="<?= e(url('/admin/fields')) ?>">custom fields</a> — what the models are asked.
        </li>
        <li>
            The Clear Books access and refresh tokens are written by the consent flow and are not
            editable anywhere. There is nothing useful a person could type into them, and a value
            typed by hand breaks a working connection.
        </li>
    </ul>
</div>

<?php foreach ($sections as $name => $section): ?>
    <form method="post" action="<?= e(url('/admin/settings/' . $name)) ?>" class="form card" id="<?= e($name) ?>">
        <?= csrf_field() ?>
        <?php /* Read by the template, not the controller — see $cameBack above. */ ?>
        <input type="hidden" name="section" value="<?= e($name) ?>">

        <h2><?= e((string) $section['title']) ?></h2>
        <p class="muted"><?= e((string) $section['blurb']) ?></p>

        <?php if ($name === 'clearbooks' && $connected): ?>
            <p class="field-hint">
                <strong>This instance is authorised with Clear Books.</strong> Changing the client
                id, client secret or business id invalidates that — the connection has to be made
                again on the Clear Books screen, by somebody with a Clear Books login.
            </p>
        <?php endif; ?>

        <?php foreach ($section['fields'] as $key => $field): ?>
            <?php
            $type     = (string) $field['type'];
            $isSecret = $type === SettingSchema::SECRET;
            $error    = $errors[$key] ?? null;
            $current  = $value($name, $key, (string) $field['stored']);
            ?>

            <?php if ($type === SettingSchema::BOOLEAN): ?>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="<?= e($key) ?>" value="1"
                            <?= ($cameBack($name)
                                    ? array_key_exists($key, $old)
                                    : (string) $field['stored'] === '1') ? 'checked' : '' ?>>
                        <span><?= e((string) $field['label']) ?></span>
                    </label>
                    <p class="field-hint"><?= e((string) $field['hint']) ?></p>
                </div>
            <?php else: ?>
                <div class="field">
                    <label class="label" for="<?= e($key) ?>">
                        <?= e((string) $field['label']) ?>
                        <?php if ($isSecret): ?>
                            <span class="badge <?= $field['configured'] ? 'badge-ok' : 'badge-muted' ?>">
                                <?= $field['configured'] ? 'set' : 'not set' ?>
                            </span>
                        <?php endif; ?>
                    </label>

                    <?php if ($isSecret): ?>
                        <div class="input-with-button">
                            <input class="input<?= $error !== null ? ' has-error' : '' ?>"
                                   type="password" id="<?= e($key) ?>" name="<?= e($key) ?>"
                                   autocomplete="off" spellcheck="false"
                                   placeholder="<?= $field['configured'] ? 'Leave empty to keep the current value' : 'Not set' ?>">
                            <button type="button" class="btn btn-inline" data-toggle-password="<?= e($key) ?>">Show</button>
                        </div>

                    <?php elseif ($type === SettingSchema::SELECT): ?>
                        <select class="input<?= $error !== null ? ' has-error' : '' ?>"
                                id="<?= e($key) ?>" name="<?= e($key) ?>">
                            <?php foreach ((array) ($field['options'] ?? []) as $option => $label): ?>
                                <option value="<?= e((string) $option) ?>"
                                    <?= $current === (string) $option ? 'selected' : '' ?>>
                                    <?= e((string) $label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($type === SettingSchema::TEXTAREA): ?>
                        <textarea class="input mono<?= $error !== null ? ' has-error' : '' ?>"
                                  id="<?= e($key) ?>" name="<?= e($key) ?>" rows="4"><?= e($current) ?></textarea>

                    <?php else: ?>
                        <input class="input<?= $error !== null ? ' has-error' : '' ?>"
                               type="<?= $type === SettingSchema::INTEGER ? 'number' : 'text' ?>"
                               <?= $type === SettingSchema::URL ? 'inputmode="url" spellcheck="false"' : '' ?>
                               id="<?= e($key) ?>" name="<?= e($key) ?>"
                               value="<?= e($current) ?>"
                               <?= isset($field['placeholder']) ? 'placeholder="' . e((string) $field['placeholder']) . '"' : '' ?>>
                    <?php endif; ?>

                    <?php if ($error !== null): ?>
                        <p class="field-error"><?= e((string) $error) ?></p>
                    <?php endif; ?>

                    <p class="field-hint"><?= e((string) $field['hint']) ?></p>

                    <?php if ($isSecret && $field['configured']): ?>
                        <label class="checkbox">
                            <input type="checkbox" name="<?= e($key) ?>__clear" value="1">
                            <span>Clear this value</span>
                        </label>
                    <?php endif; ?>

                    <?php /* Said only when it matters: the row is empty and .env is answering
                             for it. Reading "not set" beside a working integration is how an
                             afternoon gets spent on a setting that was never the problem. */ ?>
                    <?php if ($field['fallback'] && (string) $field['stored'] === '' && $field['configured']): ?>
                        <p class="field-hint">
                            <?php if ($field['fallbackValue'] !== null): ?>
                                Empty here, so the value in <code>.env</code> is used:
                                <span class="mono"><?= e(str_limit((string) $field['fallbackValue'], 60)) ?></span>.
                            <?php else: ?>
                                Empty here, so the value in <code>.env</code> is used.
                            <?php endif; ?>
                            Anything entered here wins from the moment it is saved.
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="form-actions">
            <?php /* "Save", not "Save clear books" — the heading two inches above already
                     says which card this is, and lower-casing a proper noun to fit a button
                     label reads as a bug. */ ?>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>

    <?php
    /*
     * The connection tests, outside the form they belong to — a form inside a
     * form is not valid HTML and browsers resolve it by dropping one of them.
     *
     * "Is there a string in the box" is not the question anybody has, and it is
     * the only one the badge above can answer. These make the credential prove
     * itself. Save first: a test checks what is stored, not what is typed.
     */
    $tests = match ($name) {
        'llm'       => [
            'llm_ocr'        => 'Test the page-reading model',
            'llm_extraction' => 'Test the extraction model',
        ],
        default     => [],
    };
    ?>

    <?php if ($tests !== []): ?>
        <div class="form-actions">
            <?php foreach ($tests as $target => $label): ?>
                <form method="post" action="<?= e(url('/admin/settings/test/' . $target)) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn"><?= e($label) ?></button>
                </form>
            <?php endforeach; ?>
            <span class="muted">
                <?= $name === 'llm'
                    ? 'Each test is a real call to the provider and costs a fraction of a penny. It checks what is saved, so save first.'
                    : 'Checks what is saved rather than what is typed, so save first.' ?>
            </span>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php /*
   Read-only, and nothing here to save.

   This card used to carry the one editable thing about a document type — which
   Paperless document type it was written back as — and that has gone with
   Paperless. What is left is worth keeping on screen anyway: it is the only
   place that answers "what does InvoGrid do with a credit note", and the answer
   is a row in `document_types` rather than anything in the code. An
   administrator wondering why a refund went somewhere unexpected reads it here.

   Changing a type is still a migration. That is deliberate — `clearbooks_resource`
   decides which endpoint somebody's accounts are written to, and a text box on a
   settings page is the wrong amount of ceremony for that. */ ?>
<div class="card" id="document-types">
    <h2>Document types</h2>
    <p class="muted">
        What InvoGrid can classify a document as, and where each one is submitted in Clear Books.
        Set up by migration rather than on this screen: which endpoint a document reaches is not a
        preference.
    </p>

    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">InvoGrid document types and the Clear Books resource each is submitted to</caption>
            <thead>
                <tr>
                    <th scope="col">Type</th>
                    <th scope="col">Clear Books resource</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docTypes as $type): ?>
                    <?php $inactive = (int) $type['active'] !== 1; ?>
                    <tr class="<?= $inactive ? 'row-muted' : '' ?>">
                        <th scope="row">
                            <?= e((string) $type['label']) ?>
                            <?php if ($inactive): ?>
                                <span class="badge badge-muted">out of use</span>
                            <?php endif; ?>
                            <span class="cell-sub mono"><?= e((string) $type['type_key']) ?></span>
                        </th>
                        <td class="mono break"><?= e((string) $type['clearbooks_resource']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
