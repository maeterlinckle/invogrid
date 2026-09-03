<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * A file offered to the pipeline, before anything has been decided about it.
 *
 * This is the seam. An ingest route's whole job is to produce one of these and
 * hand it to {@see Ingestor}; everything after that — checking it really is a
 * PDF, creating the row, storing the bytes, queueing the first stage — happens
 * once, in one place, however the file arrived.
 *
 * The reason it is a class and not four arguments is `moveTo()`. A browser
 * upload has to be moved with `move_uploaded_file()`, which refuses any path
 * PHP did not itself receive as an upload — that refusal is a security check
 * worth keeping, so it cannot simply be `rename()` everywhere. A file found in
 * a watched directory is the opposite case: `move_uploaded_file()` would refuse
 * it. Each route knows which it is; nothing downstream needs to.
 *
 * A watched-directory route is then `fromFile()` plus a loop, and no change
 * anywhere else.
 */
final class IngestCandidate
{
    private function __construct(
        /** Where the bytes are right now. */
        public readonly string $path,

        /** What the file was called wherever it came from, for display only. */
        public readonly string $originalFilename,

        /** An {@see IngestSource} key, stored on the document. */
        public readonly string $source,

        /** The user who caused this, when a person did. Null for a robot. */
        public readonly ?int $ingestedBy,

        /** Whether the bytes are a PHP upload, which moves differently. */
        private readonly bool $isUpload,
    ) {
    }

    /**
     * A file that arrived in `$_FILES`.
     *
     * `$path` must be the `tmp_name`, untouched — `move_uploaded_file()` checks
     * it against the list of paths this request actually received, and that
     * check is the only thing standing between an upload handler and being
     * asked to move `/etc/passwd` into the storage directory.
     */
    public static function fromUpload(string $tmpName, string $originalFilename, ?int $userId): self
    {
        return new self($tmpName, $originalFilename, IngestSource::UPLOAD, $userId, true);
    }

    /**
     * An ordinary file on disk — a watched directory, a re-ingest, a test.
     *
     * @param string $source An {@see IngestSource} key.
     */
    public static function fromFile(
        string $path,
        string $originalFilename,
        string $source,
        ?int $userId = null,
    ): self {
        return new self($path, $originalFilename, $source, $userId, false);
    }

    /**
     * Put the bytes at `$target`, whatever kind of file this is.
     *
     * Returns false rather than throwing so the caller can roll back the row it
     * has already created and report one failure rather than two.
     */
    public function moveTo(string $target): bool
    {
        if ($this->isUpload) {
            return move_uploaded_file($this->path, $target);
        }

        // rename() first: on the same volume it is atomic and free. It fails
        // across volumes — a watched directory on a different mount is the
        // ordinary case, not an exotic one — so fall back to a copy.
        if (@rename($this->path, $target)) {
            return true;
        }

        if (!@copy($this->path, $target)) {
            return false;
        }

        /*
         * And then remove the original, because this method is a *move*.
         *
         * The two branches would otherwise disagree: same-volume leaves nothing
         * behind, cross-volume leaves the file where it was. For a watched
         * directory that difference is the whole feature — a file left in place
         * is a file ingested again on the next sweep, and again after that.
         *
         * A failure to unlink is not a failure to ingest: the document is
         * stored and the row exists. It is logged so a directory quietly
         * filling up has something to find.
         */
        if (!@unlink($this->path)) {
            error_log('[ingest] copied but could not remove the original: ' . $this->path);
        }

        return true;
    }

    /**
     * Is this file readable at all, and does it look like a PHP upload when it
     * claims to be one?
     *
     * Checked before anything is written to the database, so a request that
     * lied about where its bytes came from leaves nothing behind.
     */
    public function isReadable(): bool
    {
        if ($this->isUpload && !is_uploaded_file($this->path)) {
            return false;
        }

        return is_file($this->path) && is_readable($this->path);
    }

    public function size(): int
    {
        $size = @filesize($this->path);

        return $size === false ? 0 : $size;
    }
}
