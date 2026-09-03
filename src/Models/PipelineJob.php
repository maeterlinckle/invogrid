<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The work queue.
 *
 * An upload has to answer while somebody is watching the page, and reading a
 * scanned invoice takes tens of seconds. So the ingest route writes a row here
 * and returns immediately; `bin/process-queue.php`
 * does the work a moment later.
 *
 * Claiming is done inside a transaction with `FOR UPDATE SKIP LOCKED`, so two
 * workers running at once take different jobs rather than the same one twice.
 * That needs MariaDB 10.6 or newer, which is already the stated minimum.
 */
final class PipelineJob
{
    public const QUEUED  = 'queued';
    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const FAILED  = 'failed';

    /**
     * How many times a job is retried before it is left alone.
     *
     * Four attempts over roughly ten minutes. Past that it is not a blip, and
     * repeating it every minute forever only fills the log — a human retries it
     * from the document page once whatever broke is fixed.
     */
    public const MAX_ATTEMPTS = 4;

    /**
     * Queue a stage for a document.
     *
     * Idempotent by (document, stage): the same file uploaded twice in a batch,
     * or a retry pressed twice, leaves one job. Without this the same document
     * would be read — and paid for — once per attempt to queue it.
     */
    public static function enqueue(int $documentId, string $stage, int $delaySeconds = 0): int
    {
        $existing = Database::selectOne(
            'SELECT id FROM pipeline_jobs
              WHERE document_id = ? AND stage = ? AND status IN (?, ?)
              LIMIT 1',
            [$documentId, $stage, self::QUEUED, self::RUNNING]
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return Database::insert('pipeline_jobs', [
            'document_id'  => $documentId,
            'stage'        => $stage,
            'status'       => self::QUEUED,
            'available_at' => date('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
        ]);
    }

    /**
     * Take the next job that is due, marking it running.
     *
     * Returns null when there is nothing to do.
     *
     * @return array<string,mixed>|null
     */
    public static function claim(): ?array
    {
        Database::beginTransaction();

        try {
            $row = Database::selectOne(
                'SELECT * FROM pipeline_jobs
                  WHERE status = ? AND available_at <= NOW()
                  ORDER BY available_at, id
                  LIMIT 1
                  FOR UPDATE SKIP LOCKED',
                [self::QUEUED]
            );

            if ($row === null) {
                Database::commit();

                return null;
            }

            Database::run(
                'UPDATE pipeline_jobs
                    SET status = ?, started_at = NOW(), attempts = attempts + 1
                  WHERE id = ?',
                [self::RUNNING, (int) $row['id']]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();

            throw $e;
        }

        // The caller wants the attempt count as it now is, not as it was.
        $row['attempts'] = (int) $row['attempts'] + 1;
        $row['status']   = self::RUNNING;

        return $row;
    }

    public static function succeed(int $jobId): void
    {
        Database::run(
            'UPDATE pipeline_jobs SET status = ?, finished_at = NOW(), last_error = NULL WHERE id = ?',
            [self::DONE, $jobId]
        );
    }

    /**
     * Record a failure, and put the job back for another go unless it has had
     * enough.
     *
     * The delay grows with each attempt — 1, 2, 4, 8 minutes — so a model
     * provider that is rate-limiting or briefly down is waited out rather than
     * hammered.
     *
     * @param bool $permanent Some failures will never come right on their own:
     *                        a PDF that is not a PDF, an API key that has been
     *                        revoked. Retrying those is noise.
     */
    public static function fail(
        int $jobId,
        string $error,
        int $attempts,
        bool $permanent = false,
        ?int $retryAfter = null,
    ): bool {
        $error = mb_substr($error, 0, 2000);

        if ($permanent || $attempts >= self::MAX_ATTEMPTS) {
            Database::run(
                'UPDATE pipeline_jobs SET status = ?, finished_at = NOW(), last_error = ? WHERE id = ?',
                [self::FAILED, $error, $jobId]
            );

            return false;
        }

        /*
         * A far end that stated its own reset time beats any curve we invent.
         *
         * `Retry-After` is what a 429 carries, and a provider that says "four
         * minutes" means it: coming back at sixty seconds gets another 429 and
         * spends one of the four attempts learning what it had already been
         * told. Capped at an hour so a header nobody meant cannot park a
         * document until tomorrow, and floored at the ordinary backoff so it
         * can only ever make the wait *longer*, never shorter.
         */
        $backoffSeconds = 60 * (2 ** ($attempts - 1));

        if ($retryAfter !== null) {
            $backoffSeconds = max($backoffSeconds, min(3600, $retryAfter));
        }

        Database::run(
            'UPDATE pipeline_jobs
                SET status = ?, available_at = ?, last_error = ?, started_at = NULL
              WHERE id = ?',
            [self::QUEUED, date('Y-m-d H:i:s', time() + $backoffSeconds), $error, $jobId]
        );

        return true;
    }

    /**
     * Put a stuck job back.
     *
     * A worker killed mid-job — the container restarted, the cron run was
     * cut off — leaves a row marked running that nothing will ever finish.
     * Anything running for longer than any stage plausibly takes is assumed
     * dead and re-queued.
     */
    public static function releaseStalled(int $olderThanMinutes = 30): int
    {
        return Database::run(
            'UPDATE pipeline_jobs
                SET status = ?, started_at = NULL,
                    last_error = CONCAT(COALESCE(last_error, ""), " [released after stalling]")
              WHERE status = ? AND started_at < (NOW() - INTERVAL ? MINUTE)',
            [self::QUEUED, self::RUNNING, $olderThanMinutes]
        )->rowCount();
    }

    /** @return array<string,int> */
    public static function countsByStatus(): array
    {
        $counts = [self::QUEUED => 0, self::RUNNING => 0, self::DONE => 0, self::FAILED => 0];

        foreach (Database::select('SELECT status, COUNT(*) AS n FROM pipeline_jobs GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return array<int,array<string,mixed>> */
    public static function forDocument(int $documentId): array
    {
        return Database::select(
            'SELECT * FROM pipeline_jobs WHERE document_id = ? ORDER BY id DESC',
            [$documentId]
        );
    }

    /**
     * Clear a document's finished jobs so a stage can be queued afresh.
     *
     * Used by the retry action: without it, `enqueue()` would happily add a
     * second job beside a `failed` one, and the document's history would grow a
     * row per attempt with no way to tell which mattered.
     */
    public static function clearFinished(int $documentId): int
    {
        return Database::run(
            'DELETE FROM pipeline_jobs WHERE document_id = ? AND status IN (?, ?)',
            [$documentId, self::DONE, self::FAILED]
        )->rowCount();
    }
}
