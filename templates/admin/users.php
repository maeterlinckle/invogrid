<?php

use App\Core\Auth;

/**
 * The accounts, and what each role can do.
 *
 * @var array<int,array<string,mixed>>       $users
 * @var int|null                             $currentId
 * @var int                                  $activeAdmins
 * @var array<string,array<int,string>>      $capabilities  Role => everything it can do
 */

/** How a capability reads to somebody who does not write the code. */
$capabilityLabels = [
    'documents.view'  => 'See documents and the dashboard',
    'queue.view'      => 'Look at the review queue',
    'documents.retry' => 'Retry a stage, ignore a document',
    'review.resolve'  => 'Correct a document and resolve its entities',
    'entities.create' => 'Create a supplier in Clear Books',
    'documents.submit' => 'Submit to Clear Books',
    'settings.manage' => 'Settings and the Clear Books connection',
    'prompts.manage'  => 'Edit the prompts',
    'fields.manage'   => 'Manage the custom fields',
    'users.manage'    => 'Manage accounts',
    'audit.view'      => 'Read the activity log',
];

$roleNames = [
    'viewer'   => 'Viewer',
    'reviewer' => 'Reviewer',
    'admin'    => 'Administrator',
];
?>

<div class="page-head">
    <h1>Users</h1>
    <p class="muted">
        Every account is made here. There is no sign-up page, and there is not going to be one —
        this application is reachable before it is configured, and a registration form on it would
        be a way in rather than a convenience.
    </p>
</div>

<div class="form-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/users/new')) ?>">Add a user</a>
</div>

<div class="table-wrap">
    <table class="table">
        <caption class="sr-only">Accounts, their roles and when they last signed in</caption>
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Role</th>
                <th scope="col">Last signed in</th>
                <th scope="col">State</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $account): ?>
                <?php
                $id       = (int) $account['id'];
                $isActive = (int) $account['active'] === 1;
                $isSelf   = $currentId !== null && $id === $currentId;
                $isAdmin  = (string) $account['role'] === 'admin';

                // Only-active-administrator: the two controls that could remove
                // the last one are the ones this hides.
                $onlyAdmin = $isAdmin && $isActive && $activeAdmins === 1;
                ?>
                <tr id="user-<?= e((string) $id) ?>" class="<?= $isActive ? '' : 'row-muted' ?>">
                    <th scope="row">
                        <?= e((string) $account['display_name']) ?>
                        <?php if ($isSelf): ?>
                            <span class="badge badge-muted">you</span>
                        <?php endif; ?>
                        <span class="cell-sub mono"><?= e((string) $account['username']) ?></span>
                        <?php if ($account['email'] !== null && (string) $account['email'] !== ''): ?>
                            <span class="cell-sub"><?= e((string) $account['email']) ?></span>
                        <?php endif; ?>
                    </th>
                    <td class="nowrap">
                        <?= e($roleNames[(string) $account['role']] ?? (string) $account['role']) ?>
                        <?php if ($onlyAdmin): ?>
                            <span class="cell-sub">The only active administrator.</span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <?php if ($account['last_login_at'] === null): ?>
                            <span class="muted">Never</span>
                        <?php else: ?>
                            <?= e(date('j M Y, H:i', strtotime((string) $account['last_login_at']))) ?>
                            <?php if ($account['last_login_ip'] !== null): ?>
                                <span class="cell-sub mono"><?= e((string) $account['last_login_ip']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$isActive): ?>
                            <span class="badge badge-muted">deactivated</span>
                        <?php elseif ((int) $account['must_change_password'] === 1): ?>
                            <span class="badge badge-warn">must set a password</span>
                            <span class="cell-sub">Can sign in, and can do nothing else until they do.</span>
                        <?php else: ?>
                            <span class="badge badge-ok">active</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a class="btn btn-sm" href="<?= e(url('/admin/users/' . $id)) ?>">Edit</a>

                        <?php if (!$isSelf): ?>
                            <form method="post" action="<?= e(url('/admin/users/' . $id . '/toggle')) ?>" class="inline-form"
                                  <?= $isActive ? 'data-confirm="Deactivate this account? They are signed out immediately and their history is kept."' : '' ?>>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-ghost" <?= $onlyAdmin ? 'disabled' : '' ?>>
                                    <?= $isActive ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2 class="section-title">What the roles can do</h2>

<p class="muted">
    Read from the same list the application enforces on every request, so this table cannot
    describe a permission model that is not actually in force. Roles are cumulative: each one can
    do everything the one before it can.
</p>

<div class="card-grid">
    <?php foreach ($capabilities as $role => $held): ?>
        <div class="card">
            <h3><?= e($roleNames[$role] ?? $role) ?></h3>
            <ul class="tick-list">
                <?php foreach ($held as $capability): ?>
                    <li>
                        <?= e($capabilityLabels[$capability] ?? $capability) ?>
                        <?php if (!in_array($capability, Auth::capabilitiesAddedBy($role), true)): ?>
                            <span class="cell-sub">inherited</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</div>
