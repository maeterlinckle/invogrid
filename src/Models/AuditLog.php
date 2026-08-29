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
}
