<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;

/**
 * What people did.
 *
 * Deliberately separate from document_events, which records what the pipeline
 * did. Mixing the two makes both harder to read: an administrator asking "who
 * approved this bill" does not want forty OCR retries in the answer.
 */
final class AuditLog
{
    public static function record(string $action, ?int $documentId = null, ?string $details = null): void
    {
        Database::insert('audit_log', [
            'user_id'     => Auth::id(),
            'document_id' => $documentId,
            'action'      => mb_substr($action, 0, 64),
            'details'     => $details,
            'ip_address'  => PHP_SAPI === 'cli' ? null : Request::ip(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        return Database::select(
            'SELECT a.*, u.username, u.display_name
               FROM audit_log a
               LEFT JOIN users u ON u.id = a.user_id
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT ' . $limit
        );
    }

    /**
     * One page of the log, newest first.
     *
     * Paged rather than capped: `recent()` answers "what has just happened",
     * which is what the dashboard wants, and it is the wrong tool for "what did
     * this person do in March". The two exist side by side on purpose.
     *
     * @param array<string,string> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function paginate(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::filterClause($filters);

        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        // The document's ingest details travel with the row so a line about a
        // document can name the file it arrived as. A left join, because a
        // document deleted since leaves the audit row behind deliberately —
        // the log outlives what it describes.
        return Database::select(
            'SELECT a.*, u.username, u.display_name, d.original_filename, d.ingest_source
               FROM audit_log a
               LEFT JOIN users u ON u.id = a.user_id
               LEFT JOIN documents d ON d.id = a.document_id'
            . $where
            . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    }

    /** @param array<string,string> $filters */
    public static function countMatching(array $filters = []): int
    {
        [$where, $params] = self::filterClause($filters);

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM audit_log a
               LEFT JOIN users u ON u.id = a.user_id
               LEFT JOIN documents d ON d.id = a.document_id' . $where,
            $params
        );
    }

    /**
     * The actions that actually appear in the log, for the filter.
     *
     * Read from the data rather than from a list in PHP: an action is a string
     * passed to `record()` at the call site, so a hard-coded list here would go
     * stale the first time somebody logged something new and the filter would
     * quietly stop offering it.
     *
     * @return array<int,string>
     */
    public static function actions(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['action'],
            Database::select('SELECT DISTINCT action FROM audit_log ORDER BY action')
        );
    }

    /**
     * The people who appear in the log, for the filter.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function actors(): array
    {
        return Database::select(
            'SELECT DISTINCT u.id, u.username, u.display_name
               FROM audit_log a
               JOIN users u ON u.id = a.user_id
              ORDER BY u.display_name, u.username'
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forDocument(int $documentId): array
    {
        return Database::select(
            'SELECT a.*, u.username, u.display_name
               FROM audit_log a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.document_id = ?
              ORDER BY a.created_at DESC, a.id DESC',
            [$documentId]
        );
    }

    /**
     * @param array<string,string> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function filterClause(array $filters): array
    {
        $conditions = [];
        $params     = [];

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $conditions[] = 'a.action = ?';
            $params[]     = $action;
        }

        $user = trim((string) ($filters['user'] ?? ''));
        if ($user !== '') {
            $conditions[] = 'a.user_id = ?';
            $params[]     = (int) $user;
        }

        // A whole day either end, not a timestamp: somebody filtering to
        // "the 3rd" means all of it, and `created_at >= '2026-03-03'` on its
        // own excludes everything after midnight on the closing date.
        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $conditions[] = 'a.created_at >= ?';
            $params[]     = $from . ' 00:00:00';
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $conditions[] = 'a.created_at <= ?';
            $params[]     = $to . ' 23:59:59';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // A bare number is read as a document id first, because that is what
            // somebody holding a printed summary actually has. It still matches
            // the text, so a reference that happens to be numeric is not lost.
            if (ctype_digit($q)) {
                $conditions[] = '(a.document_id = ? OR a.details LIKE ?)';
                $params[]     = (int) $q;
                $params[]     = '%' . $q . '%';
            } else {
                $conditions[] = '(a.details LIKE ? OR u.username LIKE ? OR u.display_name LIKE ?)';
                $params[]     = '%' . $q . '%';
                $params[]     = '%' . $q . '%';
                $params[]     = '%' . $q . '%';
            }
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }
}
