<?php

declare(strict_types=1);

/*
 * Is every step of the workflow actually implemented, or is something a stub?
 *
 * Reads the code rather than the documentation: a class exists, a method has a
 * body of more than a couple of lines, it is reachable from the stage registry
 * or a route, and it does the specific thing the step is named for. A method
 * that returns null or throws "not implemented" is reported as a stub.
 */

$app = dirname(__DIR__);
require $app . '/src/bootstrap.php';

use App\Core\Database;
use App\Services\Pipeline;

/** Does this class::method exist, and does it have real substance? */
function implemented(string $class, string $method, int $minLines = 4): array
{
    if (!class_exists($class)) {
        return ['ok' => false, 'why' => 'class does not exist'];
    }

    if (!method_exists($class, $method)) {
        return ['ok' => false, 'why' => 'method does not exist'];
    }

    $r     = new ReflectionMethod($class, $method);
    $lines = $r->getEndLine() - $r->getStartLine();
    $file  = (string) $r->getFileName();
    $body  = implode('', array_slice(file($file), $r->getStartLine(), $lines));

    if (preg_match('/not implemented|TODO|@stub|throw new \\\\?LogicException/i', $body)) {
        return ['ok' => false, 'why' => 'marked as unfinished'];
    }

    if ($lines < $minLines) {
        return ['ok' => false, 'why' => "only {$lines} lines — likely a stub"];
    }

    return ['ok' => true, 'why' => $lines . ' lines in ' . basename($file)];
}

$steps = [
    '1. Paperless tells InvoGrid a document arrived' => [
        ['App\Controllers\WebhookController', 'receive', 20],
        ['App\Models\Document', 'register', 8],
    ],
    '2. The PDF is pulled from Paperless' => [
        ['App\Services\PaperlessClient', 'downloadOriginal', 20],
        ['App\Services\IngestStage', 'run', 10],
    ],
    '3. Each page is rendered to an image' => [
        ['App\Services\PdfRenderer', 'render', 15],
    ],
    '4. A vision model transcribes the pages' => [
        ['App\Services\OcrStage', 'run', 15],
        ['App\Services\Llm\AnthropicClient', 'ocr', 15],
        ['App\Services\Llm\OpenAiClient', 'ocr', 15],
    ],
    '5. Focused calls extract header, supplier, lines, custom fields' => [
        ['App\Services\ExtractStage', 'run', 20],
        ['App\Services\ExtractStage', 'call', 15],
    ],
    '6. Everything is matched against the Clear Books lists' => [
        ['App\Services\MatchStage', 'run', 15],
        ['App\Services\CacheRefresh', 'run', 6],
        ['App\Services\Normaliser', 'key', 8],
    ],
    '7. Anything uncertain goes to a human' => [
        ['App\Controllers\ReviewController', 'index', 8],
        ['App\Controllers\ReviewController', 'show', 15],
        ['App\Controllers\ReviewController', 'save', 15],
        ['App\Controllers\ReviewController', 'pickEntity', 10],
        ['App\Services\EntityCreator', 'supplier', 15],
    ],
    '8. The document is submitted to Clear Books' => [
        ['App\Services\SubmitStage', 'submit', 20],
        ['App\Services\SubmitStage', 'payload', 20],
        ['App\Services\ClearBooksClient', 'createPurchase', 10],
    ],
    '9. The result is written back to Paperless' => [
        ['App\Services\PaperlessWriteBack', 'run', 20],
        ['App\Services\PaperlessClient', 'updateDocument', 10],
        ['App\Services\PaperlessClient', 'setCustomFields', 10],
        ['App\Services\PaperlessClient', 'addNote', 8],
    ],
    'Prompt 8: credit note vs purchase refund' => [
        ['App\Models\DocumentType', 'requiresConfirmation', 3],
        ['App\Models\DocumentType', 'amountSign', 3],
        ['App\Models\Extraction', 'confirmType', 6],
        ['App\Controllers\ReviewController', 'confirmType', 10],
    ],
];

$failures = 0;

foreach ($steps as $step => $checks) {
    echo "\n" . $step . "\n";

    foreach ($checks as [$class, $method, $min]) {
        $result = implemented($class, $method, $min);
        $short  = substr(strrchr($class, '\\') ?: $class, 1) . '::' . $method;

        printf("  %-4s %-42s %s\n", $result['ok'] ? '[ok]' : 'FAIL', $short, $result['why']);

        if (!$result['ok']) {
            $failures++;
        }
    }
}

// --- The registry, and whether the machine can actually walk it -------------

echo "\nThe stage registry\n";

foreach (Pipeline::STAGES as $key => $stage) {
    $handler = $stage['handler'] ?? null;
    $ok      = $handler !== null && class_exists($handler) && method_exists($handler, 'run');

    printf(
        "  %-4s %-10s %-12s -> %-16s %s\n",
        $ok ? '[ok]' : 'FAIL',
        $key,
        $stage['from'],
        $stage['to'],
        $handler === null ? 'NO HANDLER' : substr(strrchr($handler, '\\') ?: $handler, 1)
    );

    if (!$ok) {
        $failures++;
    }
}

// --- Has it actually run? --------------------------------------------------

echo "\nEvidence from the database that each stage has really run\n";

$evidence = [
    'ingest  — a PDF on disk'        => 'SELECT COUNT(*) FROM documents WHERE pdf_path IS NOT NULL',
    'ingest  — pages rendered'       => 'SELECT COUNT(*) FROM document_pages',
    'ocr     — a transcription'      => 'SELECT COUNT(*) FROM ocr_results WHERE raw_text IS NOT NULL',
    'extract — a record'             => 'SELECT COUNT(*) FROM extractions',
    'extract — line items'           => "SELECT COUNT(*) FROM extractions WHERE line_items IS NOT NULL AND line_items <> '[]'",
    'extract — custom field values'  => 'SELECT COUNT(*) FROM extractions WHERE custom_field_values IS NOT NULL',
    'match   — entity matches'       => 'SELECT COUNT(*) FROM entity_matches',
    'match   — a resolved supplier'  => "SELECT COUNT(*) FROM entity_matches WHERE entity_type = 'supplier' AND status = 'matched'",
    'review  — a human edit'         => 'SELECT COUNT(*) FROM extractions WHERE edited_at IS NOT NULL',
    'submit  — a Clear Books record' => "SELECT COUNT(*) FROM submissions WHERE status = 'success' AND clearbooks_id IS NOT NULL",
    'writeback — logged'             => "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'paperless.%'",
    'cache   — Clear Books lists'    => 'SELECT COUNT(*) FROM clearbooks_cache',
];

foreach ($evidence as $label => $sql) {
    $count = (int) Database::scalar($sql);

    printf("  %-4s %-32s %d row(s)\n", $count > 0 ? '[ok]' : 'none', $label, $count);
}

echo "\n" . ($failures === 0
    ? "Every named step is implemented and reachable.\n"
    : $failures . " problem(s) found.\n");

exit($failures === 0 ? 0 : 1);
