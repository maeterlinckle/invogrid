<?php

use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\OcrResult;
use App\Models\PipelineJob;
use App\Models\Setting;
use App\Services\Pipeline;

/**
 * One document: where it is, what happened to it, and the controls for putting
 * it back on the rails.
 *
 * @var array<string,mixed>            $document
 * @var array<int,array<string,mixed>> $events
 * @var array<int,array<string,mixed>> $jobs
 * @var array<int,array<string,mixed>> $audit
 * @var array<int,array<string,mixed>> $pages
 * @var array<string,mixed>|null       $ocr
 * @var array<int,array<string,mixed>> $ocrRuns
 * @var array<string,mixed>|null       $extraction
 * @var int|null                       $pdfBytes
 */
$id      = (int) $document['id'];
$status  = (string) $document['status'];
$stage   = Pipeline::stageFor($status);
$waiting = $stage === null && !in_array($status, [Document::SUBMITTED, Document::IGNORED, Document::FAILED], true);

$eventTone = static fn (string $s): string => match ($s) {
    DocumentEvent::FAILED    => 'badge-danger',
    DocumentEvent::SUCCEEDED => 'badge-ok',
    DocumentEvent::SKIPPED   => 'badge-muted',
    default                  => 'badge-accent',
};

$paperlessBase = rtrim((string) (Setting::get('paperless_base_url') ?? ''), '/');
?>
<div class="page-head">
    <div>
        <h1>Paperless #<?= (int) $document['paperless_doc_id'] ?></h1>
        <p class="muted">
            <?= e($document['correspondent_raw'] ?? 'Supplier not yet known') ?>
            · received <?= e(format_datetime((string) $document['created_at'])) ?>
        </p>
    </div>
    <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('/documents')) ?>">Back to documents</a>
        <?php if ($extraction !== null): ?>
            <a class="btn" href="<?= e(url('/documents/' . $id . '/print')) ?>">Printable summary</a>
        <?php endif; ?>
        <?php if ($paperlessBase !== ''): ?>
            <a class="btn" href="<?= e($paperlessBase . '/documents/' . (int) $document['paperless_doc_id'] . '/') ?>"
               target="_blank" rel="noopener noreferrer">Open in Paperless</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === Document::FAILED): ?>
    <?php
    /*
     * Everything known about the failure, in one place.
     *
     * `error_message` is one sentence, which is right for a list and not enough
     * to act on. The failed event carries the rest — which provider, which
     * model, which of the four extraction calls, what the body actually said —
     * so this reads it rather than sending somebody to the server log, which is
     * the whole point of this panel.
     */
    $failedEvent = null;

    foreach ($events as $event) {
        if ((string) $event['status'] === DocumentEvent::FAILED) {
            $failedEvent = $event;
            break;
        }
    }

    $detail = $failedEvent === null ? [] : DocumentEvent::context($failedEvent);

    // Not $stage — that is the *current* stage, set at the top of this file and
    // read again by the retry control further down.
    $failedStage = (string) ($document['failed_stage'] ?? '');
    $resumes     = Document::retryStatusFor($failedStage === '' ? null : $failedStage);
    ?>
    <div class="card card-danger">
        <h2>This document stopped</h2>
        <p>
            It failed at <strong><?= e($failedStage === '' ? 'an unrecorded stage' : $failedStage) ?></strong>
            after <?= (int) $document['attempts'] ?> attempt<?= (int) $document['attempts'] === 1 ? '' : 's' ?><?php
            if ($failedEvent !== null): ?>, most recently
            <?= e(format_datetime((string) $failedEvent['created_at'])) ?><?php endif; ?>.
        </p>

        <?php if ($document['error_message'] !== null): ?>
            <p class="mono break"><?= e((string) $document['error_message']) ?></p>
        <?php endif; ?>

        <?php if ($detail !== []): ?>
            <h3>What the call looked like</h3>
            <ul class="meta-list">
                <?php foreach ($detail as $key => $value): ?>
                    <li>
                        <strong><?= e(ucfirst((string) $key)) ?></strong>
                        <span class="break mono"><?= e((string) $value) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>What happens if you retry</h3>
        <p>
            It resumes at <strong><?= e(Document::label($resumes)) ?></strong> — the head of the
            stage that broke, not the beginning. Work already done is kept: a document whose
            extraction failed is not downloaded and read again.
        </p>
        <p class="muted">Fix whatever it is complaining about, then retry below. Nothing is lost by retrying.</p>
    </div>
<?php elseif ($waiting): ?>
    <div class="card notice-card">
        <h2>Waiting here</h2>
        <p class="muted">
            This document is at <strong><?= e(Document::label($status)) ?></strong>, and the stage that
            picks it up from there has not been built yet. It will move on once it exists.
        </p>
    </div>
<?php endif; ?>

<div class="card-grid">
    <div class="card">
        <h2>Where it is</h2>
        <ul class="meta-list">
            <li><strong>Stage</strong> <?= e(Document::label($status)) ?></li>
            <li><strong>Document type</strong> <?= e($document['doc_type'] ?? 'not yet classified') ?></li>
            <li><strong>Attempts</strong> <?= (int) $document['attempts'] ?></li>
            <li><strong>Last change</strong> <?= e(format_datetime((string) $document['updated_at'])) ?></li>
        </ul>
    </div>

    <div class="card">
        <h2>The source PDF</h2>
        <?php if ($pdfBytes !== null): ?>
            <ul class="meta-list">
                <li><strong>Size</strong> <?= e(number_format($pdfBytes / 1024, 0)) ?> KB</li>
                <li><strong>Pages</strong> <?= $document['page_count'] === null ? 'not counted yet' : (int) $document['page_count'] ?></li>
            </ul>
            <p><a class="btn" href="<?= e(url('/documents/' . $id . '/pdf')) ?>" target="_blank" rel="noopener">View the PDF</a></p>
        <?php elseif ($document['pdf_path'] !== null): ?>
            <p class="text-danger">
                The database says the PDF is at <span class="mono"><?= e((string) $document['pdf_path']) ?></span>,
                but it is not on disk.
            </p>
            <p class="muted">A restore without the storage directory does this. Retry the document to fetch it again.</p>
        <?php else: ?>
            <p class="muted">Not fetched yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($pdfBytes !== null || $pages !== [] || $ocr !== null): ?>
    <h2 class="section-title">What it says</h2>

    <?php /* The scan on the left, what was read off it on the right. Side by
             side above 1100px and stacked below, because comparing the two is
             the whole job and a reviewer should not have to scroll between
             them. This is the shape the full review screen grows into. */ ?>
    <div class="doc-split">
        <div class="doc-pane">
            <h3>The scan</h3>

            <?php if ($pdfBytes !== null): ?>
                <?php /* <object> rather than <iframe>: the browser's own PDF
                         viewer, from our own same-origin route, and it degrades
                         to the link inside it where there is no viewer. */ ?>
                <object class="pdf-frame" data="<?= e(url('/documents/' . $id . '/pdf')) ?>" type="application/pdf">
                    <p class="muted">
                        Your browser will not display the PDF here.
                        <a href="<?= e(url('/documents/' . $id . '/pdf')) ?>" target="_blank" rel="noopener">Open it in a new tab</a>.
                    </p>
                </object>
            <?php else: ?>
                <p class="muted">No PDF stored yet.</p>
            <?php endif; ?>

            <?php if ($pages !== []): ?>
                <h3>Pages sent to the model</h3>
                <p class="muted">
                    <?= count($pages) ?> page<?= count($pages) === 1 ? '' : 's' ?>,
                    rendered at <?= (int) $pages[0]['width'] ?>&times;<?= (int) $pages[0]['height'] ?>.
                    These are the images the model actually saw — if it misread something,
                    look here first.
                </p>

                <div class="page-strip">
                    <?php foreach ($pages as $page): ?>
                        <figure class="page-thumb">
                            <a href="<?= e(url('/documents/' . $id . '/page/' . $page['page_number'])) ?>"
                               target="_blank" rel="noopener">
                                <img src="<?= e(url('/documents/' . $id . '/page/' . $page['page_number'])) ?>"
                                     alt="Page <?= (int) $page['page_number'] ?> of this document"
                                     loading="lazy" width="<?= (int) $page['width'] ?>" height="<?= (int) $page['height'] ?>">
                            </a>
                            <figcaption>Page <?= (int) $page['page_number'] ?></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="doc-pane">
            <h3>The transcription</h3>

            <?php if ($ocr === null): ?>
                <p class="muted">Not read yet.</p>
            <?php else: ?>
                <?php
                // Both were parsed and stored when the response arrived. A
                // template has no business decoding JSON, and doing it here was
                // the n8n habit of treating the text as the carrier rather than
                // the database.
                $structured = OcrResult::structured($ocr);
                $text       = OcrResult::text($ocr);
                ?>

                <ul class="meta-list">
                    <li><strong>Provider</strong> <?= e((string) $ocr['llm_provider']) ?> · <?= e((string) $ocr['llm_model']) ?></li>
                    <li><strong>Read</strong> <?= e(format_datetime((string) $ocr['created_at'])) ?><?= $ocr['duration_ms'] === null ? '' : ' in ' . round((int) $ocr['duration_ms'] / 1000, 1) . 's' ?></li>
                    <?php if ($ocr['prompt_tokens'] !== null): ?>
                        <li><strong>Tokens</strong> <?= number_format((int) $ocr['prompt_tokens']) ?> in, <?= number_format((int) $ocr['completion_tokens']) ?> out</li>
                    <?php endif; ?>
                </ul>

                <?php if ($structured !== null): ?>
                    <div class="annotation-summary">
                        <?php
                        // The field names the production prompt emits. Note the
                        // lower-case b in clearbooksNumber — it does not match
                        // how the rest of the application spells Clear Books,
                        // and reading the wrong key makes both fields look
                        // absent on every document.
                        $clearBooks = $structured['clearbooksNumber'] ?? null;
                        $project    = $structured['project'] ?? null;
                        ?>
                        <p>
                            <strong>Clearbooks Number</strong>
                            <?php if (is_scalar($clearBooks) && (string) $clearBooks !== ''): ?>
                                <span class="badge badge-accent mono"><?= e((string) $clearBooks) ?></span>
                            <?php else: ?>
                                <span class="muted">none found</span>
                                <span class="cell-sub">— frequently absent, and never guessed at</span>
                            <?php endif; ?>
                        </p>
                        <p>
                            <strong>Project</strong>
                            <?php if (is_string($project) && $project !== ''): ?>
                                <span class="badge badge-accent mono"><?= e($project) ?></span>
                            <?php else: ?>
                                <span class="muted">none found</span>
                            <?php endif; ?>
                        </p>

                        <?php $annotations = is_array($structured['handwrittenAnnotations'] ?? null) ? $structured['handwrittenAnnotations'] : []; ?>
                        <?php if ($annotations !== []): ?>
                            <p><strong>Handwritten on the page</strong></p>
                            <ul class="plain-list">
                                <?php foreach ($annotations as $annotation): ?>
                                    <?php if (!is_array($annotation)) {
                                        continue;
                                    } ?>
                                    <?php
                                    // inkColor and marksPrintedText are both
                                    // explicitly nullable in the prompt, so each
                                    // is only shown when it is actually there.
                                    $detail = array_filter([
                                        isset($annotation['inkColor']) ? (string) $annotation['inkColor'] . ' ink' : null,
                                        isset($annotation['location']) ? (string) $annotation['location'] : null,
                                        isset($annotation['marksPrintedText'])
                                            ? 'marks: ' . (string) $annotation['marksPrintedText']
                                            : null,
                                    ], static fn (?string $part): bool => $part !== null && trim($part) !== '');
                                    ?>
                                    <li>
                                        <span class="mono"><?= e((string) ($annotation['text'] ?? '')) ?></span>
                                        <?php if ($detail !== []): ?>
                                            <span class="cell-sub">— <?= e(implode(', ', $detail)) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php elseif (($structured['notesPresent'] ?? null) === false): ?>
                            <p class="muted">Nothing handwritten found on the page.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php /* Pre-formatted and scrollable: the transcription keeps the
                         document's own line breaks and column spacing, which is
                         what makes a line-item table still readable as one. */ ?>
                <pre class="ocr-text"><?= e($text) ?></pre>

                <?php if ($structured === null && trim($text) !== ''): ?>
                    <p class="field-hint">
                        This came back as plain text rather than the JSON the prompt asked for.
                        The transcription is usable; the annotation fields were not read.
                    </p>
                <?php endif; ?>

                <?php if (count($ocrRuns) > 1): ?>
                    <p class="field-hint">
                        <?= count($ocrRuns) ?> transcriptions of this document. The newest is shown;
                        earlier ones are kept so two models can be compared.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($extraction !== null): ?>
    <h2 class="section-title">What was extracted</h2>
    <?= partial('partials/extraction', ['extraction' => $extraction, 'matches' => $matches]) ?>
<?php endif; ?>

<?php if ($matches !== []): ?>
    <?php /* Separate from the extracted fields on purpose. Those are what a model
             said about the page; these are what it resolves to in Clear Books,
             which is the thing that has to be true before anything is submitted. */ ?>
    <h2 class="section-title">What it matched to</h2>
    <?= partial('partials/matches', ['matches' => $matches]) ?>
<?php endif; ?>

<?php if ($submissions !== []): ?>
    <h2 class="section-title">Clear Books</h2>

    <?php if ($submitted !== null): ?>
        <?php /* The prominent one, because it is the only way to set a project
                 code: Clear Books has no API for it, so a person has to open the
                 record and do it by hand. `data-clearbooks-window` reuses one
                 named window across every click rather than opening a tab per
                 document. */ ?>
        <div class="card card-ok">
            <h3>In Clear Books</h3>
            <p>
                <strong><?= e((string) $submitted['clearbooks_type']) ?>
                    <?= e((string) $submitted['clearbooks_id']) ?></strong>,
                submitted <?= e(format_datetime((string) $submitted['submitted_at'])) ?>
                <?= $submitted['display_name'] === null ? '' : 'by ' . e((string) $submitted['display_name']) ?>.
            </p>

            <?php if ($submitted['clearbooks_url'] !== null): ?>
                <p>
                    <a class="btn btn-primary btn-lg" href="<?= e((string) $submitted['clearbooks_url']) ?>"
                       data-clearbooks-window>Open in Clear Books</a>
                </p>
                <p class="field-hint">
                    Clear Books has no API for a purchase line's project code, so it is set here by
                    hand. The link reuses one window, so repeated clicks replace what is in it rather
                    than filling the browser with tabs.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (count($submissions) > 1 || $submitted === null): ?>
        <div class="table-wrap">
            <table class="table table-compact">
                <caption class="sr-only">Every submission attempt for this document</caption>
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">As</th>
                        <th scope="col">Outcome</th>
                        <th scope="col">By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $attempt): ?>
                        <tr class="<?= $attempt['status'] === 'success' ? '' : 'row-muted' ?>">
                            <td class="nowrap"><?= e(format_datetime((string) $attempt['submitted_at'])) ?></td>
                            <td>
                                <?= e((string) $attempt['clearbooks_type']) ?>
                                <?php if ($attempt['clearbooks_id'] !== null): ?>
                                    <span class="mono"><?= e((string) $attempt['clearbooks_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($attempt['status'] === 'success'): ?>
                                    <span class="badge badge-ok">created</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">rejected</span>
                                    <?php
                                    $response = \App\Models\Submission::response($attempt);
                                    ?>
                                    <?php if (isset($response['error'])): ?>
                                        <span class="muted break"><?= e((string) $response['error']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($attempt['display_name'] ?? $attempt['username'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php /* `role:admin`, matching the route exactly. It used to test
             `users.manage`, which is admin-only and so gave the same answer —
             but a capability standing in for a role check is a hidden button
             that stops matching the server the day the two diverge. */ ?>
    <?php if ($submitted !== null && role_at_least('admin')): ?>
        <?php /* Admin only, off the ordinary path, and honest about what it
                 does. The first record is not withdrawn — InvoGrid has no
                 business deleting from somebody's ledger. */ ?>
        <details class="card">
            <summary>Submit this again</summary>
            <p>
                This document is already in Clear Books. Submitting it again creates a
                <strong>second</strong> record; the first is left exactly where it is, and has to be
                removed in Clear Books if it is not wanted. The usual reason to do this is that
                somebody deleted the first one there.
            </p>
            <form method="post" action="<?= e(url('/documents/' . $id . '/resubmit')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="resubmit-reason">Why</label>
                    <input class="input" id="resubmit-reason" name="reason" maxlength="500"
                           placeholder="e.g. the first record was deleted in Clear Books">
                    <p class="field-hint">Kept in the activity log beside both submissions.</p>
                </div>
                <button type="submit" class="btn btn-danger"
                        data-confirm="Create a SECOND record in Clear Books? The first one stays where it is.">
                    Resubmit
                </button>
            </form>
        </details>
    <?php endif; ?>
<?php endif; ?>

<?php if (can('documents.retry')): ?>
    <h2 class="section-title">Put it back</h2>

    <div class="card">
        <form method="post" action="<?= e(url('/documents/' . $id . '/retry')) ?>" class="form-actions">
            <?= csrf_field() ?>

            <div class="field field-inline">
                <label class="label" for="to">Reset to</label>
                <select class="input" id="to" name="to">
                    <?php
                    $default = Document::retryStatusFor(
                        $document['failed_stage'] === null ? null : (string) $document['failed_stage']
                    );
                    ?>
                    <?php foreach (Document::STATUSES as $candidate): ?>
                        <?php if (!Document::canTransition($status, $candidate)) {
                            continue;
                        } ?>
                        <option value="<?= e($candidate) ?>" <?= $candidate === $default ? 'selected' : '' ?>>
                            <?= e(Document::label($candidate)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Only the stages this document can legitimately move to are listed.</p>
            </div>

            <button type="submit" class="btn btn-primary">Retry</button>
        </form>

        <?php if ($status !== Document::IGNORED && Document::canTransition($status, Document::IGNORED)): ?>
            <hr class="nav-divider">
            <form method="post" action="<?= e(url('/documents/' . $id . '/ignore')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-warning"
                        data-confirm="Ignore this document? Nothing further will happen to it.">
                    Not one for InvoGrid
                </button>
                <p class="field-hint">For a delivery note or a duplicate that came in on the same scan run. Reversible.</p>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<h2 class="section-title">What the pipeline did</h2>

<div class="table-wrap">
    <table class="table table-compact">
        <caption class="sr-only">Pipeline events for this document</caption>
        <thead>
            <tr>
                <th scope="col">When</th>
                <th scope="col">Stage</th>
                <th scope="col">Outcome</th>
                <th scope="col">Detail</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($events === []): ?>
                <tr><td class="empty" colspan="4">Nothing yet.</td></tr>
            <?php else: ?>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_datetime((string) $event['created_at'])) ?></td>
                        <td><?= e((string) $event['stage']) ?></td>
                        <td>
                            <span class="badge <?= e($eventTone((string) $event['status'])) ?>"><?= e((string) $event['status']) ?></span>
                            <?php if ($event['duration_ms'] !== null): ?>
                                <span class="cell-sub"><?= (int) $event['duration_ms'] ?> ms</span>
                            <?php endif; ?>
                        </td>
                        <td class="break">
                            <?= e($event['message'] ?? '') ?>

                            <?php /* Every failure, not only the most recent one. The panel at
                                     the top explains the current stop; this is the history, and
                                     "it has failed the same way four times" and "it failed four
                                     different ways" want completely different responses. */ ?>
                            <?php $eventDetail = DocumentEvent::context($event); ?>
                            <?php if ($eventDetail !== []): ?>
                                <details class="event-detail">
                                    <summary class="cell-sub">What the call looked like</summary>
                                    <ul class="meta-list">
                                        <?php foreach ($eventDetail as $key => $value): ?>
                                            <li>
                                                <strong><?= e(ucfirst((string) $key)) ?></strong>
                                                <span class="break mono"><?= e((string) $value) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($jobs !== []): ?>
    <h2 class="section-title">Queue</h2>

    <div class="table-wrap">
        <table class="table table-compact">
            <caption class="sr-only">Queued and finished jobs for this document</caption>
            <thead>
                <tr>
                    <th scope="col">Stage</th>
                    <th scope="col">State</th>
                    <th scope="col">Attempts</th>
                    <th scope="col">Due</th>
                    <th scope="col">Last error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr<?= $job['status'] === PipelineJob::DONE ? ' class="row-muted"' : '' ?>>
                        <td><?= e((string) $job['stage']) ?></td>
                        <td>
                            <span class="badge <?= $job['status'] === PipelineJob::FAILED ? 'badge-danger' : 'badge-muted' ?>">
                                <?= e((string) $job['status']) ?>
                            </span>
                        </td>
                        <td><?= (int) $job['attempts'] ?></td>
                        <td class="nowrap"><?= e(format_datetime((string) $job['available_at'])) ?></td>
                        <td class="break"><?= e(str_limit($job['last_error'] === null ? '' : (string) $job['last_error'], 120)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($audit !== []): ?>
    <h2 class="section-title">What people did</h2>

    <div class="table-wrap">
        <table class="table table-compact">
            <caption class="sr-only">Human actions on this document</caption>
            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Who</th>
                    <th scope="col">Action</th>
                    <th scope="col">Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($audit as $entry): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_datetime((string) $entry['created_at'])) ?></td>
                        <td><?= e($entry['display_name'] ?: $entry['username'] ?: 'system') ?></td>
                        <td><?= e((string) $entry['action']) ?></td>
                        <td class="break"><?= e($entry['details'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
