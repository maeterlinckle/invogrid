<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Response;
use App\Core\Upload;
use App\Services\Ingest\IngestCandidate;
use App\Services\Ingest\IngestException;
use App\Services\Ingest\Ingestor;
use Throwable;

/**
 * The upload page: the one ingest route that exists today.
 *
 * Thin on purpose. Everything that decides whether a file becomes a document
 * lives in {@see Ingestor}, so the watched-directory route that follows this
 * one gets the same checks, the same storage layout and the same queued first
 * stage without any of it being copied. What is left here is the part that is
 * genuinely about a browser: reading `$_FILES`, turning PHP's upload error
 * codes into English, and saying what happened to each file.
 *
 * Several files at once, because invoices arrive in handfuls and uploading them
 * one page-load at a time is a chore. Each is accepted or refused on its own —
 * one bad file in a batch of six must not discard the other five, which is the
 * same rule the branding form follows for its two logos.
 */
final class UploadController extends Controller
{
    /** The form field, and the only one. */
    private const FIELD = 'documents';

    /**
     * How many files one submission may carry.
     *
     * Not a security boundary — `post_max_size` is that — but a limit that
     * keeps one request inside the web server's timeout. Each accepted file is
     * a database write and a disk write, not an OCR run, so this is generous.
     */
    private const MAX_FILES = 20;

    public function form(): void
    {
        $this->view('documents/upload', [
            'pageTitle' => 'Upload documents',
            'maxBytes'  => Ingestor::effectiveMaxBytes(),
            'maxFiles'  => self::MAX_FILES,
        ]);
    }

    /**
     * Take the files off the form and hand each to the ingestor.
     *
     * A completely empty submission is a mistake rather than an error — the
     * commonest cause is pressing Upload before the file picker has been used —
     * so it says so and returns to the form with nothing lost.
     */
    public function store(): void
    {
        $files = Upload::files(self::FIELD);
        $back  = '/documents/upload';

        /*
         * An empty $_FILES is ambiguous, and the ambiguity matters. It means
         * either "no file was chosen" or "the whole request body was discarded
         * because it exceeded post_max_size" — and in the second case PHP
         * throws away $_POST as well, so the form looks untouched to a person
         * who just waited two minutes for a large upload. The empty $_POST is
         * what tells the two apart.
         */
        if ($files === []) {
            if ($_POST === [] && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                Flash::error(sprintf(
                    'That was too large for the server to accept at all. The limit is %s per '
                    . 'submission — try fewer files at a time.',
                    Upload::formatBytes(Ingestor::effectiveMaxBytes())
                ));

                Response::redirect($back);
            }

            Flash::info('No file was chosen, so nothing was uploaded.');
            Response::redirect($back);
        }

        if (count($files) > self::MAX_FILES) {
            Flash::error(sprintf(
                'That is %d files, and %d is the most that can be uploaded at once.',
                count($files),
                self::MAX_FILES
            ));

            Response::redirect($back);
        }

        $accepted = [];
        $refused  = [];

        foreach ($files as $file) {
            $name = Upload::displayName($file['name']);

            /*
             * PHP's own verdict on the transfer comes first: a file that was
             * truncated, or that the server refused, has nothing worth
             * checking further.
             *
             * The deep content check is deliberately a no-op here. `Upload`
             * defaults to "does this decode as an image", which a PDF fails,
             * and the real answer — does the file begin `%PDF-` — is one the
             * ingestor asks of every route rather than only of this one.
             * Checking it twice with two different messages would mean the
             * upload page and the watched directory disagreeing about what a
             * PDF is.
             */
            $problem = Upload::validate(
                $file,
                ['application/pdf'],
                ['pdf'],
                Ingestor::effectiveMaxBytes(),
                static fn (string $path): ?string => null,
            );

            if ($problem !== null) {
                $refused[] = $problem;
                continue;
            }

            try {
                $document = Ingestor::accept(IngestCandidate::fromUpload(
                    $file['tmp_name'],
                    $file['name'],
                    Auth::id()
                ));

                $accepted[] = $document;
            } catch (IngestException $e) {
                $refused[] = $name . ': ' . $e->getMessage();
            } catch (Throwable $e) {
                // Anything else is InvoGrid's fault rather than the file's. It
                // is still reported against the file, because that is what the
                // person can act on, but it goes to the log in full.
                error_log('[upload] ' . $name . ': ' . $e->getMessage());
                $refused[] = $name . ': it could not be saved. The error has been logged.';
            }
        }

        foreach ($refused as $problem) {
            Flash::error($problem);
        }

        if ($accepted === []) {
            Response::redirect($back);
        }

        // One document goes straight to it; a batch goes to the list, because
        // there is no single document to show and the list is where somebody
        // watches them move through the pipeline.
        if (count($accepted) === 1 && $refused === []) {
            $id = (int) $accepted[0]['id'];

            Flash::success('Uploaded. It is queued for reading now.');
            Response::redirect('/documents/' . $id);
        }

        Flash::success(sprintf(
            '%d document%s uploaded and queued for reading.',
            count($accepted),
            count($accepted) === 1 ? '' : 's'
        ));

        Response::redirect('/documents');
    }
}
