<?php
/**
 * The full audit log: what people did.
 *
 * A table rather than the dashboard's feed. The feed answers "what has just
 * happened" and reads best as prose; this answers "who changed this, and when",
 * which is a question with columns — the time, the person and the action want
 * lining up so a run of them can be scanned.
 *
 * Nothing on this page writes. There is no delete, no edit and no "clear the
 * log": an audit log a user interface can alter is not an audit log.
 *
 * @var array<int,array<string,mixed>> $entries
 * @var array<string,string>           $filters
 * @var bool                           $filtered
 * @var int                            $total
 * @var int                            $page
 * @var int                            $pages
 * @var array<int,string>              $actions
 * @var array<int,array<string,mixed>> $actors
 */

/** The badge tone an action reads as. */
$tone = static function (string $action): string {
    if (str_contains($action, '_failed')) {
        return 'badge-danger';
    }

    return match (true) {
        str_starts_with($action, 'auth.')      => 'badge-muted',
        str_starts_with($action, 'users.')     => 'badge-warn',
        str_starts_with($action, 'settings.')  => 'badge-warn',
        $action === 'document.submitted'       => 'badge-ok',

        // A link ends a document the same way a submission does: it is now in
        // Clear Books.
        $action === 'document.linked'          => 'badge-ok',

        // The only line in this log describing something that no longer
        // exists. It reads as the exception it is.
        $action === 'document.deleted'         => 'badge-danger',
        $action === 'clearbooks.connected'     => 'badge-ok',
        default                                => 'badge-accent',
    };
};

/**
 * The one family whose name `ucfirst()` gets wrong.
 *
 * A log that calls the accounts package "Clearbooks" looks like it is
 * describing something else. Everything else — `document`, `users`, `review` —
 * comes out right on its own.
 *
 * Families no longer written still appear here, and should: `paperless.*` rows
 * from before the pivot are history, and the log outliving what it describes is
 * the point of it.
 */
$families = ['clearbooks' => 'Clear Books'];

/** `clearbooks.supplier_created` reads as "Clear Books — supplier created". */
$humanise = static function (string $action) use ($families): string {
    [$family, $what] = array_pad(explode('.', $action, 2), 2, '');

    $label = $families[$family] ?? ucfirst(str_replace('_', ' ', $family));
    $what  = str_replace('_', ' ', $what);

    return $what === '' ? $label : $label . ' — ' . $what;
};

/** Keep the current filters when building a page link. */
$pageUrl = static function (int $page) use ($filters): string {
    $query = array_filter(
        $filters + ['page' => $page > 1 ? (string) $page : ''],
        static fn ($value): bool => trim((string) $value) !== ''
    );

    return url('/admin/activity') . ($query === [] ? '' : '?' . http_build_query($query));
};

// Grouped by the part before the dot, so a list of fifty actions is navigable.
$grouped = [];
foreach ($actions as $action) {
    $grouped[explode('.', $action, 2)[0]][] = $action;
}
?>

<div class="page-head">
    <div>
        <h1>Activity log</h1>
        <p class="muted">
            People, not the pipeline. Every line here is somebody's deliberate action — a document
            corrected, a supplier created, a setting changed. What the machine did to a document is
            on that document's own page.
        </p>
    </div>
</div>

<form method="get" action="<?= e(url('/admin/activity')) ?>" class="filter-bar">
    <div class="field field-wide">
        <label class="label" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
               placeholder="A name, or words from the entry, or a document number">
        <p class="field-hint">
            A number is read as a document number first, and then as text — so a reference
            that happens to be numeric is not lost.
        </p>
    </div>

    <div class="field">
        <label class="label" for="action">Action</label>
        <select class="input" id="action" name="action">
            <option value="">Any action</option>
            <?php foreach ($grouped as $family => $members): ?>
                <optgroup label="<?= e(ucfirst(str_replace('_', ' ', (string) $family))) ?>">
                    <?php foreach ($members as $action): ?>
                        <option value="<?= e($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>>
                            <?= e($humanise($action)) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label class="label" for="user">Person</label>
        <select class="input" id="user" name="user">
            <option value="">Anyone</option>
            <?php foreach ($actors as $actor): ?>
                <?php $id = (string) (int) $actor['id']; ?>
                <option value="<?= e($id) ?>" <?= $filters['user'] === $id ? 'selected' : '' ?>>
                    <?= e((string) ($actor['display_name'] ?? $actor['username'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label class="label" for="from">From</label>
        <input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
    </div>

    <div class="field">
        <label class="label" for="to">To</label>
        <input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
    </div>

    <div class="field">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($filtered): ?>
            <a class="btn btn-ghost" href="<?= e(url('/admin/activity')) ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="table-wrap">
    <table class="table table-compact">
        <caption class="sr-only">Actions taken by people, newest first</caption>
        <thead>
            <tr>
                <th scope="col">When</th>
                <th scope="col">Who</th>
                <th scope="col">Action</th>
                <th scope="col">What happened</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($entries as $entry): ?>
                <?php
                $action = (string) $entry['action'];
                $who    = trim((string) ($entry['display_name'] ?? $entry['username'] ?? ''));
                ?>
                <tr>
                    <td class="nowrap"><?= e(format_datetime((string) $entry['created_at'])) ?></td>
                    <td>
                        <?php if ($who !== ''): ?>
                            <?= e($who) ?>
                            <?php /* The username only when it adds something. Compared without
                                     case, because "Nick" over "nick" is two lines saying one
                                     thing, and it is the commonest pair there is. */ ?>
                            <?php if (($entry['username'] ?? null) !== null
                                && mb_strtolower($who) !== mb_strtolower((string) $entry['username'])): ?>
                                <span class="cell-sub mono"><?= e((string) $entry['username']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php /* Either the pipeline itself or an account since deleted. The log
                                     outlives the account — that is the point of ON DELETE SET NULL —
                                     but it must not read as though nobody did it. */ ?>
                            <span class="badge badge-muted">no account</span>
                        <?php endif; ?>

                        <?php if ($entry['ip_address'] !== null): ?>
                            <span class="cell-sub mono"><?= e((string) $entry['ip_address']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <span class="badge <?= e($tone($action)) ?>"><?= e($humanise($action)) ?></span>
                    </td>
                    <td class="break">
                        <?= e(trim((string) ($entry['details'] ?? '')) === ''
                            ? 'No further detail was recorded.'
                            : (string) $entry['details']) ?>

                        <?php if ($entry['document_id'] !== null): ?>
                            <span class="cell-sub">
                                <a href="<?= e(url('/documents/' . (int) $entry['document_id'])) ?>">
                                    Document #<?= (int) $entry['document_id'] ?>
                                    <?php /* The name it arrived under, when the document still
                                             exists to have one. A left join, so this is null for a
                                             log line that outlived its document. */ ?>
                                    <?php if (($entry['original_filename'] ?? null) !== null): ?>
                                        — <?= e((string) $entry['original_filename']) ?>
                                    <?php endif; ?>
                                </a>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($entries === []): ?>
                <tr>
                    <td colspan="4" class="empty">
                        <?= $filtered
                            ? 'Nothing matches those filters.'
                            : 'Nothing has been logged yet. Signing in is the first thing that will appear here.' ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
    <div class="form-actions">
        <?php if ($page > 1): ?>
            <a class="btn" href="<?= e($pageUrl($page - 1)) ?>">Previous</a>
        <?php endif; ?>
        <span class="muted">Page <?= (int) $page ?> of <?= (int) $pages ?> · <?= (int) $total ?> entries</span>
        <?php if ($page < $pages): ?>
            <a class="btn" href="<?= e($pageUrl($page + 1)) ?>">Next</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <p class="muted"><?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?>.</p>
<?php endif; ?>
