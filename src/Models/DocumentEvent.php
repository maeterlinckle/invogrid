<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * What the pipeline did to a document, stage by stage.
 *
 * Separate from `audit_log`, which records what people did. Mixing the two
 * makes both unreadable: somebody asking who approved a bill does not want
 * forty OCR retries in the answer, and somebody debugging a stuck document does
 * not want sign-ins.
 *
 * This is also what makes a failure recoverable rather than lost — a document
 * sitting in `failed` has a row here saying which stage, when, and why.
 */
final class DocumentEvent
{
    public const STARTED   = 'started';
    public const SUCCEEDED = 'succeeded';
    public const FAILED    = 'failed';
    public const SKIPPED   = 'skipped';

    /**
     * @param array<string,scalar|null> $context Structured detail of a failure —
     *        which provider, which model, what it answered. See
     *        `App\Services\Diagnosable`. Never put a credential in it: this is
     *        written to the database and rendered on the document page.
     */
    public static function record(
        int $documentId,
        string $stage,
        string $status,
        ?string $message = null,
        ?int $durationMs = null,
        array $context = [],
    ): void {
        Database::insert('document_events', [
            'document_id' => $documentId,
            'stage'       => mb_substr($stage, 0, 32),
            'status'      => $status,
            // Long enough for a stack-trace-free error, short enough that a
            // provider returning a page of HTML does not fill the table.
            'message'     => $message === null ? null : mb_substr($message, 0, 2000),
            'context'     => $context === [] ? null : json_encode(
                $context,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * The structured detail on an event row, or an empty array.
     *
     * @param  array<string,mixed> $event
     * @return array<string,scalar>
     */
    public static function context(array $event): array
    {
        $raw = $event['context'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_filter($decoded, 'is_scalar') : [];
    }

    /** @return array<int,array<string,mixed>> */
    public static function forDocument(int $documentId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return Database::select(
            'SELECT * FROM document_events
              WHERE document_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT ' . $limit,
            [$documentId]
        );
    }

    /**
     * The most recent failure across all documents, for the dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recentFailures(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        return Database::select(
            'SELECT e.*, d.paperless_doc_id
               FROM document_events e
               JOIN documents d ON d.id = e.document_id
              WHERE e.status = ?
              ORDER BY e.created_at DESC, e.id DESC
              LIMIT ' . $limit,
            [self::FAILED]
        );
    }
}
