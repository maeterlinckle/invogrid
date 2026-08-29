<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\Document;
use RuntimeException;

/**
 * The first stage: fetch everything Paperless knows about the document, and the
 * source PDF itself.
 *
 * The webhook gives us an id and nothing else — deliberately, because a webhook
 * body is whatever the workflow was configured to send and cannot be trusted to
 * be complete or current. Everything real is read back from the API.
 */
final class IngestStage
{
    /**
     * @param array<string,mixed> $document
     * @return string The status the document moves to
     */
    public function run(array $document): string
    {
        $id         = (int) $document['id'];
        $paperlessId = (int) $document['paperless_doc_id'];

        $client = new PaperlessClient();

        // Metadata first. If the document has been deleted in Paperless this
        // throws PaperlessNotFoundException, which the runner treats as
        // permanent — there is no point retrying for something that is gone.
        $meta = $client->document($paperlessId);

        $path = self::storagePath($id);

        // A retry re-downloads rather than trusting whatever is on disk: the
        // usual reason for a retry is that the last attempt left something
        // half-written.
        if (is_file($path)) {
            @unlink($path);
        }

        $bytes = $client->downloadOriginal($paperlessId, $path);

        Database::update('documents', [
            // Relative to the storage path, so moving the storage directory or
            // restoring onto a different machine does not invalidate every row.
            'pdf_path' => self::relativePath($id),

            // The correspondent Paperless already believes in, if any. Only a
            // starting point: the extraction stage reads the issuer off the page
            // and the two are reconciled later.
            'correspondent_raw' => self::correspondentName($meta, $client),

            // Cleared because this attempt worked.
            'failed_stage'  => null,
            'error_message' => null,
        ], $id);

        error_log(sprintf(
            '[ingest] document %d (paperless %d): %s, %d bytes',
            $id,
            $paperlessId,
            (string) ($meta['title'] ?? 'untitled'),
            $bytes
        ));

        return Document::OCR_PENDING;
    }

    /**
     * Where a document's source PDF lives, absolute.
     *
     * One directory per document rather than one flat folder: the page images
     * the next stage renders belong beside it, and a directory with a hundred
     * thousand files in it is a directory nothing enjoys listing.
     */
    public static function storagePath(int $documentId): string
    {
        return rtrim((string) Config::get('storage.pdf'), '/' . chr(92))
            . DIRECTORY_SEPARATOR . $documentId
            . DIRECTORY_SEPARATOR . 'source.pdf';
    }

    /** The same path as stored in the database: relative to the storage root. */
    public static function relativePath(int $documentId): string
    {
        return 'pdf/' . $documentId . '/source.pdf';
    }

    /**
     * Turn a stored relative path back into an absolute one, refusing anything
     * that tries to climb out of the storage directory.
     */
    public static function absolutePath(string $relative): ?string
    {
        $relative = str_replace(chr(92), '/', trim($relative));

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $base = rtrim((string) Config::get('storage.path'), '/' . chr(92));
        $full = $base . DIRECTORY_SEPARATOR . ltrim($relative, '/');

        if (!is_file($full)) {
            return null;
        }

        $real     = realpath($full);
        $realBase = realpath($base);

        if ($real === false || $realBase === false || !str_starts_with($real, $realBase)) {
            return null;
        }

        return $real;
    }

    /**
     * The correspondent's name, given a document whose `correspondent` is an id.
     *
     * Paperless returns the id, not the name, unless asked otherwise. One extra
     * call per document is cheap and saves the review screen showing "17".
     *
     * @param array<string,mixed> $meta
     */
    private static function correspondentName(array $meta, PaperlessClient $client): ?string
    {
        $correspondent = $meta['correspondent'] ?? null;

        if (!is_int($correspondent) && !ctype_digit((string) $correspondent)) {
            return null;
        }

        static $names = null;

        if ($names === null) {
            $names = [];

            try {
                foreach ($client->correspondents() as $row) {
                    $names[(int) $row['id']] = (string) $row['name'];
                }
            } catch (RuntimeException) {
                // Not worth failing the whole ingest over: the name is a
                // convenience, and the extraction stage reads the issuer off
                // the page anyway.
                return null;
            }
        }

        return $names[(int) $correspondent] ?? null;
    }
}
