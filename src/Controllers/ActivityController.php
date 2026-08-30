<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Models\AuditLog;

/**
 * What people did, in full.
 *
 * The counterpart to the dashboard's feed, which shows the last fifteen entries
 * and answers "what has just happened". This answers the other question — "who
 * changed this, and when" — and that one needs filters and pages.
 *
 * `audit.view`, which only an administrator holds. It is deliberately separate
 * from `documents.view`: the log names people, and half its lines are about
 * accounts and credentials rather than about documents.
 *
 * Read-only, with no route that writes. An audit log a user interface can edit
 * is not an audit log, and there is no "clear the log" button here for the same
 * reason.
 *
 * `document_events` — what the *machine* did — is not shown here. It is on each
 * document's own page, where it belongs: an administrator asking who approved a
 * bill does not want forty OCR retries in the answer.
 */
final class ActivityController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): void
    {
        $filters = [
            'action' => (string) Request::query('action', ''),
            'user'   => (string) Request::query('user', ''),
            'from'   => (string) Request::query('from', ''),
            'to'     => (string) Request::query('to', ''),
            'q'      => (string) Request::query('q', ''),
        ];

        $total = AuditLog::countMatching($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, (int) Request::query('page', 1)), $pages);

        $this->view('admin/activity', [
            'pageTitle' => 'Activity log',
            'entries'   => AuditLog::paginate($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'filters'   => $filters,
            'filtered'  => array_filter($filters, static fn (string $v): bool => trim($v) !== '') !== [],
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,

            // Both read from the log itself rather than from a list in PHP: an
            // action is whatever string a call site passed, so a hard-coded
            // list would go stale the first time somebody logged a new one.
            'actions'   => AuditLog::actions(),
            'actors'    => AuditLog::actors(),
        ]);
    }
}
