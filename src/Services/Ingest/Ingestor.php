<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\Database;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\Setting;
use App\Services\IngestStage;
use App\Services\Pipeline;
use Throwable;

/**
 * Where every document enters InvoGrid.
 *
 * One route exists today — the upload page — and a watched directory is the
 * likely next one. Both hand an {@see IngestCandidate} to `accept()` and are
 * finished; everything that has to be true of a document before OCR is worth
 * spending money on happens here, once, rather than being reimplemented
 * slightly differently by each new route.
 *
 * ## What "accepted" means
 *
 * A row in `documents` at `received`, its PDF on disk outside the webroot, and
 * a queued `ingest` job. From that point the document is indistinguishable from
 * one that arrived any other way, which is the entire point: the pipeline past
 * this class has no idea routes exist.
 *
 * ## Order of operations
 *
 * Check, insert, store, queue — and if storing fails, the row is deleted again.
 * The alternative orders are both worse. Storing first means naming the file
 * before there is an id to name it after. Leaving the row behind on a failed
 * write means a document at `received` with no PDF, which the ingest stage will
 * pick up, fail, retry four times and finally park in front of a person who can
 * do nothing about it. A candidate that could not be stored was never accepted,
 * and the database should say so.
 */
final class Ingestor
{
    /**
     * The largest upload the application will take, in megabytes, when the
     * setting is missing or nonsense.
     *
     * Twenty-five is roomy for a scanned invoice — a colour A4 page at 300dpi
     * is a couple of megabytes — and small enough that a mistake costs a
     * disk write rather than a volume.
     */
    private const DEFAULT_MAX_MB = 25;

    /** Below this a PDF is a stub or a truncated transfer, not a document. */
    private const MIN_BYTES = 100;

    /**
     * Take a candidate into the pipeline.
     *
     * @return array<string,mixed> The created `documents` row.
     * @throws IngestException When the file is not something worth processing.
     */
    public static function accept(IngestCandidate $candidate): array
    {
        self::check($candidate);

        $filename = self::displayName($candidate->originalFilename);

        // Read before the bytes are moved: afterwards the candidate's path is
        // an empty space where a file used to be.
        $size = $candidate->size();

        $id = Database::insert('documents', [
            'ingest_source'     => mb_substr($candidate->source, 0, 32),
            'original_filename' => $filename,
            'ingested_by'       => $candidate->ingestedBy,
            'ingested_at'       => date('Y-m-d H:i:s'),
            'status'            => Document::RECEIVED,
        ]);

        try {
            self::store($candidate, $id);
        } catch (Throwable $e) {
            // Nothing references the row yet — no events, no jobs, no
            // extraction — so it can simply go, and the person who tried to
            // upload gets one error instead of a half-made document.
            Database::run('DELETE FROM documents WHERE id = ?', [$id]);

            throw new IngestException($e->getMessage(), 0, $e);
        }

        $document = Document::find($id);

        if ($document === null) {
            throw new IngestException('The document vanished immediately after being created.');
        }

        DocumentEvent::record($id, 'ingest', DocumentEvent::SUCCEEDED, sprintf(
            '%s: %s (%s).',
            IngestSource::label($candidate->source),
            $filename,
            self::formatBytes($size)
        ));

        // `lcfirst` because the labels are past participles — "Uploaded",
        // "Imported" — which read as a heading on the document page and as a
        // verb here. One list of words, two grammatical positions.
        AuditLog::record('document.ingested', $id, sprintf(
            'Document #%d %s as "%s".',
            $id,
            lcfirst(IngestSource::label($candidate->source)),
            $filename
        ));

        // Straight into the queue rather than waiting to be discovered. The
        // ingest stage verifies what was stored and hands on to OCR; a route
        // that wanted to defer that would not call this method at all.
        Pipeline::advance($id, Document::RECEIVED);

        return $document;
    }

    /**
     * The application's own upload ceiling, in bytes.
     *
     * Not the effective one — PHP and the web server each impose their own and
     * the smallest wins. {@see effectiveMaxBytes()} is what a screen should
     * quote at somebody.
     */
    public static function maxBytes(): int
    {
        $mb = Setting::int('ingest_max_upload_mb', self::DEFAULT_MAX_MB);

        // A zero or a negative would otherwise reject every file with "the
        // limit is 0 MB", which reads as a bug rather than as a setting.
        return max(1, $mb) * 1024 * 1024;
    }

    /**
     * The largest file that can actually get through, in bytes.
     *
     * `upload_max_filesize` and `post_max_size` are set in php.ini and cannot
     * be raised from here. A form that promises 25MB while PHP silently drops
     * anything over 2MB produces the worst kind of bug report — "it just goes
     * back to the list" — so the screen quotes this number instead.
     */
    public static function effectiveMaxBytes(): int
    {
        $limits = [self::maxBytes()];

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = self::iniBytes($directive);

            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    /**
     * Everything that has to be true before a row is created.
     *
     * The extension is not checked, and deliberately: a file dropped into a
     * watched directory by a scanner may be called anything at all, and the
     * first eight bytes of the file are a better answer than its name. The
     * upload route checks the extension as well, because there it is a way of
     * telling somebody they picked the wrong file rather than a security
     * control.
     */
    private static function check(IngestCandidate $candidate): void
    {
        if (!$candidate->isReadable()) {
            throw new IngestException('The file could not be read.');
        }

        $size = $candidate->size();

        if ($size < self::MIN_BYTES) {
            throw new IngestException('The file is empty or truncated.');
        }

        $max = self::maxBytes();

        if ($size > $max) {
            throw new IngestException(sprintf(
                'The file is %s and the limit is %s.',
                self::formatBytes($size),
                self::formatBytes($max)
            ));
        }

        // A PDF begins "%PDF-" and there is no version of it that does not.
        // This is what a JPEG renamed to .pdf fails, and it costs one read.
        $handle = @fopen($candidate->path, 'rb');

        if ($handle === false) {
            throw new IngestException('The file could not be opened.');
        }

        $magic = (string) fread($handle, 5);
        fclose($handle);

        if ($magic !== '%PDF-') {
            throw new IngestException('That is not a PDF. Only PDF files can be processed.');
        }
    }

    /**
     * Move the bytes into place and record where they went.
     *
     * Storage is one directory per document, outside the webroot: the page
     * images the OCR stage renders land beside the source, and
     * `documents.pdf_path` stays relative so the storage directory can be moved
     * or restored onto another machine without invalidating every row.
     */
    private static function store(IngestCandidate $candidate, int $id): void
    {
        $target    = IngestStage::storagePath($id);
        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new IngestException('Could not create the storage directory for this document.');
        }

        if (!$candidate->moveTo($target)) {
            throw new IngestException(
                'Could not save the file. Check the permissions on the storage directory.'
            );
        }

        @chmod($target, 0640);

        Database::update('documents', [
            'pdf_path' => IngestStage::relativePath($id),
        ], $id);
    }

    /** A filename from somewhere else, made safe to store and print back. */
    private static function displayName(string $name): string
    {
        $name = basename(str_replace(chr(92), '/', trim($name)));

        // Control characters in a filename are either a mistake or an attempt
        // to make the activity log render something it should not.
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return $name === '' ? 'document.pdf' : mb_substr($name, 0, 255);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** A php.ini size directive — "8M", "512K", "1G" — in bytes. */
    private static function iniBytes(string $directive): int
    {
        $value = trim((string) ini_get($directive));

        if ($value === '') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
