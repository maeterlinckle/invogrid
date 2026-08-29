<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\PipelineJob;
use App\Services\Llm\LlmException;
use App\Services\Diagnosable;
use Throwable;

/**
 * The stage runner.
 *
 * One place that knows which stage follows which status, so adding a stage in a
 * later prompt is a row in `STAGES` plus a handler — not a change to the
 * webhook receiver, the queue, the retry action and the document page.
 *
 * A stage handler is given the document row and returns the status the document
 * should move to. It may throw; the runner records the failure, backs the job
 * off and leaves the document where a human can see it. What it must never do
 * is swallow an error and return a success status — a document that silently
 * skips a stage is far worse than one that visibly stops.
 */
final class Pipeline
{
    /**
     * stage key => what it consumes, what it produces, and who does it.
     *
     * `during` is the status the document wears while the stage is running,
     * where the state machine has one. The LLM stages take tens of seconds, and
     * a document that sits at "Read" for half a minute and then jumps to
     * "Extracted" gives somebody watching the list no idea whether anything is
     * happening. It is also the recovery point: a worker killed mid-stage
     * leaves the document there, and the stage accepts it back.
     *
     * `handler` is null for a stage a later prompt builds. Those are listed
     * anyway so the shape of the pipeline is readable in one place, and so
     * `stageFor()` can say "nothing runs this yet" rather than pretending the
     * document is finished.
     *
     * @var array<string,array{from:string,during:?string,to:string,label:string,handler:?class-string}>
     */
    public const STAGES = [
        'ingest' => [
            'from'    => Document::RECEIVED,
            'during'  => null,
            'to'      => Document::OCR_PENDING,
            'label'   => 'Fetch from Paperless',
            'handler' => IngestStage::class,
        ],
        'ocr' => [
            'from'    => Document::OCR_PENDING,
            'during'  => null,
            'to'      => Document::OCR_DONE,
            'label'   => 'Read the pages',
            'handler' => OcrStage::class,
        ],
        'extract' => [
            'from'    => Document::OCR_DONE,
            'during'  => Document::EXTRACTING,
            'to'      => Document::EXTRACTED,
            'label'   => 'Extract the fields',
            'handler' => ExtractStage::class,
        ],
        // `to` is the conservative outcome, not the only one. MatchStage
        // returns `ready_to_submit` when every entity resolved and nothing was
        // flagged; the registry records the destination a document reaches when
        // it needs a person, because that is the one the retry action and the
        // state-machine consistency check have to be right about.
        'match' => [
            'from'    => Document::EXTRACTED,
            'during'  => Document::MATCHING,
            'to'      => Document::NEEDS_REVIEW,
            'label'   => 'Match against Clear Books',
            'handler' => MatchStage::class,
        ],
    ];

    /**
     * The stage that acts on a document in this status, or null when nothing
     * does — either because the document is waiting on a person, or because the
     * stage has not been built yet.
     */
    public static function stageFor(string $status): ?string
    {
        foreach (self::STAGES as $key => $stage) {
            if ($stage['from'] === $status) {
                return $stage['handler'] === null ? null : $key;
            }
        }

        return null;
    }

    /** Queue whatever should happen next for a document, if anything should. */
    public static function advance(int $documentId, string $status, int $delaySeconds = 0): ?int
    {
        $stage = self::stageFor($status);

        return $stage === null ? null : PipelineJob::enqueue($documentId, $stage, $delaySeconds);
    }

    /**
     * Work the queue.
     *
     * `$limit` caps one run: a cron tick should finish in well under a minute
     * so runs do not pile up, and a backlog is better drained over several
     * ticks than in one run that overlaps the next.
     *
     * @param callable(string):void|null $log
     * @return array{claimed:int,succeeded:int,failed:int}
     */
    public static function work(int $limit = 5, ?callable $log = null): array
    {
        $say = $log ?? static function (string $line): void {
        };

        $released = PipelineJob::releaseStalled();
        if ($released > 0) {
            $say("released {$released} stalled job(s)");
        }

        $tally = ['claimed' => 0, 'succeeded' => 0, 'failed' => 0];

        for ($i = 0; $i < $limit; $i++) {
            $job = PipelineJob::claim();

            if ($job === null) {
                break;
            }

            $tally['claimed']++;

            if (self::runJob($job, $say)) {
                $tally['succeeded']++;
            } else {
                $tally['failed']++;
            }
        }

        return $tally;
    }

    /**
     * Run one claimed job.
     *
     * @param array<string,mixed> $job
     * @param callable(string):void $say
     */
    private static function runJob(array $job, callable $say): bool
    {
        $jobId      = (int) $job['id'];
        $documentId = (int) $job['document_id'];
        $stageKey   = (string) $job['stage'];
        $attempts   = (int) $job['attempts'];

        $stage = self::STAGES[$stageKey] ?? null;

        if ($stage === null || $stage['handler'] === null) {
            // A stage that was queued and then removed, or one a later prompt
            // has not built. Not an error worth retrying.
            PipelineJob::fail($jobId, 'No handler for stage ' . $stageKey, $attempts, true);
            DocumentEvent::record($documentId, $stageKey, DocumentEvent::SKIPPED, 'No handler for this stage.');
            $say("  job {$jobId}: no handler for {$stageKey}");

            return false;
        }

        $document = Document::find($documentId);

        if ($document === null) {
            PipelineJob::fail($jobId, 'Document no longer exists.', $attempts, true);
            $say("  job {$jobId}: document {$documentId} is gone");

            return false;
        }

        $status = (string) $document['status'];

        // `during` is accepted as well as `from`: a worker killed mid-stage
        // leaves the document in the working status, and the released job has
        // to be able to pick it back up. Without this, every interrupted
        // extraction would need a human to press Retry.
        $acceptable = array_filter([$stage['from'], $stage['during']]);

        // Otherwise the document moved on since the job was queued — a human
        // retried it, or ignored it. The job is stale, not failed.
        if (!in_array($status, $acceptable, true)) {
            PipelineJob::succeed($jobId);
            DocumentEvent::record(
                $documentId,
                $stageKey,
                DocumentEvent::SKIPPED,
                'Document was ' . $status . ', not ' . implode(' or ', $acceptable) . '.'
            );
            $say("  job {$jobId}: {$stageKey} skipped, document is {$status}");

            return true;
        }

        DocumentEvent::record($documentId, $stageKey, DocumentEvent::STARTED);
        $started = microtime(true);

        try {
            // Show that it is being worked on, before the slow part.
            if ($stage['during'] !== null && $status !== $stage['during']) {
                Document::transitionTo($documentId, $stage['during']);
            }

            /** @var class-string $handler */
            $handler = $stage['handler'];
            $next    = (new $handler())->run($document);
            $elapsed = (int) round((microtime(true) - $started) * 1000);

            Document::transitionTo($documentId, $next);
            PipelineJob::succeed($jobId);
            DocumentEvent::record($documentId, $stageKey, DocumentEvent::SUCCEEDED, null, $elapsed);

            $say("  job {$jobId}: {$stageKey} ok in {$elapsed}ms, document now {$next}");

            // Straight on to whatever is next, rather than waiting for the
            // following cron tick. A document that needs three stages should
            // not take three minutes of wall clock to get through them.
            self::advance($documentId, $next);

            return true;
        } catch (Throwable $e) {
            $elapsed   = (int) round((microtime(true) - $started) * 1000);
            $permanent = self::isPermanent($e);

            /*
             * Everything the failure knows about itself, kept beside the event.
             *
             * `getMessage()` is one sentence because it goes on a badge. This
             * is which model was called, what it answered and how long it took
             * — the difference between reading the document page and reading
             * the server log, which is the whole of this prompt's third item.
             */
            $context = $e instanceof Diagnosable ? $e->context() : [];

            // The far end usually knows when its own limit resets. Retrying at
            // sixty seconds against a four-minute rate limit just burns one of
            // the four attempts.
            $retryAfter = $e instanceof Diagnosable ? $e->retryAfter() : null;

            $willRetry = PipelineJob::fail($jobId, $e->getMessage(), $attempts, $permanent, $retryAfter);

            if ($retryAfter !== null) {
                $context['asked us to wait'] = $retryAfter . 's';
            }

            $context['attempt'] = $attempts;

            DocumentEvent::record($documentId, $stageKey, DocumentEvent::FAILED, $e->getMessage(), $elapsed, $context);

            error_log(sprintf(
                '[pipeline] document %d stage %s attempt %d failed: %s',
                $documentId,
                $stageKey,
                $attempts,
                $e->getMessage()
            ));

            if ($willRetry) {
                // Deliberately *not* moved to `failed`: it is going to be tried
                // again shortly, and a status that flickers between failed and
                // running is a status nobody can act on.
                $say("  job {$jobId}: {$stageKey} failed, will retry — " . $e->getMessage());

                return false;
            }

            Document::markFailed($documentId, $stageKey, $e->getMessage(), $attempts);
            $say("  job {$jobId}: {$stageKey} failed for good — " . $e->getMessage());

            return false;
        }
    }

    /**
     * Is this failure one that will never come right on its own?
     *
     * The default is "retry", because most failures are transient and a
     * document that stops when it did not need to costs somebody an
     * interruption. The exceptions are the ones where trying again every minute
     * for four attempts achieves nothing but noise:
     *
     *  - the document has been deleted in Paperless;
     *  - a provider said no in a way that a second identical request will not
     *    change — a rejected API key, a model id that does not exist, an image
     *    it will not accept. `LlmException` carries that judgement itself,
     *    because only the client that made the call can tell a 429 from a 401.
     */
    private static function isPermanent(Throwable $e): bool
    {
        if ($e instanceof PaperlessNotFoundException) {
            return true;
        }

        if ($e instanceof LlmException) {
            return !$e->retryable;
        }

        // Same judgement, same reason: only the client that made the call can
        // tell a rate limit from a revoked authorisation. A 429 backs off; a
        // 401 or a business-rule rejection would fail identically four more
        // times, and a document stopped with "reconnect Clear Books" on it is
        // more use than one that keeps quietly retrying.
        if ($e instanceof ClearBooksException) {
            return !$e->retryable;
        }

        return false;
    }

    /**
     * Everything the queue is currently sitting on, for the document page and
     * for `bin/process-queue.php --status`.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::select(
            'SELECT j.*, d.paperless_doc_id
               FROM pipeline_jobs j
               JOIN documents d ON d.id = j.document_id
              WHERE j.status IN (?, ?)
              ORDER BY j.available_at, j.id
              LIMIT ' . $limit,
            [PipelineJob::QUEUED, PipelineJob::RUNNING]
        );
    }
}
