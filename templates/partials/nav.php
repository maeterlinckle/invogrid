<?php
/**
 * Responsive navigation.
 *
 * Desktop and mobile render from the same markup on purpose. A group is a
 * <details> element: on a phone it is an accordion inside the slide-out menu,
 * on a desktop the same element is styled as a drop-down. There is no second
 * structure to keep in step, and it works with JavaScript switched off.
 *
 * Every entry is a real destination now. The `'pending' => true` rendering is
 * kept — it draws an entry as muted text with a `soon` badge instead of a link,
 * which is how Application settings and the Activity log were shown while they
 * were being built. Showing the shape of the application without offering a
 * link to a page that does not exist beats hiding the structure or handing
 * somebody a 404, and the next unfinished screen will want it again.
 */
$user = auth_user();

$links = [
    // No Dashboard entry: the logo top-left is already the way home, and a menu
    // item pointing at the same page spends width saying it twice.
    ['label' => 'Documents', 'href' => '/documents', 'capability' => 'documents.view'],
    ['label' => 'Review queue', 'href' => '/review', 'capability' => 'queue.view'],

    // The other flow's queue. Its own top-level entry rather than a tab on the
    // review queue, because the two screens ask different questions of
    // different data — one has an extraction to correct, the other has a
    // handwritten number that did not find its Clear Books record.
    ['label' => 'Existing invoices', 'href' => '/existing', 'capability' => 'queue.view'],

    // The third queue, and a third question again: not "is this extraction
    // right" and not "which record does this number point at", but "is this
    // invoice one Clear Books already has". Top level beside the other two
    // because a document sitting in it is a bill nobody can post until somebody
    // decides, and a queue tucked inside another screen does not get worked.
    ['label' => 'Duplicates', 'href' => '/duplicates', 'capability' => 'queue.view'],

    // The way in. Top level rather than tucked under Settings: it is the one
    // thing somebody comes to this application to *do* that is not reading
    // something. A viewer does not hold `documents.upload` and never sees it.
    ['label' => 'Upload', 'href' => '/documents/upload', 'capability' => 'documents.upload'],

    // Everything occasional: set up once, visited rarely.
    ['label' => 'Settings', 'href' => '/admin/settings', 'capability' => null, 'children' => [
        ['label' => 'Application settings', 'href' => '/admin/settings', 'capability' => 'settings.manage'],
        ['label' => 'Branding',             'href' => '/admin/branding', 'capability' => 'settings.manage'],
        ['label' => 'Clear Books',          'href' => '/admin/clearbooks', 'capability' => 'settings.manage'],
        ['label' => 'Prompts',              'href' => '/admin/prompts',  'capability' => 'prompts.manage'],
        ['label' => 'Custom fields',        'href' => '/admin/fields',   'capability' => 'fields.manage'],
        ['label' => 'Users',                'href' => '/admin/users',    'capability' => 'users.manage'],
        ['label' => 'Activity log',         'href' => '/admin/activity', 'capability' => 'audit.view'],
    ]],
];

/** Can this user see this entry at all? */
$allowed = static function (array $link): bool {
    return $link['capability'] === null || can((string) $link['capability']);
};

// Resolve groups: drop the children a user cannot see, then drop the group
// itself when nothing is left in it.
$visible = [];

foreach ($links as $link) {
    if (!$allowed($link)) {
        continue;
    }

    if (isset($link['children'])) {
        $link['children'] = array_values(array_filter($link['children'], $allowed));

        if ($link['children'] === []) {
            continue;
        }
    }

    $visible[] = $link;
}

/**
 * Which child of a group is the page you are actually on — at most one.
 *
 * active_path() picks the longest match rather than every prefix match, so a
 * menu whose items nest under one another does not light up twice.
 */
$activeChild = static function (array $link): ?string {
    return active_path($link['children'] === [] ? [] : array_column($link['children'], 'href'));
};
?>
<header class="site-header">
    <div class="container header-inner">
        <?php /* Not itself a link: the logo inside carries the link home, and the
                 wordmark beside it is a heading sized to sit level with the menu
                 items. Wrapping both made the whole lockup one large target. */ ?>
        <div class="brand">
            <?= partial('partials/brand', [
                'appName'  => $appName ?? config('app.name', 'InvoGrid'),
                'homeHref' => '/',
            ]) ?>
        </div>

        <nav id="primary-nav" class="primary-nav" data-nav aria-label="Main">
            <ul class="nav-list">
                <?php foreach ($visible as $link): ?>
                    <li class="<?= isset($link['children']) ? 'nav-item nav-item-group' : 'nav-item' ?>">
                        <?php if (!isset($link['children'])): ?>
                            <?php if (!empty($link['pending'])): ?>
                                <span class="nav-link is-pending">
                                    <?= e($link['label']) ?>
                                    <span class="badge badge-muted">soon</span>
                                </span>
                            <?php else: ?>
                                <a href="<?= e(url($link['href'])) ?>"
                                   class="nav-link<?= is_active_path($link['href']) ? ' is-active' : '' ?>"
                                    <?= is_active_path($link['href']) ? 'aria-current="page"' : '' ?>>
                                    <?= e($link['label']) ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php $active = $activeChild($link); ?>
                            <details class="nav-group" data-nav-group
                                <?= $active !== null ? 'open data-nav-autoopen' : '' ?>>
                                <summary class="nav-link nav-group-toggle<?= $active !== null ? ' is-active' : '' ?>">
                                    <span><?= e($link['label']) ?></span>
                                    <span class="caret" aria-hidden="true"></span>
                                </summary>
                                <ul class="nav-sublist">
                                    <?php foreach ($link['children'] as $child): ?>
                                        <li>
                                            <?php if (!empty($child['pending'])): ?>
                                                <span class="nav-link nav-sublink is-pending">
                                                    <?= e($child['label']) ?>
                                                    <span class="badge badge-muted">soon</span>
                                                </span>
                                            <?php else: ?>
                                                <?php $isCurrent = $child['href'] === $active; ?>
                                                <a href="<?= e(url($child['href'])) ?>"
                                                   class="nav-link nav-sublink<?= $isCurrent ? ' is-active' : '' ?>"
                                                    <?= $isCurrent ? 'aria-current="page"' : '' ?>>
                                                    <?= e($child['label']) ?>
                                                </a>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="nav-account">
                <button type="button" class="btn btn-ghost btn-icon" data-theme-toggle title="Switch between light and dark">
                    <span class="theme-icon" aria-hidden="true"></span>
                    <span class="btn-label" data-theme-label>Dark mode</span>
                </button>

                <?php /* A link to the one thing an account can change about itself. Every
                         role has it, including a viewer who can change nothing else. */ ?>
                <a class="nav-user" href="<?= e(url('/account/password')) ?>" title="Change your password">
                    <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($user['display_name'] ?? $user['username'] ?? '?'), 0, 1))) ?></span>
                    <span class="nav-user-text">
                        <span class="nav-user-name"><?= e($user['display_name'] ?? $user['username'] ?? '') ?></span>
                        <span class="nav-user-role"><?= e(ucfirst((string) ($user['role'] ?? ''))) ?></span>
                    </span>
                </a>

                <form method="post" action="<?= e(url('/logout')) ?>" class="nav-logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost">Sign out</button>
                </form>
            </div>
        </nav>

        <div class="header-actions">
            <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="primary-nav">
                <span class="nav-toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="sr-only">Menu</span>
            </button>
        </div>
    </div>
</header>
