<?php
/**
 * One prompt: the text, what its variables hold today, and its history.
 *
 * @var string                                                   $key
 * @var array<string,mixed>|null                                 $active
 * @var string                                                   $purpose
 * @var array<int,string>                                        $variables
 * @var array<string,string>                                     $help
 * @var array<string,array{value:string,summary:string}>         $preview
 * @var array<int,array<string,mixed>>                           $versions
 * @var array<string,mixed>|null                                 $seed
 * @var bool                                                     $isDefault
 */

$old     = $old ?? [];
$content = array_key_exists('content', $old)
    ? (string) $old['content']
    : (string) ($active['content'] ?? '');
?>

<div class="page-head">
    <h1 class="mono"><?= e($key) ?></h1>
    <p class="muted">
        <?= e($purpose) ?>
        <?php if ($active !== null): ?>
            · Live version <strong>v<?= e((string) $active['version']) ?></strong>
            <?php if ($isDefault): ?>
                <span class="badge badge-muted">as shipped</span>
            <?php else: ?>
                <span class="badge badge-info">edited</span>
            <?php endif; ?>
        <?php endif; ?>
        · <a href="<?= e(url('/admin/prompts')) ?>">all prompts</a>
    </p>
</div>

<?php if ($active === null): ?>
    <div class="card card-danger">
        <h2>No version is active</h2>
        <p>
            The stage that uses this prompt will refuse to run rather than fall back to something
            nobody chose. Save one below, or bring an earlier version back.
        </p>
    </div>
<?php endif; ?>

<div class="doc-split">
    <div class="doc-pane">
        <h3>The prompt</h3>

        <form method="post" action="<?= e(url('/admin/prompts/' . rawurlencode($key))) ?>" class="form">
            <?= csrf_field() ?>

            <div class="field">
                <label class="label sr-only" for="content">Prompt text</label>
                <textarea class="input mono prompt-editor" id="content" name="content"
                          rows="28" spellcheck="false" required><?= e($content) ?></textarea>
            </div>

            <div class="field">
                <label class="label" for="label">What changed</label>
                <input class="input" id="label" name="label" maxlength="120"
                       placeholder="e.g. tightened the account-code rule for courier lines">
                <p class="field-hint">
                    Optional, kept against the version and in the activity log. Six months from now
                    it is the only thing that explains why v7 exists.
                </p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save as a new version</button>

                <?php if ($seed !== null && !$isDefault): ?>
                    <button type="submit" class="btn btn-warning"
                            formaction="<?= e(url('/admin/prompts/' . rawurlencode($key) . '/reset')) ?>"
                            data-confirm="Go back to the version that shipped? Your edits stay in the history.">
                        Reset to default
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="doc-pane">
        <h3>What it can fill in</h3>

        <?php if ($variables === []): ?>
            <div class="card card-warn">
                <p>
                    <strong>This prompt is sent to the model exactly as written.</strong>
                    It goes with the page images, and nothing is substituted into it — a
                    <code>{{ name }}</code> here would be transmitted as those literal characters
                    and the model would answer confidently about nothing. Saving one is refused.
                </p>
            </div>
        <?php else: ?>
            <p class="field-hint">
                Write <code>{{ name }}</code> and it is replaced when the document runs. A name
                nothing supplies is refused on save rather than at three in the morning.
            </p>

            <?php foreach ($variables as $name): ?>
                <?php $shown = $preview[$name] ?? null; ?>
                <details class="fieldset">
                    <summary>
                        <span class="mono">{{ <?= e($name) ?> }}</span>
                        <?php if ($shown !== null): ?>
                            <span class="cell-sub"><?= e($shown['summary']) ?></span>
                        <?php endif; ?>
                    </summary>

                    <p class="field-hint"><?= e($help[$name] ?? '') ?></p>

                    <?php if ($shown !== null): ?>
                        <p class="field-hint">As things stand it would be:</p>
                        <div class="ocr-text"><?= e($shown['value']) ?></div>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>

            <p class="field-hint">
                <code>{{ customFields }}</code> comes from the
                <a href="<?= e(url('/admin/fields')) ?>">Custom fields</a> screen as it stands when
                each document runs. <strong>Do not list fields in the prompt as well</strong> — the
                two go out of step and the copy in the prompt wins without saying so.
            </p>
        <?php endif; ?>

        <h3>History</h3>

        <?php if ($versions === []): ?>
            <p class="empty">No versions yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-compact">
                    <caption class="sr-only">Every version of this prompt</caption>
                    <thead>
                        <tr>
                            <th scope="col">Version</th>
                            <th scope="col">Note</th>
                            <th scope="col">Saved</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($versions as $version): ?>
                            <?php $isLive = (int) $version['is_active'] === 1; ?>
                            <tr class="<?= $isLive ? '' : 'row-muted' ?>">
                                <th scope="row" class="nowrap">
                                    v<?= e((string) $version['version']) ?>
                                    <?php if ($isLive): ?>
                                        <span class="badge badge-ok">live</span>
                                    <?php endif; ?>
                                    <?php if ((string) $version['origin'] === 'seed'): ?>
                                        <span class="badge badge-muted">shipped</span>
                                    <?php endif; ?>
                                </th>
                                <td class="break">
                                    <?= trim((string) $version['label']) === ''
                                        ? '<span class="muted">no note</span>'
                                        : e((string) $version['label']) ?>
                                </td>
                                <td class="nowrap">
                                    <?= e(format_date((string) $version['created_at'])) ?>
                                    <?php if ($version['display_name'] !== null): ?>
                                        <span class="cell-sub"><?= e((string) $version['display_name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <?php if (!$isLive): ?>
                                        <form method="post" class="inline-form"
                                              action="<?= e(url('/admin/prompts/' . rawurlencode($key) . '/activate/' . (int) $version['id'])) ?>"
                                              data-confirm="Make v<?= e((string) $version['version']) ?> live? It is what the next document sees.">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm">Make live</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
