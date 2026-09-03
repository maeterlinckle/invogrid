<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\Document;
use RuntimeException;

/**
 * The first stage: check that what was ingested is actually processable.
 *
 * The route that accepted the document — {@see \App\Services\Ingest\Ingestor} —
 * has already stored the PDF and created the row, so this stage does not fetch
 * anything. It exists for two reasons that outlived the Paperless download it
 * replaced.
 *
 * **It is the gate in front of the expensive part.** OCR renders every page and
 * sends each one to a vision model. A PDF that is missing, truncated, encrypted
 * or not really a PDF should be found here, once, for the cost of a stat and a
 * `pdfinfo` call — not discovered by a model that has already been paid for
 * three pages of nothing.
 *
 * **It gives every route the same first step.** A watched directory can copy a
 * file that another process is still writing; `Ingestor` reads a plausible size
 * and a `%PDF-` header from a file that is nonetheless half a document. This
 * stage runs a moment later, from the queue, and is where that shows up — as a
 * retryable failure on a document a person can see, which is exactly what the
 * retry machinery is for.
 *
 * Keeping `received -> ocr_pending` as a queued stage rather than folding it
 * into the ingest routes also means the queue processor remains the only thing
 * that advances a document, however it arrived.
 */
final class IngestStage
{
    /**
     * @param array<string,mixed> $document
     * @return string The status the document moves to
     */
    public function run(array $document): string
    {
        $id       = (int) $document['id'];
        $relative = (string) ($document['pdf_path'] ?? '');

        if ($relative === '') {
            throw new RuntimeException(
                'No PDF was stored for this document, so there is nothing to read.'
            );
        }

        $path = self::absolutePath($relative);

        if ($path === null) {
            throw new RuntimeException(
                'The stored PDF is missing from disk. It may have been removed, or the '
                . 'storage directory may not be the one it was written to.'
            );
        }

        $bytes = filesize($path);

        if ($bytes === false || $bytes < 1) {
            throw new RuntimeException('The stored PDF is empty.');
        }

        self::assertPdf($path);

        $renderer = new PdfRenderer();

        /*
         * `pageCount()` returns null when poppler is not installed, which is a
         * deployment problem the OCR stage reports far better than this one
         * can — it is the stage that actually needs the renderer. A null here
         * means "could not check", not "no pages", so it must not fail.
         *
         * A zero, on the other hand, is `pdfinfo` reading the file and finding
         * nothing in it: a real answer, and a bad one.
         */
        $pages = $renderer->pageCount($path);

        if ($pages !== null && $pages < 1) {
            throw new RuntimeException('The PDF has no pages in it.');
        }

        Database::update('documents', [
            'page_count' => $pages,

            // Cleared because this attempt worked. A document that failed here
            // and was fixed should not keep wearing the old error.
            'failed_stage'  => null,
            'error_message' => null,
        ], $id);

        error_log(sprintf(
            '[ingest] document %d (%s): %s, %d bytes, %s',
            $id,
            (string) ($document['ingest_source'] ?? 'unknown'),
            (string) ($document['original_filename'] ?? 'unnamed'),
            $bytes,
            $pages === null ? 'page count unknown' : $pages . ' page(s)'
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
     * That the file on disk is a PDF, checked again here rather than trusted
     * from ingest.
     *
     * The two checks are not the same check twice. `Ingestor` reads the header
     * of a file it is about to accept, to reject an obvious mistake before
     * creating anything. This reads the header of the file that was actually
     * stored — after a move that could have been interrupted, from a directory
     * a backup or a sync client may have touched since.
     */
    private static function assertPdf(string $path): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The stored PDF could not be opened for reading.');
        }

        $magic = (string) fread($handle, 5);
        fclose($handle);

        if ($magic !== '%PDF-') {
            throw new RuntimeException(
                'The stored file is not a PDF. It may have been replaced, or the transfer '
                . 'that wrote it may not have finished.'
            );
        }
    }
}
