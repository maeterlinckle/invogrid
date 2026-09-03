<?php

/**
 * The scan, as the reviewer actually reads it.
 *
 * **The page images come first and the PDF is a button underneath.** Every
 * document is rendered to one PNG per page before a model is ever shown it, so
 * the images are already on disk and are the very things the extraction was
 * worked out from — if the reading is wrong, this is what it was wrong about.
 * Serving one is an `<img>` the browser paints at once; the `<object>` it
 * replaces boots a whole PDF viewer, with its own toolbar and its own idea of
 * zoom, inside a box a third of the screen wide. On a queue of twenty
 * documents that difference is the screen feeling quick or feeling heavy.
 *
 * The PDF is one click away and opens *under* the images rather than instead of
 * them, for the times a render is not enough — checking a signature, or
 * flicking through fifteen pages of appendix faster than a thumbnail strip
 * allows.
 *
 * Everything works with JavaScript switched off: the pages are stacked in a
 * scrolling box in order, the thumbnail strip is ordinary in-page anchors, and
 * "View PDF" is a link to the PDF route. The controls that cannot work without
 * a script — the page arrows, the zoom toggle — ship `hidden` and `app.js`
 * reveals them, so nothing on the bar is a button that does nothing.
 *
 * @var int                            $documentId
 * @var array<int,array<string,mixed>> $pages   Rendered page images, in order
 * @var bool                           $hasPdf
 * @var string                         $missing What to say when there is neither
 */
$pages   = $pages ?? [];
$hasPdf  = $hasPdf ?? false;
$missing = $missing ?? 'Nothing has been stored for this document yet.';
$pdfUrl  = url('/documents/' . $documentId . '/pdf');
?>

<?php if ($pages === [] && !$hasPdf): ?>
    <p class="empty"><?= e($missing) ?></p>
<?php else: ?>
    <div class="scan" data-scan>
        <?php if ($pages !== []): ?>
            <div class="scan-stage" data-scan-stage>
                <?php foreach ($pages as $position => $page): ?>
                    <?php $number = (int) $page['page_number']; ?>
                    <img class="scan-page"
                         id="scan-page-<?= $number ?>"
                         data-scan-page="<?= $number ?>"
                         src="<?= e(url('/documents/' . $documentId . '/page/' . $number)) ?>"
                         alt="Page <?= $number ?> of this document"
                         width="<?= (int) $page['width'] ?>" height="<?= (int) $page['height'] ?>"
                         <?php /* The first page is the one being looked at; the rest
                                  can arrive as they are scrolled to. */ ?>
                         <?= $position === 0 ? '' : 'loading="lazy"' ?>>
                <?php endforeach; ?>
            </div>

            <div class="scan-bar">
                <div class="scan-nav">
                    <button type="button" class="btn btn-sm" data-scan-prev hidden>&larr;<span class="sr-only"> Previous page</span></button>
                    <span class="scan-count" data-scan-count aria-live="polite">
                        <?= count($pages) === 1 ? '1 page' : count($pages) . ' pages' ?>
                    </span>
                    <button type="button" class="btn btn-sm" data-scan-next hidden>&rarr;<span class="sr-only"> Next page</span></button>
                    <button type="button" class="btn btn-sm" data-scan-zoom aria-pressed="false" hidden>Actual size</button>
                </div>

                <?php if ($hasPdf): ?>
                    <?php /* A real link, so it works with no script and can be
                             opened in a tab deliberately. app.js turns a plain
                             click into the panel below instead. */ ?>
                    <a class="btn btn-sm" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener"
                       data-scan-pdf aria-expanded="false">View PDF</a>
                <?php endif; ?>
            </div>

            <?php if (count($pages) > 2): ?>
                <div class="scan-strip">
                    <?php foreach ($pages as $page): ?>
                        <?php $number = (int) $page['page_number']; ?>
                        <a href="#scan-page-<?= $number ?>" data-scan-goto="<?= $number ?>"
                           title="Page <?= $number ?>">
                            <img src="<?= e(url('/documents/' . $documentId . '/page/' . $number)) ?>"
                                 alt="Page <?= $number ?>" loading="lazy"
                                 width="<?= (int) $page['width'] ?>" height="<?= (int) $page['height'] ?>">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="field-hint">
                No page images have been rendered for this document, so the PDF itself is shown.
                Re-run it from <strong>Reading pages</strong> on
                <a href="<?= e(url('/documents/' . $documentId)) ?>">its pipeline record</a> to render them.
            </p>
        <?php endif; ?>

        <?php if ($hasPdf): ?>
            <div class="scan-pdf" data-scan-pdf-panel <?= $pages === [] ? '' : 'hidden' ?>>
                <object class="pdf-frame" data="<?= e($pdfUrl) ?>#view=FitH" type="application/pdf">
                    <p class="muted">
                        Your browser will not display the PDF here.
                        <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener">Open it in a new tab</a>.
                    </p>
                </object>
            </div>
        <?php elseif ($pages !== []): ?>
            <p class="field-hint text-danger">
                The stored PDF is missing from disk, so only the rendered pages are available.
                Retry the document from
                <a href="<?= e(url('/documents/' . $documentId)) ?>">its pipeline record</a> to fetch it again.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>
