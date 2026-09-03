<?php

use App\Core\Upload;

/**
 * The upload page — the manual ingest route.
 *
 * One form, one field. The interesting decisions are about what it tells you
 * *before* you press the button rather than after: the real size limit, that
 * PDFs are the only thing accepted, and what happens next. An upload page whose
 * rules are only discovered by breaking them wastes somebody's afternoon
 * scanning at the wrong resolution.
 *
 * @var int $maxBytes The effective limit — the smallest of the application's
 *                    setting and the two PHP directives that outrank it.
 * @var int $maxFiles
 */
?>

<div class="page-head">
    <div>
        <h1>Upload documents</h1>
        <p class="muted">
            Hand InvoGrid a purchase invoice, bill or credit note and it will read it,
            pull out the detail and match it against Clear Books.
        </p>
    </div>

    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e(url('/documents')) ?>">All documents</a>
    </div>
</div>

<div class="card">
    <form method="post"
          action="<?= e(url('/documents/upload')) ?>"
          enctype="multipart/form-data"
          data-upload-form>
        <?= csrf_field() ?>

        <div class="field">
            <label class="label" for="documents">PDF files</label>

            <?php /* `multiple`, because invoices arrive in handfuls. Each file is
                     accepted or refused on its own — one bad file does not
                     discard the rest of the batch. */ ?>
            <input class="input"
                   type="file"
                   id="documents"
                   name="documents[]"
                   accept="application/pdf,.pdf"
                   multiple
                   required
                   data-upload-input
                   data-max-bytes="<?= (int) $maxBytes ?>"
                   data-max-files="<?= (int) $maxFiles ?>">

            <p class="field-hint">
                PDF only, up to <?= e(Upload::formatBytes($maxBytes)) ?> each and
                <?= (int) $maxFiles ?> at a time.
                <?php /* The number quoted is the effective limit, not the setting: PHP's
                         upload_max_filesize and post_max_size each outrank it, and a form
                         promising more than the server will take produces the worst kind
                         of bug report — "it just goes back to the list". */ ?>
            </p>

            <?php /* Filled in by app.js once files are chosen. Empty and hidden until
                     then, so a page with JavaScript switched off shows nothing odd —
                     the form still works, it just says less before you submit. */ ?>
            <ul class="meta-list" data-upload-list hidden></ul>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-upload-submit>Upload</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>What happens next</h2>

    <ol class="steps">
        <li>
            <strong>Stored.</strong> The PDF is written outside the webroot and the document
            appears in the list straight away, marked <em>Received</em>.
        </li>
        <li>
            <strong>Read.</strong> The queue renders each page and sends it to the vision
            model. This is the slow part — a few seconds a page.
        </li>
        <li>
            <strong>Extracted and matched.</strong> Dates, totals, reference and line items
            come off the page, and the issuer is matched against your Clear Books suppliers.
        </li>
        <li>
            <strong>Yours to check.</strong> Anything the models were unsure of lands in the
            <a href="<?= e(url('/review')) ?>">review queue</a> with the reason attached.
            Nothing reaches Clear Books until somebody submits it.
        </li>
    </ol>

    <p class="muted">
        The queue is worked by a scheduled task rather than by this page, so you can close
        the tab. If a document sits at <em>Received</em> and does not move, that scheduled
        task is not running.
    </p>
</div>
