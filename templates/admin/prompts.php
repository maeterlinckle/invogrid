<?php
/**
 * The live prompts, and whether anybody has changed them.
 *
 * @var array<string,array{active:?array<string,mixed>,purpose:string,variables:array<int,string>,isDefault:bool,versions:int,used:array<int,string>}> $prompts
 */
?>

<div class="page-head">
    <h1>Prompts</h1>
    <p class="muted">
        What the models are actually asked, editable here rather than in the source. An edit writes
        a new version and makes it active; nothing is overwritten, so a change that turns out badly
        is one click to undo and every result can say which prompt produced it.
    </p>
</div>

<?php if ($prompts === []): ?>
    <div class="card">
        <p class="empty">No prompts are in the database. Run <code>php bin/migrate.php</code>.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">The prompt used at each stage</caption>
            <thead>
                <tr>
                    <th scope="col">Prompt</th>
                    <th scope="col">What it does</th>
                    <th scope="col">Live version</th>
                    <th scope="col">Fills in</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($prompts as $key => $prompt): ?>
                    <tr>
                        <th scope="row" class="mono nowrap"><?= e($key) ?></th>
                        <td class="break"><?= e($prompt['purpose']) ?></td>
                        <td class="nowrap">
                            <?php if ($prompt['active'] === null): ?>
                                <span class="badge badge-danger">none active</span>
                            <?php else: ?>
                                v<?= e((string) $prompt['active']['version']) ?>
                                <?php if ($prompt['isDefault']): ?>
                                    <span class="badge badge-muted">as shipped</span>
                                <?php else: ?>
                                    <span class="badge badge-info">edited</span>
                                <?php endif; ?>
                                <span class="cell-sub"><?= e((string) $prompt['versions']) ?> version<?= $prompt['versions'] === 1 ? '' : 's' ?> kept</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($prompt['used'] === []): ?>
                                <span class="muted">nothing — sent as written</span>
                            <?php else: ?>
                                <?php foreach ($prompt['used'] as $name): ?>
                                    <span class="badge badge-muted mono"><?= e($name) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <a class="btn btn-sm" href="<?= e(url('/admin/prompts/' . rawurlencode($key))) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="card">
    <h2>A note on the custom fields</h2>
    <p>
        <code>{{ customFields }}</code> is filled in from the
        <a href="<?= e(url('/admin/fields')) ?>">Custom fields</a> screen at the moment each
        document runs — never from a list written into the prompt. Adding a field there is enough;
        <strong>do not list fields in a prompt as well</strong>, or the two go out of step and the
        one in the prompt wins silently.
    </p>
</div>
