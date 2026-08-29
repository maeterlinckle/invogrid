<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentPage;
use App\Models\OcrResult;
use App\Models\PromptTemplate;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmFactory;
use App\Services\Llm\LlmImage;
use RuntimeException;

/**
 * Render the stored PDF to page images, then read them with a vision model.
 *
 * Rendering and transcription are one stage rather than two on purpose. They
 * always happen together, the images have no value on their own, and splitting
 * them would mean a status for "rendered but not read" that nothing would ever
 * usefully sit in. The rendering is recorded as its own event so a slow render
 * and a slow model call can still be told apart.
 */
final class OcrStage
{
    /**
     * @param array<string,mixed> $document
     * @return string The status the document moves to
     */
    public function run(array $document): string
    {
        $id = (int) $document['id'];

        $pdfPath = $document['pdf_path'] === null
            ? null
            : IngestStage::absolutePath((string) $document['pdf_path']);

        if ($pdfPath === null) {
            // Recoverable, and the retry action puts it back to `received` where
            // the ingest stage will fetch the PDF again.
            throw new RuntimeException(
                'The source PDF is missing from disk. Retry this document from Received to fetch it again.'
            );
        }

        $images = $this->renderPages($id, $pdfPath);

        // The prompt is read once and its id recorded, so a result can always
        // say which version of which prompt produced it.
        $prompt   = PromptTemplate::active('ocr');
        $promptId = $prompt === null ? null : (int) $prompt['id'];

        if ($prompt === null) {
            throw new RuntimeException('No active OCR prompt. Run the migrations, or activate one in Settings.');
        }

        $client = LlmFactory::forStage('ocr');

        DocumentEvent::record(
            $id,
            'ocr',
            DocumentEvent::STARTED,
            sprintf(
                'Sending %d page%s to %s (%s), prompt v%d.',
                count($images),
                count($images) === 1 ? '' : 's',
                $client->provider(),
                $client->model(),
                (int) $prompt['version']
            )
        );

        $response = $client->ocr($images, (string) $prompt['content']);

        // Parsed once, here. Everything downstream reads the stored columns.
        $structured = $response->json();

        OcrResult::create($id, [
            'llm_provider'       => $response->provider,
            'llm_model'          => $response->model,
            'raw_text'           => $response->text,
            'structured'         => $structured,
            'prompt_template_id' => $promptId,
            'prompt_tokens'      => $response->promptTokens,
            'completion_tokens'  => $response->completionTokens,
            'duration_ms'        => $response->durationMs,
        ]);

        // The structured half is not consumed until the extraction stage, but a
        // note now is what tells somebody watching the queue that there is
        // handwriting on this one.
        $this->recordAnnotationSummary($id, $structured);

        return Document::OCR_DONE;
    }

    /**
     * Render the pages, unless a usable set is already on disk.
     *
     * A retry after the model call failed — a rate limit, a timeout — should not
     * pay to re-render pages that came out perfectly well the first time.
     * Rendering a fifteen-page scan is not free.
     *
     * @return array<int,LlmImage>
     */
    private function renderPages(int $documentId, string $pdfPath): array
    {
        $existing = DocumentPage::forDocument($documentId);
        $images   = [];

        if ($existing !== []) {
            $allPresent = true;

            foreach ($existing as $page) {
                $path = IngestStage::absolutePath((string) $page['image_path']);

                if ($path === null) {
                    $allPresent = false;
                    break;
                }

                $images[] = new LlmImage($path, (int) $page['page_number']);
            }

            if ($allPresent) {
                DocumentEvent::record(
                    $documentId,
                    'render',
                    DocumentEvent::SKIPPED,
                    count($images) . ' page image(s) already rendered.'
                );

                return $images;
            }

            $images = [];
        }

        $renderer = new PdfRenderer();
        $started  = microtime(true);

        $pages = $renderer->render(
            $pdfPath,
            DocumentPage::directory($documentId),
            DocumentPage::relativeDirectory($documentId)
        );

        DocumentPage::replaceAll($documentId, $pages);

        $bytes = array_sum(array_column($pages, 'bytes'));

        DocumentEvent::record(
            $documentId,
            'render',
            DocumentEvent::SUCCEEDED,
            sprintf(
                '%d page%s, %d KB, %dx%d.',
                count($pages),
                count($pages) === 1 ? '' : 's',
                (int) round($bytes / 1024),
                $pages[0]['width'],
                $pages[0]['height']
            ),
            (int) round((microtime(true) - $started) * 1000)
        );

        foreach ($pages as $page) {
            $images[] = new LlmImage($page['path'], $page['page']);
        }

        return $images;
    }

    /**
     * Note what the model found by hand, if it said.
     *
     * Best-effort: the prompt asks for JSON, but a transcription that came back
     * as plain prose is still a perfectly good transcription and must not fail
     * the stage. The extraction stage is where the structure is actually
     * depended upon.
     *
     * @param array<string,mixed>|null $structured
     */
    private function recordAnnotationSummary(int $documentId, ?array $structured): void
    {
        if ($structured === null) {
            DocumentEvent::record(
                $documentId,
                'ocr',
                DocumentEvent::SKIPPED,
                'The transcription was not valid JSON, so the annotation fields were not read. '
                . 'The text itself is stored and usable.'
            );

            return;
        }

        $notes = [];

        // `clearbooksNumber` and `project` — the names the production prompt
        // uses. Note the lower-case b: it does not follow the "Clear Books" the
        // rest of the application spells, and getting it wrong means both
        // fields silently read as absent on every document.
        $clearBooks = $structured['clearbooksNumber'] ?? null;
        $project    = $structured['project'] ?? null;

        $notes[] = is_scalar($clearBooks) && (string) $clearBooks !== ''
            ? 'Clearbooks Number ' . $clearBooks
            : 'no Clearbooks Number';

        $notes[] = is_string($project) && $project !== ''
            ? 'project ' . $project
            : 'no project';

        $annotations = $structured['handwrittenAnnotations'] ?? [];
        if (is_array($annotations) && $annotations !== []) {
            $notes[] = count($annotations) . ' handwritten annotation(s)';
        }

        DocumentEvent::record($documentId, 'ocr', DocumentEvent::SUCCEEDED, 'Read: ' . implode(', ', $notes) . '.');
    }

    /**
     * Whether a failure from this stage is worth retrying.
     *
     * Consulted by the pipeline runner: a rate limit will pass, a rejected API
     * key will not, and retrying the latter every minute achieves nothing but
     * noise in the log.
     */
    public static function isRetryable(\Throwable $e): bool
    {
        return $e instanceof LlmException && $e->retryable;
    }
}
