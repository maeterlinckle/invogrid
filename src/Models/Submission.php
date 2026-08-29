<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

/**
 * What was sent to Clear Books, and what came back.
 *
 * Every attempt is a row, failures included, and that is the point: a
 * submission that Clear Books rejected is the thing somebody most needs to be
 * able to read afterwards, and a table holding only successes cannot answer
 * "what did we actually send".
 *
 * `clearbooks_url` is kept because Clear Books has no API for a purchase line's
 * project code. Every submitted document therefore offers a link straight into
 * the Clear Books web interface for a person to set one by hand — which is why
 * the URL is stored rather than rebuilt: the address of the record that was
 * created is a fact about that submission, not a template applied later.
 */
final class Submission
{
    public const SUCCESS = 'success';
    public const FAILED  = 'failed';

    /**
     * @param array<string,mixed> $response What Clear Books said
     */
    public static function record(
        int $documentId,
        string $clearbooksType,
        ?string $clearbooksId,
        ?string $url,
        string $status,
        array $response = [],
    ): int {
        return Database::insert('submissions', [
            'document_id'     => $documentId,
            'clearbooks_type' => mb_substr($clearbooksType, 0, 64),
            'clearbooks_id'   => $clearbooksId === null ? null : mb_substr($clearbooksId, 0, 64),
            'clearbooks_url'  => $url === null ? null : mb_substr($url, 0, 255),
            'status'          => $status,
            'submitted_by'    => Auth::id(),
            'response_json'   => $response === []
                ? null
                : json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * The columns every reader gets.
     *
     * The join is here rather than at each call site because the templates show
     * who submitted a document, and a reader that returns the row *without*
     * `display_name` is indistinguishable from one that returns it right up
     * until a page dies half-rendered. That happened: `successful()` selected
     * `submissions.*` alone and the document page threw "Undefined array key"
     * mid-template. One shape for every reader is what stops it recurring.
     */
    private const COLUMNS = 'SELECT s.*, u.username, u.display_name
               FROM submissions s
               LEFT JOIN users u ON u.id = s.submitted_by';

    /** @return array<string,mixed>|null */
    public static function latest(int $documentId): ?array
    {
        return Database::selectOne(
            self::COLUMNS . ' WHERE s.document_id = ? ORDER BY s.submitted_at DESC, s.id DESC LIMIT 1',
            [$documentId]
        );
    }

    /**
     * The successful one, if there is one.
     *
     * This is the idempotency check. A document that already has a successful
     * submission must not be sent again — a bill entered twice in somebody's
     * accounts is discovered by a payment run, not by this application.
     *
     * @return array<string,mixed>|null
     */
    public static function successful(int $documentId): ?array
    {
        return Database::selectOne(
            self::COLUMNS . ' WHERE s.document_id = ? AND s.status = ? AND s.clearbooks_id IS NOT NULL
              ORDER BY s.submitted_at DESC, s.id DESC LIMIT 1',
            [$documentId, self::SUCCESS]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forDocument(int $documentId): array
    {
        return Database::select(
            self::COLUMNS . ' WHERE s.document_id = ? ORDER BY s.submitted_at DESC, s.id DESC',
            [$documentId]
        );
    }

    /**
     * Decode the stored response.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function response(array $row): array
    {
        $value = $row['response_json'] ?? null;

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
