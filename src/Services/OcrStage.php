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
 *
 * This is also where the two flows are *decided*, though not where they part:
 * a page carrying a handwritten Clearbooks Number is a scan of an invoice
 * already in Clear Books, and `route()` writes that on the document. Both flows
 * then run the identical pipeline and diverge at the end of matching. See
 * `route()`.
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

        $resultId = OcrResult::create($id, [
            'llm_provider'       => $response->provider,
            'llm_model'          => $response->model,
            'raw_text'           => $response->text,
            'structured'         => $structured,
            'prompt_template_id' => $promptId,
            'prompt_tokens'      => $response->promptTokens,
            'completion_tokens'  => $response->completionTokens,
            'duration_ms'        => $response->durationMs,
        ]);

        // Read back rather than reported from `$structured`, so the note and the
        // routing decision below both describe the values as they were stored —
        // "#80421" written on the page and `80421` in the column must not turn
        // into two different numbers in the same document's history.
        $result = OcrResult::find($resultId) ?? [];

        // The annotations themselves are not consumed until the extraction
        // stage, but a note now is what tells somebody watching the queue that
        // there is handwriting on this one.
        $this->recordAnnotationSummary($id, $structured, $result);

        return $this->route($id, $result);
    }

    /**
     * Record which of the two flows this document is on.
     *
     * A handwritten Clearbooks Number is a reference to an invoice already in
     * Clear Books, so the document is a scan belonging to a record that exists
     * rather than a bill to post. This is the stage that reads that number, so
     * this is where the answer is written down.
     *
     * **It writes `documents.route` and nothing else.** Every document goes on
     * to `ocr_done` and through the same extraction and matching, whichever
     * flow it is on — see `Document::ROUTE_NEW`. The route is read at the
     * *exit* of the matching stage, which is where the two actually part
     * company: one creates a record in Clear Books, the other matches an
     * existing one.
     *
     * An earlier version of this branch returned `existing_invoice` here and
     * skipped extraction, to save four model calls on a question the
     * handwriting had already answered. It saved the calls and lost the
     * document: no supplier, no dates, no line items, nothing to search on, and
     * two pipelines to keep in step for ever after.
     *
     * @param array<string,mixed> $result The stored OCR result
     * @return string The status the document moves to
     */
    private function route(int $documentId, array $result): string
    {
        $number = OcrResult::clearbooksNumber($result);

        if (OcrResult::isUsableNumber($number)) {
            Document::setRoute($documentId, Document::ROUTE_EXISTING);

            DocumentEvent::record(
                $documentId,
                'route',
                DocumentEvent::SUCCEEDED,
                'Clearbooks Number ' . $number . ' is written on the page, so this is an existing invoice '
                . 'rather than a new one. It will be read and extracted like any other document, and '
                . 'matched against that Clear Books record instead of creating one.'
            );

            return Document::OCR_DONE;
        }

        Document::setRoute($documentId, Document::ROUTE_NEW);

        // A number that came back but is not digits is a misread, and the
        // prompt says why it matters: a code with letters in it is a Project,
        // not a Clearbooks Number. Saying so beats routing on it, and beats
        // dropping it silently — somebody looking at the page and at this
        // document will want to know the two were not the same.
        $message = $number === null
            ? 'No Clearbooks Number on the page, so this is a new invoice. Sent to the New Invoice flow.'
            : 'The Clearbooks Number came back as "' . $number . '", which is not digits only and so cannot '
              . 'be a Clear Books reference. Treated as absent, and sent to the New Invoice flow.';

        DocumentEvent::record(
            $documentId,
            'route',
            $number === null ? DocumentEvent::SUCCEEDED : DocumentEvent::SKIPPED,
            $message
        );

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
     * @param array<string,mixed>|null $structured What the model returned
     * @param array<string,mixed>      $result     The row it was stored as
     */
    private function recordAnnotationSummary(int $documentId, ?array $structured, array $result): void
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

        // The stored columns, not the response: `OcrResult` is where the two
        // fields are named — `clearbooksNumber` with its lower-case b, and
        // `project` rather than `projectCode` — and one place knowing that is
        // the point of promoting them. Reading the wrong key here used to mean
        // every document silently reported no annotations.
        $clearBooks  = OcrResult::clearbooksNumber($result);
        $project     = OcrResult::projectCode($result);
        $annotations = OcrResult::annotations($result);

        $notes = [];

        $notes[] = $clearBooks === null ? 'no Clearbooks Number' : 'Clearbooks Number ' . $clearBooks;
        $notes[] = $project === null ? 'no project' : 'project ' . $project;

        if ($annotations !== []) {
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
