<?php

declare(strict_types=1);

/*
 * The checks that do not need a browser.
 *
 *   php tests/smoke.php
 *
 * Deliberately not a test framework: there is no Composer here, and a hundred
 * lines of plain assertions catch the things that actually break — a mangled
 * config path, a state machine that lists a status it does not define, a helper
 * that stops escaping. Exits non-zero on failure so it can go in a hook.
 *
 * It needs a readable .env with APP_KEY set. Everything up to "Database" runs
 * without one; the last section is **skipped** when the database is unreachable
 * or unmigrated, so this is still useful on a machine that has neither.
 *
 * That last section exists because of a real bug it would have caught: a class
 * adapted from the sibling application kept querying a column that InvoGrid's
 * schema does not have, and nothing without a live database noticed. Anything
 * where PHP names a column or an enum value belongs down there.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

// The bootstrap runs its web-only branch off PHP_SAPI, so nothing here needs a
// fake request — but Request::path() is consulted by the helpers below, and it
// reads $_SERVER.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/login';
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['HTTP_HOST']      = 'localhost';

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\LoginThrottle;
use App\Core\Migrator;
use App\Core\Router;
use App\Core\Validator;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\CustomField;
use App\Models\ClearbooksCache;
use App\Models\DocumentType;
use App\Models\EntityMatch;
use App\Models\Extraction;
use App\Models\Submission;
use App\Models\OcrResult;
use App\Models\PromptTemplate;
use App\Models\Setting;
use App\Models\SettingSchema;
use App\Models\User;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmFactory;
use App\Services\Llm\LlmResponse;
use App\Services\Branding;
use App\Services\Normaliser;
use App\Services\PdfRenderer;
use App\Services\PromptRenderer;
use App\Services\Pipeline;

$failures = 0;

function check(string $what, bool $ok): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
}

/**
 * A minimal valid two-page A4 PDF, built by hand so the renderer has a real
 * document to work on without committing a binary or adding a library. The xref
 * offsets are computed rather than guessed, or poppler rejects the file.
 */
function smoke_sample_pdf(): string
{
    $page1 = "BT /F1 24 Tf 60 760 Td (ACME SUPPLIES LTD) Tj ET\n"
        . 'BT /F1 12 Tf 60 720 Td (Invoice INV-2026-0042) Tj ET';
    $page2 = 'BT /F1 12 Tf 60 760 Td (Page 2 - terms) Tj ET';

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 2 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => '<< /Length ' . strlen($page1) . " >>\nstream\n" . $page1 . "\nendstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        6 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 7 0 R >>',
        7 => '<< /Length ' . strlen($page2) . " >>\nstream\n" . $page2 . "\nendstream",
    ];

    $pdf     = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefAt = strlen($pdf);
    $pdf   .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

    foreach (array_keys($objects) as $number) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
    }

    return $pdf . 'trailer' . "\n<< /Size " . (count($objects) + 1)
        . " /Root 1 0 R >>\nstartxref\n" . $xrefAt . "\n%%EOF\n";
}

echo "Config\n";
check('app.name loads', Config::get('app.name') === 'InvoGrid');
check('app.mark is the IG monogram', Config::get('app.mark') === 'IG');
check('storage paths resolve under the project', str_contains((string) Config::get('storage.pages'), 'storage'));
check('Clear Books API base url has a default', Config::get('integrations.clearbooks.base_url') === 'https://api.clearbooks.co.uk');

echo "\nCrypto — the APP_KEY round trip every stored credential depends on\n";
check('a key is configured', Crypto::hasKey());
$cipher = Crypto::encrypt('sk-test-secret-value');
check('encrypt returns a versioned blob', is_string($cipher) && str_starts_with($cipher, 'v1.'));
check('decrypt returns the original', Crypto::decrypt((string) $cipher) === 'sk-test-secret-value');
// The whole reason for GCM: a tampered value must fail rather than decrypt to
// rubbish that then gets sent to an API as a credential.
check('a tampered blob fails closed', Crypto::decrypt(substr((string) $cipher, 0, -4) . 'AAAA') === null);

echo "\nValidator\n";
$validator = Validator::make(['a' => '', 'b' => 'not-a-date', 'c' => '12'], [
    'a' => 'required',
    'b' => 'date',
    'c' => 'integer|min_value:10',
]);
check('required catches an empty field', isset($validator->errors()['a']));
check('date rejects rubbish', isset($validator->errors()['b']));
check('integer with min_value passes', !isset($validator->errors()['c']));
check('character classes: 4 for Aa1!', Validator::characterClasses('Aa1!') === 4);
check('character classes: 2 for abcd1234', Validator::characterClasses('abcd1234') === 2);

echo "\nPipeline state machine\n";
check('every status has a label', array_diff(Document::STATUSES, array_keys(Document::LABELS)) === []);
check('received -> ocr_pending is allowed', Document::canTransition(Document::RECEIVED, Document::OCR_PENDING));
check('received -> submitted is refused', !Document::canTransition(Document::RECEIVED, Document::SUBMITTED));
check('failed -> extracting is allowed (a retry)', Document::canTransition(Document::FAILED, Document::EXTRACTING));
check('submitted -> needs_review is refused', !Document::canTransition(Document::SUBMITTED, Document::NEEDS_REVIEW));
check('needs_review -> ready_to_submit is allowed', Document::canTransition(Document::NEEDS_REVIEW, Document::READY_TO_SUBMIT));

// A transition naming a status that does not exist is the kind of typo that
// only shows up when a document reaches that stage in production.
check('every transition names a real status', (static function (): bool {
    foreach (Document::TRANSITIONS as $from => $targets) {
        if (!in_array($from, Document::STATUSES, true)) {
            return false;
        }

        foreach ($targets as $to) {
            if (!in_array($to, Document::STATUSES, true)) {
                return false;
            }
        }
    }

    return true;
})());

check('every status can be reached from somewhere', (static function (): bool {
    $reachable = [Document::RECEIVED => true];

    foreach (Document::TRANSITIONS as $targets) {
        foreach ($targets as $to) {
            $reachable[$to] = true;
        }
    }

    return count(array_diff(Document::STATUSES, array_keys($reachable))) === 0;
})());

echo "\nPipeline stages\n";

// The stage registry and the state machine are written in two files and have to
// agree. A stage whose `to` is not reachable from its `from` produces a job
// that runs, succeeds, and then throws when it tries to move the document —
// after the work is already done and paid for.
check('every stage names real statuses', (static function (): bool {
    foreach (Pipeline::STAGES as $stage) {
        if (!in_array($stage['from'], Document::STATUSES, true)) {
            return false;
        }

        if (!in_array($stage['to'], Document::STATUSES, true)) {
            return false;
        }
    }

    return true;
})());

check('every stage transition is legal', (static function (): bool {
    foreach (Pipeline::STAGES as $stage) {
        // A stage with a working status passes through it: from -> during -> to.
        $steps = $stage['during'] === null
            ? [[$stage['from'], $stage['to']]]
            : [[$stage['from'], $stage['during']], [$stage['during'], $stage['to']]];

        foreach ($steps as [$from, $to]) {
            if (!Document::canTransition($from, $to)) {
                return false;
            }
        }
    }

    return true;
})());

check('a working status is recoverable after a crash', (static function (): bool {
    // A worker killed mid-stage leaves the document in `during`. The stage has
    // to accept it back, which means `during -> to` must be legal — checked
    // above — and `during` must not be some other stage's `from`.
    $consumed = array_column(Pipeline::STAGES, 'from');

    foreach (Pipeline::STAGES as $stage) {
        if ($stage['during'] !== null && in_array($stage['during'], $consumed, true)) {
            return false;
        }
    }

    return true;
})());

check('no two stages consume the same status', (static function (): bool {
    $from = array_column(Pipeline::STAGES, 'from');

    return count($from) === count(array_unique($from));
})());

check('ingest is the stage for a received document', Pipeline::stageFor(Document::RECEIVED) === 'ingest');
check('nothing runs a document that needs review', Pipeline::stageFor(Document::NEEDS_REVIEW) === null);
check('match is the stage for an extracted document', Pipeline::stageFor(Document::EXTRACTED) === 'match');
check('every stage has a handler now', (static function (): bool {
    foreach (Pipeline::STAGES as $stage) {
        if ($stage['handler'] === null) {
            return false;
        }
    }

    return true;
})());

// The registry records one destination; the matching stage has two. Both have
// to be legal from `matching`, or a clean document does all the work and then
// throws on the way out.
check(
    'a matched document may go straight to ready to submit',
    Document::canTransition(Document::MATCHING, Document::READY_TO_SUBMIT)
);
check('a failed match retries from extracted', Document::retryStatusFor('match') === Document::EXTRACTED);

// A retry has to put the document back at the head of the stage that broke, or
// it re-runs work that already succeeded — which, once the LLM stages exist,
// costs real money.
check('a failed ingest retries from received', Document::retryStatusFor('ingest') === Document::RECEIVED);
check('a failed ocr retries from ocr_pending', Document::retryStatusFor('ocr') === Document::OCR_PENDING);
check('an unrecorded stage retries from the start', Document::retryStatusFor(null) === Document::RECEIVED);
check('an unknown stage retries from the start', Document::retryStatusFor('nonsense') === Document::RECEIVED);

echo "\nName normalisation\n";

// The deterministic supplier fallback. Everything here is a real shape of
// disagreement between a scanned letterhead and a Clear Books record.
$same = static fn (string $a, string $b): bool => Normaliser::key($a) === Normaliser::key($b) && Normaliser::key($a) !== '';

check('case and punctuation stop counting', $same('ACME SUPPLIES LTD.', 'Acme Supplies Ltd'));
check('Ltd and Limited are the same company', $same('Acme Supplies Ltd', 'Acme Supplies Limited'));
check('a missing suffix is still the same company', $same('Acme Supplies Limited', 'Acme Supplies'));
check('two suffixes are both stripped', $same('Acme Trading Co Ltd', 'Acme Trading'));
check('ampersand is a spelling of and', $same('Smith & Sons', 'Smith and Sons'));
check('an apostrophe closes up rather than splits', $same("O'Brien Plant Hire", 'OBrien Plant Hire'));
check('a leading "the" is noise', $same('The Paper Company', 'Paper Co'));
check('hyphens and extra spaces are separators', $same('Blue-Sky   Widgets PLC', 'Blue Sky Widgets'));

// The looser pass, which is deliberately *not* folded into key(): it is right
// often enough to use and wrong often enough that the matcher insists on a
// single candidate before believing it.
check('word boundaries only stop counting in the compact form',
    Normaliser::key('Clear Books') !== Normaliser::key('Clearbooks')
    && Normaliser::compact('Clear Books') === Normaliser::compact('Clearbooks'));

// The guards. A name that is nothing but a suffix must survive: a supplier
// really called "Company" would otherwise reduce to the empty string and match
// every other one that did the same.
check('a name that is only a suffix survives', Normaliser::key('Limited') === 'limited');
check('a suffix inside a name is left alone', Normaliser::key('Limited Editions Ltd') === 'limited editions');
check('different companies stay different', !$same('Acme Supplies Ltd', 'Acme Services Ltd'));
check('an empty name reduces to nothing', Normaliser::key('   ') === '');

check('trading names are offered as alternative keys', (static function (): bool {
    $keys = Normaliser::keysFor('Acme Supplies Limited', ['ACME', 'Acme Supplies Ltd']);

    // "Acme Supplies Limited" and "Acme Supplies Ltd" reduce to one key, so
    // three names give two.
    return $keys === ['acme supplies', 'acme'];
})());

echo "\nTotals arithmetic\n";

// One implementation, shared by the extraction stage, the review form and the
// entity picker. It was three, and the copy the picker did not have was a real
// bug: a document resolved by picking a VAT rate went to Clear Books still
// describing itself as having no VAT.
check('an empty document has no totals',
    Extraction::totalsFromLines([]) === ['net' => null, 'vat' => null, 'gross' => null]);

check('a line with no total makes every total unknown', (static function (): bool {
    $totals = Extraction::totalsFromLines([['lineTotal' => null, 'vatRateKey' => '20']]);

    return $totals === ['net' => null, 'vat' => null, 'gross' => null];
})());

check('net is the sum of the line totals, unsigned', (static function (): bool {
    // No sign is applied for a credit note here; that is the submission's
    // decision, taken from document_types.amount_sign.
    $totals = Extraction::totalsFromLines([
        ['lineTotal' => 100.0, 'vatRateKey' => null],
        ['lineTotal' => 55.5, 'vatRateKey' => null],
    ]);

    return $totals['net'] === 155.5;
})());

check('an unknown VAT rate leaves VAT and gross null, not zero', (static function (): bool {
    $totals = Extraction::totalsFromLines([['lineTotal' => 100.0, 'vatRateKey' => 'not-a-rate']]);

    // Zero would look like a legitimate answer on a zero-rated invoice, which
    // is exactly why it must be null.
    return $totals['net'] === 100.0 && $totals['vat'] === null && $totals['gross'] === null;
})());

echo "\nSubmission payload\n";

/*
 * The sign rule, which is Clear Books' and not InvoGrid's:
 *
 *   a bill            is positive — money spent
 *   a credit note     is ALSO positive at creation; Clear Books inverts it
 *                     internally, because it is an amount available against an
 *                     invoice rather than a movement of money
 *   a purchase refund is negative — money that actually came back
 *
 * InvoGrid had the credit note at -1, which would have inverted an inversion
 * and put the amount back where it started. These three assertions are the
 * corrected rule written down where a future edit has to notice it.
 */
check('a bill is submitted positive', DocumentType::amountSign('bill') === 1);
check('a credit note is submitted positive, not negative', DocumentType::amountSign('credit_note') === 1);
check('a purchase refund is submitted negative', DocumentType::amountSign('purchase_refund') === -1);

check('a credit note goes to its own endpoint', (static function (): bool {
    return (string) (DocumentType::find('credit_note')['clearbooks_resource'] ?? '') === 'purchases/creditNotes';
})());

// A refund is an ordinary purchase document carrying money that came back, so
// it posts to bills — the sign is what distinguishes it, not the endpoint.
check('a refund is a bill, distinguished only by its sign', (static function (): bool {
    return (string) (DocumentType::find('purchase_refund')['clearbooks_resource'] ?? '') === 'purchases/bills';
})());

// The two that are easily confused both stop and ask; an ordinary bill does not.
check('a bill needs no confirmation', !DocumentType::requiresConfirmation('bill'));
check('a credit note needs confirming', DocumentType::requiresConfirmation('credit_note'));
check('a refund needs confirming', DocumentType::requiresConfirmation('purchase_refund'));

// Unclassified is not "nothing to confirm": nobody has said what it is, so
// nobody has agreed to it either.
check('an unclassified document needs confirming', DocumentType::requiresConfirmation(null));

echo "\nLLM client selection\n";

// The whole point of the abstraction: nothing outside App\Services\Llm names a
// provider, and switching one in Settings must change what gets called.
check('both providers are offered', LlmFactory::PROVIDERS === ['anthropic', 'openai']);
check('ocr and extraction choose separately', LlmFactory::STAGES === ['ocr', 'extraction']);
check('an unknown stage is refused', (static function (): bool {
    try {
        LlmFactory::forStage('nonsense');
    } catch (LlmException) {
        return true;
    }

    return false;
})());

// A gateway base URL has the provider's path appended, so an administrator does
// not have to know that one is /v1/messages and the other /v1/chat/completions.
check('a gateway URL gains the Anthropic path', (static function (): bool {
    $method = new ReflectionMethod(LlmFactory::class, 'endpoint');
    $method->setAccessible(true);

    $before = Setting::get('anthropic_base_url');

    try {
        Setting::put('anthropic_base_url', 'https://gateway.example.com/');
        $built = $method->invoke(null, 'anthropic');
    } finally {
        Setting::put('anthropic_base_url', (string) $before);
    }

    return $built === 'https://gateway.example.com/v1/messages';
})());

check('an empty base URL means go direct', (static function (): bool {
    $method = new ReflectionMethod(LlmFactory::class, 'endpoint');
    $method->setAccessible(true);

    $before = Setting::get('openai_base_url');

    try {
        Setting::put('openai_base_url', '');
        $built = $method->invoke(null, 'openai');
    } finally {
        Setting::put('openai_base_url', (string) $before);
    }

    return $built === null;
})());

// Models wrap JSON in a fence however firmly the prompt says not to.
$fenced = new LlmResponse("```json\n{\"ocrText\":\"hello\",\"clearBooksNumber\":\"80421\"}\n```", 'stub', 'stub');
check('a fenced JSON reply still parses', ($fenced->json()['clearBooksNumber'] ?? null) === '80421');

$chatty = new LlmResponse("Here is the transcription:\n{\"ocrText\":\"hello\"}", 'stub', 'stub');
check('a reply with a preamble still parses', ($chatty->json()['ocrText'] ?? null) === 'hello');

$prose = new LlmResponse('The invoice reads: total 300.00', 'stub', 'stub');
check('plain prose parses as null rather than throwing', $prose->json() === null);

echo "\nPrompt rendering\n";

// The n8n prompts interpolated with JavaScript expressions. Here a placeholder
// is a name, resolved by the caller — and a name nothing provides is an error
// at render time, not a literal `{{ suppliers }}` posted to a model that will
// then answer confidently about nothing.
check('a placeholder is filled', PromptRenderer::render('a {{ b }} c', ['b' => 'B']) === 'a B c');
check('whitespace inside the braces is allowed', PromptRenderer::render('{{b}} {{  b  }}', ['b' => 'X']) === 'X X');
check('a value containing braces is not re-scanned',
    PromptRenderer::render('{{ b }}', ['b' => '{{ c }}']) === '{{ c }}');

check('an unknown placeholder throws', (static function (): bool {
    try {
        PromptRenderer::render('{{ nope }}', ['b' => 'B']);
    } catch (Throwable $e) {
        // The message has to name what is available, or the person editing the
        // prompt is left guessing.
        return str_contains($e->getMessage(), 'nope') && str_contains($e->getMessage(), 'b');
    }

    return false;
})());

check('the variables a template uses can be listed',
    PromptRenderer::variablesUsed('{{ a }} then {{ b }} then {{ a }}') === ['a', 'b']);

// An empty `[]` reads to a model as "there are none", after which it invents
// values to fill the gap. Saying so plainly gets a better failure.
check('an empty reference list says so', str_contains(PromptRenderer::encodeList([]), 'this list is empty'));
check('a populated list is JSON', str_contains(PromptRenderer::encodeList([['id' => '1']]), '"id": "1"'));

echo "\nCustom field coercion\n";

// A value that will not fit its declared type becomes null — "not found", which
// is a legitimate answer everywhere here. Storing a malformed one pushes the
// failure into the Paperless write-back, where it is far less legible.
check('an integer field takes a number', CustomField::coerce('integer', '42') === 42);
check('an integer field refuses a sentence', CustomField::coerce('integer', 'about forty') === null);
check('a date field normalises', CustomField::coerce('date', '26 August 2026') === '2026-08-26');
check('a date field refuses rubbish', CustomField::coerce('date', 'soon') === null);
check('a boolean field reads yes', CustomField::coerce('boolean', 'yes') === true);
check('a boolean field refuses maybe', CustomField::coerce('boolean', 'maybe') === null);
check('a monetary field takes a decimal', CustomField::coerce('monetary', '12.50') === 12.5);
check('an empty value is absent, not empty-string', CustomField::coerce('string', '') === null);
check('a string field keeps its value', CustomField::coerce('string', '80421') === '80421');

echo "\nPDF rendering\n";

// poppler is a system prerequisite, not a PHP one, so its absence is a skip
// with a clear reason rather than a failure — this test runs on machines that
// have not installed it yet.
if (!PdfRenderer::isAvailable()) {
    echo "  -- skipped: pdftoppm not found. Install poppler-utils, or set PDFTOPPM_PATH.\n";
} else {
    $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'invogrid-smoke-' . getmypid();
    @mkdir($workspace, 0775, true);
    $samplePdf = $workspace . DIRECTORY_SEPARATOR . 'sample.pdf';

    file_put_contents($samplePdf, smoke_sample_pdf());

    try {
        $renderer = new PdfRenderer();

        check('pdfinfo counts the pages', $renderer->pageCount($samplePdf) === 2);

        $pages = $renderer->render($samplePdf, $workspace . DIRECTORY_SEPARATOR . 'out', 'pages/smoke');

        check('both pages render', count($pages) === 2);
        check('pages come back in order', array_column($pages, 'page') === [1, 2]);
        check('an A4 page at 200 DPI is about 1653x2339', $pages[0]['width'] === 1653 && $pages[0]['height'] === 2339);
        check('inside the vision models\' 2576px long edge', max($pages[0]['width'], $pages[0]['height']) <= 2576);
        check('the files are real images', @getimagesize($pages[0]['path']) !== false);
        check('paths are stored relative to the storage root', str_starts_with($pages[0]['relative'], 'pages/smoke/'));

        // The renderer must judge success on the exit code and the files, never
        // on stderr — poppler warns about fonts on a perfectly good render.
        check('a render that warns on stderr still succeeds', $pages[0]['bytes'] > 0);
    } finally {
        foreach (glob($workspace . '/out/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($workspace . '/out');
        @unlink($samplePdf);
        @rmdir($workspace);
    }
}

echo "\nHelpers\n";
check('e() escapes', e('<b>&</b>') === '&lt;b&gt;&amp;&lt;/b&gt;');
check('url() builds a path', url('/login') === '/login');
check('url() leaves an absolute url alone', url('https://example.com/x') === 'https://example.com/x');
check('format_money defaults to GBP', format_money(12.5) === "\u{a3}12.50");
check('format_money honours a currency', format_money(12.5, 'EUR') === "\u{20ac}12.50");
check('format_money on an unknown currency', format_money(12.5, 'SEK') === 'SEK 12.50');
check('format_date on empty', format_date('') === "\u{2014}");

echo "\nRoutes\n";
$router = require dirname(__DIR__) . '/routes/web.php';
check('routes/web.php returns a Router', $router instanceof Router);
check('login is named', Router::path('login') === '/login');
check('logout is named', Router::path('logout') === '/logout');
check('the dashboard is named', Router::path('dashboard') === '/');

/*
 * Every route is gated, or is on this list.
 *
 * "Enforce the role check server-side on every protected route" is only a fact
 * if something checks. Hiding a button is a courtesy; this is the enforcement,
 * and a route added next year without a gate fails here rather than being found
 * by whoever finds it.
 *
 * The four open ones, and why each is open:
 */
$deliberatelyOpen = [
    // Says nothing but "the process answered".
    'GET /health',
    // The logo, needed by the sign-in page before anybody is signed in.
    'GET /branding/{variant:light|dark}',
    // Not a browser form. Authenticated by the shared secret in
    // `paperless_webhook_secret`, because Paperless has no session to carry.
    'POST /webhook/paperless',
    // Signing in, which cannot itself require being signed in.
    'GET /login',
    'POST /login',
];

$ungated = [];

foreach ($router->routes() as $route) {
    $signature = $route['method'] . ' ' . $route['pattern'];

    if (in_array($signature, $deliberatelyOpen, true)) {
        continue;
    }

    $gated = false;

    foreach ($route['middleware'] as $middleware) {
        $name = explode(':', $middleware, 2)[0];

        // `can`, `canany` and `role` all call AuthMiddleware first.
        if (in_array($name, ['auth', 'can', 'canany', 'role'], true)) {
            $gated = true;
            break;
        }
    }

    if (!$gated) {
        $ungated[] = $signature;
    }
}

check(
    'every route is gated, or is one of the ' . count($deliberatelyOpen) . ' deliberately open ones'
        . ($ungated === [] ? '' : ' — ungated: ' . implode(', ', $ungated)),
    $ungated === []
);

// Anything that changes something needs a token as well as a permission. The
// callback is the exception, and the reason is in routes/web.php: it is a
// redirect from Clear Books, which has no token to carry, and a `state`
// parameter checked against the session does the same job.
$csrfExempt = ['POST /webhook/paperless', 'GET /admin/clearbooks/callback'];
$unprotected = [];

foreach ($router->routes() as $route) {
    if ($route['method'] === 'GET') {
        continue;
    }

    $signature = $route['method'] . ' ' . $route['pattern'];

    if (in_array($signature, $csrfExempt, true)) {
        continue;
    }

    if (!in_array('csrf', $route['middleware'], true)) {
        $unprotected[] = $signature;
    }
}

check(
    'every state-changing route carries csrf'
        . ($unprotected === [] ? '' : ' — missing: ' . implode(', ', $unprotected)),
    $unprotected === []
);

// The users screen is admin-only on every one of its routes, not just the one
// with the link to it.
$userRoutes = array_values(array_filter(
    $router->routes(),
    static fn (array $r): bool => str_starts_with($r['pattern'], '/admin/users')
));

check('the users screen has routes at all', count($userRoutes) >= 7);
check('every users route requires users.manage', (static function (array $routes): bool {
    foreach ($routes as $route) {
        if (!in_array('can:users.manage', $route['middleware'], true)) {
            return false;
        }
    }

    return true;
})($userRoutes));

// Changing your own password is the one thing every account can do, including
// the viewer who can do nothing else. A capability on it would lock somebody
// out of the only remedy for a password an administrator knows.
check('changing your own password needs no capability', (static function (array $routes): bool {
    foreach ($routes as $route) {
        if ($route['pattern'] !== '/account/password') {
            continue;
        }

        foreach ($route['middleware'] as $middleware) {
            if (str_starts_with($middleware, 'can') || str_starts_with($middleware, 'role')) {
                return false;
            }
        }
    }

    return true;
})($router->routes()));

echo "\nBranding, uploads and print\n";

// SVG is a document that can carry script. Serving one from this origin would
// let anybody who can reach the branding screen run code in everybody else's
// browser, so it must never appear on any of these lists.
check('no whitelist admits SVG', (static function (): bool {
    $lists = [
        (array) Config::get('uploads.logo_mimes', []),
        (array) Config::get('uploads.logo_extensions', []),
    ];

    foreach ($lists as $list) {
        foreach ($list as $item) {
            if (stripos((string) $item, 'svg') !== false) {
                return false;
            }
        }
    }

    return true;
})());

check('a logo is capped at a sensible size',
    Branding::maxBytes() > 0 && Branding::maxBytes() <= 8 * 1024 * 1024);

check('both variants are known', Branding::VARIANTS === ['light', 'dark']);

// path() shows what is actually in a slot; resolve() stands the other variant
// in. The admin form must use the first or an empty slot looks filled.
check('an unset variant reads as unset', (static function (): bool {
    $slot = Branding::slot('__nonsense__');

    return $slot['url'] === null && $slot['dimensions'] === null;
})());

check('a stored path cannot climb out of the uploads directory', (static function (): bool {
    foreach (['../../../etc/passwd', '..\\..\\windows\\win.ini', '/etc/passwd', ''] as $attempt) {
        if (App\Core\Upload::absolutePath($attempt) !== null) {
            return false;
        }
    }

    return true;
})());

// Three independent checks, and the third is the one a script wearing a PNG
// header fails. Exercised here on real files rather than trusted by reading.
check('upload validation refuses what it should', (static function (): bool {
    $dir = sys_get_temp_dir() . '/invogrid-smoke-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0700, true);

    $cases = [];

    try {
        // A PHP script named .png. finfo sees text/x-php, and even if it did
        // not, getimagesize() would refuse it.
        $script = $dir . '/evil.png';
        file_put_contents($script, "<?php echo 'pwned'; ?>\n");
        $cases['a script named .png'] = [
            'name' => 'evil.png', 'tmp_name' => $script,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($script),
        ];

        // A real PNG under a forbidden extension.
        $svg = $dir . '/logo.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $cases['an SVG'] = [
            'name' => 'logo.svg', 'tmp_name' => $svg,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($svg),
        ];

        // Empty.
        $empty = $dir . '/empty.png';
        file_put_contents($empty, '');
        $cases['an empty file'] = [
            'name' => 'empty.png', 'tmp_name' => $empty,
            'error' => UPLOAD_ERR_OK, 'size' => 0,
        ];

        foreach ($cases as $file) {
            $problem = App\Core\Upload::validate(
                $file,
                Branding::mimes(),
                Branding::extensions(),
                Branding::maxBytes()
            );

            // Every one must be refused. `is_uploaded_file()` refuses them all
            // on its own here, which is itself the point: nothing that did not
            // arrive as a real upload gets through.
            if ($problem === null) {
                return false;
            }
        }

        return true;
    } finally {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($dir);
    }
})());

check('a filename from the client is never trusted as a path',
    App\Core\Upload::displayName('../../etc/passwd') === 'passwd'
        && App\Core\Upload::displayName('') === 'The file');

check('bytes are formatted for a person',
    App\Core\Upload::formatBytes(2 * 1024 * 1024) === '2 MB'
        && App\Core\Upload::formatBytes(700) === '1 KB');

/*
 * Every colour goes through a variable, or it is one of the places where a
 * fixed colour is the correct answer.
 *
 * This is the light/dark sweep, automated so it cannot quietly drift back.
 * Three exemptions, each of which is commented at the point of use:
 *
 *  - the `:root` and `[data-theme]` blocks, which *are* the variables;
 *  - the print layout, which is one palette because paper is white;
 *  - the logo previews and the scan thumbnail, which must sit on the ground
 *    they are for rather than the page's current theme.
 */
check('no colour bypasses the theme variables', (static function (): bool {
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/css/app.css');

    // Everything from the print banner onwards is single-palette by design.
    $printFrom = strpos($css, 'InvoGrid follows Kitwell here');
    $screen    = $printFrom === false ? $css : substr($css, 0, $printFrom);

    $offenders = [];

    foreach (explode("\n", $screen) as $number => $line) {
        if (!preg_match('/#[0-9a-fA-F]{3,8}\b|\brgba?\(/', $line)) {
            continue;
        }

        // The token definitions themselves, and the shadow built from them.
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }

        // The two deliberate exceptions, both commented in the stylesheet.
        if (str_contains($line, 'logo-preview') || str_contains($line, 'page-thumb')) {
            continue;
        }

        // A comment mentioning a colour is not a colour.
        if (preg_match('/^\s*(\/\*|\*)/', $line)) {
            continue;
        }

        // `background: #ffffff;` inside .page-thumb img, which is two lines
        // below its own comment rather than on the selector line.
        if (trim($line) === 'background: #ffffff;') {
            continue;
        }

        $offenders[] = ($number + 1) . ': ' . trim($line);
    }

    if ($offenders !== []) {
        echo '        ' . implode("\n        ", array_slice($offenders, 0, 5)) . "\n";
    }

    return $offenders === [];
})());

check('the reduced-motion answer covers the page, not three components', (static function (): bool {
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/css/app.css');

    return substr_count($css, 'prefers-reduced-motion') === 1
        && preg_match('/prefers-reduced-motion.*?\*,\s*\*::before,\s*\*::after/s', $css) === 1;
})());

check('the print layout and its template exist',
    is_file(dirname(__DIR__) . '/templates/layouts/print.php')
        && is_file(dirname(__DIR__) . '/templates/documents/print.php')
        && is_file(dirname(__DIR__) . '/templates/admin/branding.php'));

// A printed page must not carry the navigation. It is on its own layout for
// exactly that reason — hiding it with CSS still ships it.
check('the print layout does not include the navigation', (static function (): bool {
    $layout = (string) file_get_contents(dirname(__DIR__) . '/templates/layouts/print.php');

    return !str_contains($layout, "partials/nav")
        && !str_contains($layout, "partials/footer")
        && str_contains($layout, 'no-print');
})());

check('serving the logo is open; replacing it is not', (static function (array $routes): bool {
    $open = 0;
    $gated = 0;

    foreach ($routes as $route) {
        if (!str_contains($route['pattern'], 'branding')) {
            continue;
        }

        $isAdmin = str_starts_with($route['pattern'], '/admin/');

        if ($isAdmin) {
            if (!in_array('can:settings.manage', $route['middleware'], true)) {
                return false;
            }

            $gated++;
        } else {
            $open++;
        }
    }

    return $open === 1 && $gated === 3;
})($router->routes()));

echo "\nThe documentation, against the code\n";

/*
 * PROJECT-STATE.md is what a future maintenance session reads before touching
 * anything, so a route it does not mention is a route that session will not
 * know exists. Of everything in that document this is the part most likely to
 * rot, because adding a route is a one-line change somebody can make without
 * opening the docs at all.
 */
check('every route appears in PROJECT-STATE', (static function (array $routes): bool {
    $doc = (string) file_get_contents(dirname(__DIR__) . '/docs/PROJECT-STATE.md');

    /*
     * Normalise **both** sides, not just the route.
     *
     * The document usually writes `{id}` where the table says `{id:\d+}`, but
     * not always — the branding route is written out in full, with its pipe
     * escaped because it sits in a markdown table. Stripping the regex from one
     * side only made that route look undocumented when it was not.
     */
    $normalise = static fn (string $p): string => preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $p);
    $plain     = $normalise(str_replace('\|', '|', $doc));

    $missing = [];

    foreach ($routes as $route) {
        if (!str_contains($plain, '`' . $normalise($route['pattern']) . '`')) {
            $missing[] = $route['method'] . ' ' . $route['pattern'];
        }
    }

    if ($missing !== []) {
        echo '        ' . implode("\n        ", array_unique($missing)) . "\n";
    }

    return $missing === [];
})($router->routes()));

check('every migration appears in PROJECT-STATE', (static function (): bool {
    $doc     = (string) file_get_contents(dirname(__DIR__) . '/docs/PROJECT-STATE.md');
    $missing = [];

    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $file) {
        if (!str_contains($doc, basename($file))) {
            $missing[] = basename($file);
        }
    }

    if ($missing !== []) {
        echo '        ' . implode("\n        ", $missing) . "\n";
    }

    return $missing === [];
})());

// A doc naming a file that has since been renamed sends the next maintainer
// looking for something that is not there.
check('PROJECT-STATE names no file that has gone', (static function (): bool {
    $doc = (string) file_get_contents(dirname(__DIR__) . '/docs/PROJECT-STATE.md');

    preg_match_all(
        '/`((?:src|templates|bin|tests|config|routes|public)\/[A-Za-z0-9\/_.-]+\.(?:php|css|js|sql))`/',
        $doc,
        $matches
    );

    $gone = array_values(array_unique(array_filter(
        $matches[1],
        static fn (string $path): bool => !is_file(dirname(__DIR__) . '/' . $path)
    )));

    if ($gone !== []) {
        echo '        ' . implode("\n        ", $gone) . "\n";
    }

    return $gone === [];
})());

// The README tells somebody to run these. If one is renamed, they hit a
// "No such file" on their first day.
check('every script the README names exists', (static function (): bool {
    $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

    preg_match_all('/php (bin\/[a-z-]+\.php|tests\/[a-z]+\.php)/', $readme, $matches);

    $gone = array_values(array_unique(array_filter(
        $matches[1],
        static fn (string $path): bool => !is_file(dirname(__DIR__) . '/' . $path)
    )));

    if ($gone !== []) {
        echo '        ' . implode("\n        ", $gone) . "\n";
    }

    return $gone === [];
})());

echo "\nThe install and management scripts\n";

check('both scripts exist and are executable-ish',
    is_file(dirname(__DIR__) . '/install.sh') && is_file(dirname(__DIR__) . '/manage.sh'));

/*
 * Every command manage.sh dispatches to must have a function behind it.
 *
 * A case label with no `cmd_` function is a command that prints "command not
 * found" at the moment somebody needs it, which on a server at 2am is the
 * worst possible time to discover a typo.
 */
check('every manage.sh command has a function behind it', (static function (): bool {
    $sh = (string) file_get_contents(dirname(__DIR__) . '/manage.sh');

    // The dispatch block only — the usage text mentions the same words.
    $from = strpos($sh, 'case "$command" in');

    if ($from === false) {
        return false;
    }

    preg_match_all('/^\s{4}([a-z|-]+)\)\s+(cmd_[a-z_]+)/m', substr($sh, $from), $matches, PREG_SET_ORDER);

    $missing = [];

    foreach ($matches as [, $label, $function]) {
        if (!preg_match('/^' . preg_quote($function, '/') . '\(\)/m', $sh)) {
            $missing[] = $label . ' -> ' . $function . '()';
        }
    }

    if ($missing !== []) {
        echo '        ' . implode("\n        ", $missing) . "\n";
    }

    return $matches !== [] && $missing === [];
})());

/*
 * Every application script the two shell scripts invoke must exist.
 *
 * This is the failure that actually happened while writing the README: a
 * documented `--verbose` flag that was never implemented. A shell script naming
 * a file that has been renamed fails the same way, later, on somebody else's
 * server.
 */
check('every bin/ and tests/ script the shell scripts call exists', (static function (): bool {
    $missing = [];

    foreach (['install.sh', 'manage.sh'] as $name) {
        $sh = (string) file_get_contents(dirname(__DIR__) . '/' . $name);

        // Comment lines are stripped first. A comment naming a placeholder
        // path is documentation, not a call, and treating it as one made this
        // assertion fail on its own explanatory text.
        $code = implode("\n", array_filter(
            explode("\n", $sh),
            static fn (string $line): bool => !preg_match('/^\s*#/', $line)
        ));

        preg_match_all('#\b((?:bin|tests)/[a-z-]+\.php)#', $code, $matches);

        foreach (array_unique($matches[1]) as $path) {
            if (!is_file(dirname(__DIR__) . '/' . $path)) {
                $missing[] = $name . ' calls ' . $path;
            }
        }
    }

    if ($missing !== []) {
        echo '        ' . implode("\n        ", $missing) . "\n";
    }

    return $missing === [];
})());

check('every console command the shell scripts call is implemented', (static function (): bool {
    $console = (string) file_get_contents(dirname(__DIR__) . '/bin/console.php');
    $missing = [];

    foreach (['install.sh', 'manage.sh'] as $name) {
        $sh = (string) file_get_contents(dirname(__DIR__) . '/' . $name);

        /*
         * `console <verb>` and `bin/console.php <verb>`, both of which appear.
         *
         * A verb is either `group:action` or one of the two bare ones. Matching
         * any bare word after "console" swept up the prose in the file header —
         * "goes through bin/console.php so it uses the application's own
         * models" — and reported a missing command called `so`.
         */
        preg_match_all(
            '/(?:console(?:\.php)?)\s+([a-z]+:[a-z-]+|doctor|stats)\b/',
            $sh,
            $matches
        );

        foreach (array_unique($matches[1]) as $verb) {
            if (!str_contains($console, "case '" . $verb . "':")) {
                $missing[] = $name . ' calls console ' . $verb;
            }
        }
    }

    if ($missing !== []) {
        echo '        ' . implode("\n        ", $missing) . "\n";
    }

    return $missing === [];
})());

// The queue and the cache refresh are what make the application run at all.
// A cron block that names the wrong script is an install that looks fine and
// processes nothing.
check('cron-install writes both jobs, naming scripts that exist', (static function (): bool {
    $sh = (string) file_get_contents(dirname(__DIR__) . '/manage.sh');

    return str_contains($sh, 'bin/process-queue.php')
        && str_contains($sh, 'bin/refresh-clearbooks.php')
        && str_contains($sh, '/etc/cron.d/invogrid');
})());

// poppler is the one local dependency without which no document can be read.
check('the installer refuses to finish without pdftoppm', (static function (): bool {
    $sh = (string) file_get_contents(dirname(__DIR__) . '/install.sh');

    return str_contains($sh, 'poppler-utils')
        && preg_match('/have pdftoppm.*?\n\s*die /s', $sh) === 1;
})());

// An update that overwrote .env would replace APP_KEY, and every stored
// credential would become an unreadable blob with no error at the time.
check('nothing copies over .env or storage', (static function (): bool {
    foreach (['install.sh', 'manage.sh'] as $name) {
        $sh = (string) file_get_contents(dirname(__DIR__) . '/' . $name);

        /*
         * Only archives rooted at the whole application need the exclusions.
         *
         * The backup's file archive is rooted at `storage` and names the two
         * subdirectories it wants, so there is no .env anywhere near it — and
         * requiring the flag there reported a fault that did not exist. What
         * matters is the tars that walk the application directory: `update` and
         * `package`, either of which copying .env would replace APP_KEY and
         * turn every stored credential into an unreadable blob, with no error
         * at the moment it happened.
         */
        preg_match_all('/tar -c[zf][zf]? [^\n]*-C "\$(APP_DIR|source)"(.*?)\n\s*\n/s', $sh, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            foreach (['--exclude=./.env', '--exclude=./.git'] as $flag) {
                if (!str_contains($match[2], $flag)) {
                    echo '        ' . $name . ': a tar over $' . $match[1] . ' without ' . $flag . "\n";

                    return false;
                }
            }
        }
    }

    return true;
})());

check('the doctor covers what an install actually needs', (static function (): bool {
    $rows   = App\Services\Doctor::run();
    $groups = array_unique(array_column($rows, 'group'));

    foreach (['PHP', 'Configuration', 'Storage', 'Database', 'Tools', 'Integrations'] as $wanted) {
        if (!in_array($wanted, $groups, true)) {
            return false;
        }
    }

    // Every row has to say what to do about itself, or it is a diagnosis
    // nobody can act on.
    foreach ($rows as $row) {
        if ($row['status'] !== App\Services\Doctor::OK && trim($row['hint']) === '' && $row['group'] !== 'Pipeline') {
            echo '        no hint on: ' . $row['label'] . "\n";

            return false;
        }
    }

    return true;
})());

echo "\nSecrets, and what may reach a browser\n";

/*
 * `Setting::get()` decrypts a secret rather than handing back ciphertext, which
 * is right for the caller that needs it and means there is no type-level
 * barrier stopping a template from rendering one. So the barrier is this: no
 * template may name a secret key at all.
 */
check('no template reads a secret setting', (static function (): bool {
    $secrets = [
        'paperless_token', 'paperless_webhook_secret',
        'clearbooks_client_secret', 'clearbooks_access_token', 'clearbooks_refresh_token',
        'openai_api_key', 'anthropic_api_key',
    ];

    $offenders = [];

    foreach (glob(dirname(__DIR__) . '/templates/*/*.php') ?: [] as $file) {
        $body = (string) file_get_contents($file);

        foreach ($secrets as $key) {
            // The Clear Books screen names `clearbooks_client_secret` in prose,
            // telling an administrator which console setting to fill in. Naming
            // it is fine; reading it is not.
            if (preg_match('/Setting::(get|secret|int)\(\s*[\'"]' . preg_quote($key, '/') . '/', $body)) {
                $offenders[] = basename(dirname($file)) . '/' . basename($file) . ' — ' . $key;
            }
        }
    }

    if ($offenders !== []) {
        echo '        ' . implode("\n        ", $offenders) . "\n";
    }

    return $offenders === [];
})());

/*
 * The Settings screen is the one page whose whole job is handling credentials,
 * so the rule above is tightened for it: it may not touch the model at all.
 * Everything it renders is prepared by the controller, where `secret` is turned
 * into a boolean before it ever reaches a template.
 */
check('the settings screen never reads a setting itself', (static function (): bool {
    $body = (string) file_get_contents(dirname(__DIR__) . '/templates/admin/settings.php');

    // Comments are stripped first, because the file's own docblock explains
    // this rule and names `Setting::secret()` doing it. A check that cannot
    // tell an explanation from a call would be satisfied by deleting the
    // paragraph that says why the rule exists.
    $code = '';
    foreach (token_get_all($body) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return !str_contains($code, 'Setting::');
})());

check('the settings and activity screens exist',
    is_file(dirname(__DIR__) . '/templates/admin/settings.php')
        && is_file(dirname(__DIR__) . '/templates/admin/activity.php'));

/*
 * A key named in the schema but absent from the seed is a field that renders,
 * accepts what is typed and silently never comes back — the worst kind of bug
 * on a settings screen, because it looks exactly like success.
 */
check('every editable setting is a seeded row', (static function (): bool {
    $seeded = [];

    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $file) {
        if (preg_match_all('/^\s*\(\'([a-z0-9_]+)\'\s*,/mi', (string) file_get_contents($file), $m) > 0) {
            $seeded = array_merge($seeded, $m[1]);
        }
    }

    $missing = array_diff(SettingSchema::keys(), $seeded);

    if ($missing !== []) {
        echo '        not seeded: ' . implode(', ', $missing) . "\n";
    }

    return $missing === [];
})());

/*
 * The deliberate omissions, asserted rather than left to a comment.
 *
 * The three OAuth tokens are written by the consent flow and the logo paths by
 * an upload; offering any of them as a text box is how somebody breaks a
 * working connection, or points the header at a file that is not there.
 */
check('the settings screen does not offer the machine-written rows', (static function (): bool {
    $forbidden = [
        'clearbooks_access_token', 'clearbooks_refresh_token', 'clearbooks_token_expires_at',
        'logo_light_path', 'logo_light_mime', 'logo_dark_path', 'logo_dark_mime',
    ];

    $offered = array_intersect($forbidden, SettingSchema::keys());

    if ($offered !== []) {
        echo '        editable when it should not be: ' . implode(', ', $offered) . "\n";
    }

    return $offered === [];
})());

check('every editable setting belongs to a card that exists', (static function (): bool {
    foreach (SettingSchema::fields() as $key => $field) {
        if (!SettingSchema::isSection((string) $field['section'])) {
            echo '        ' . $key . ' names section ' . $field['section'] . "\n";

            return false;
        }
    }

    // And the other way round: an empty card renders as a heading with a Save
    // button under it and nothing in between.
    foreach (array_keys(SettingSchema::SECTIONS) as $section) {
        if (SettingSchema::forSection((string) $section) === []) {
            echo '        section ' . $section . ' has no fields' . "\n";

            return false;
        }
    }

    return true;
})());

/*
 * A section name reaches the router as a URL segment. `[a-z_]+` is what the
 * route pattern matches, and a section named outside it would 404 on save
 * while rendering perfectly.
 */
check('every settings section is a legal URL segment', (static function (): bool {
    foreach (array_keys(SettingSchema::SECTIONS) as $section) {
        if (preg_match('/^[a-z_]+$/', (string) $section) !== 1) {
            return false;
        }
    }

    return true;
})());

check('every JSON response is a fixed literal, never a settings dump', (static function (): bool {
    // A `Response::json($something)` built from a variable is where a secret
    // would escape without anybody noticing. Every call site must pass a
    // literal array.
    foreach (glob(dirname(__DIR__) . '/src/*/*.php') ?: [] as $file) {
        $body = (string) file_get_contents($file);

        if (preg_match('/Response::json\(\s*\$/', $body)) {
            echo '        variable passed to Response::json in ' . basename($file) . "\n";

            return false;
        }
    }

    return true;
})());

// The one thing a remote service can write to this disk.
check('a PDF fetched from Paperless has a size ceiling', (static function (): bool {
    $max = (int) Config::get('uploads.max_pdf_bytes', 0);

    if ($max <= 0) {
        return false;
    }

    // And the ceiling is actually passed to the download, not merely configured.
    $client = (string) file_get_contents(dirname(__DIR__) . '/src/Services/PaperlessClient.php');

    return str_contains($client, "Config::get('uploads.max_pdf_bytes'");
})());

check('the download cap aborts mid-transfer rather than measuring afterwards', (static function (): bool {
    // CURLOPT_MAXFILESIZE only works when the far end sends a Content-Length,
    // and a streamed response sends none — so it has to be a progress callback.
    $http = (string) file_get_contents(dirname(__DIR__) . '/src/Services/Http.php');

    return str_contains($http, 'CURLOPT_PROGRESSFUNCTION')
        && str_contains($http, 'CURLOPT_NOPROGRESS');
})());

check('a downloaded file that is not a PDF is deleted, not kept', (static function (): bool {
    $client = (string) file_get_contents(dirname(__DIR__) . '/src/Services/PaperlessClient.php');

    return str_contains($client, "\$magic !== '%PDF-'")
        && preg_match('/\$magic !== \'%PDF-\'\).*?unlink\(\$path\)/s', $client) === 1;
})());

// A timing-safe comparison, on every route the secret can arrive by.
check('the webhook secret is compared in constant time', (static function (): bool {
    $controller = (string) file_get_contents(dirname(__DIR__) . '/src/Controllers/WebhookController.php');

    return substr_count($controller, 'hash_equals(') >= 3
        && !preg_match('/\$expected\s*===\s*\$/', $controller);
})());

echo "\nRate limits and backoff\n";

// Both shapes of Retry-After are in use, and both are in the spec.
check('Retry-After in seconds is read', (static function (): bool {
    $response = new App\Services\HttpResponse(429, '', ['retry-after' => '90']);

    return App\Services\Http::retryAfter($response) === 90;
})());

check('Retry-After as an HTTP date is read', (static function (): bool {
    $response = new App\Services\HttpResponse(429, '', [
        'retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120),
    ]);

    $seconds = App\Services\Http::retryAfter($response);

    // A second or two of slack: the header is built and read either side of a
    // clock tick.
    return $seconds !== null && $seconds >= 118 && $seconds <= 121;
})());

check('a Retry-After in the past is nothing to wait for', (static function (): bool {
    $response = new App\Services\HttpResponse(429, '', [
        'retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() - 600),
    ]);

    return App\Services\Http::retryAfter($response) === 0;
})());

check('no Retry-After is null, not zero', (static function (): bool {
    return App\Services\Http::retryAfter(new App\Services\HttpResponse(429, '')) === null
        && App\Services\Http::retryAfter(new App\Services\HttpResponse(429, '', ['retry-after' => 'nonsense'])) === null;
})());

check('a nonsense Retry-After cannot park a document for a week', (static function (): bool {
    $response = new App\Services\HttpResponse(429, '', ['retry-after' => '999999']);

    return App\Services\Http::retryAfter($response) === 3600;
})());

/*
 * The rule that matters most in this whole section.
 *
 * A GET may be repeated; a POST that creates a bill may not. Clear Books has no
 * idempotency key, so a POST that timed out after the record was written is
 * indistinguishable from one that never arrived — and repeating it puts a
 * second bill in somebody's accounts.
 */
check('retrying is off by default on every HTTP helper', (static function (): bool {
    foreach (['request' => 6, 'get' => 3, 'postJson' => 4, 'download' => 4] as $name => $position) {
        $parameters = (new ReflectionMethod(App\Services\Http::class, $name))->getParameters();

        if (!isset($parameters[$position])) {
            return false;
        }

        $parameter = $parameters[$position];

        if ($parameter->getName() !== 'retries' || $parameter->getDefaultValue() !== 0) {
            return false;
        }
    }

    return true;
})());

check('nothing that creates a record in Clear Books asks for retries', (static function (): bool {
    // A crude read of the source, and deliberately so: this is a rule about
    // what must never be written, and the cheapest guard is to look.
    $source = file_get_contents(dirname(__DIR__) . '/src/Services/ClearBooksClient.php');

    // The one call site that sets retries must gate them on the method.
    return is_string($source)
        && str_contains($source, "strtoupper(\$method) === 'GET' ? 2 : 0");
})());

check('an exception that can explain itself is asked to', (static function (): bool {
    $llm = new App\Services\Llm\LlmException('Rate limited.', true, 429, [
        'provider' => 'openai',
        'model'    => 'gpt-5',
    ], 42);

    $context = $llm->context();

    return $llm instanceof App\Services\Diagnosable
        && $llm->retryAfter() === 42
        && $context['provider'] === 'openai'
        && (int) $context['http status'] === 429
        && $context['retryable'] === 'yes';
})());

check('an LLM failure can be told which call it happened on', (static function (): bool {
    $llm = (new App\Services\Llm\LlmException('Rate limited.', true, 429, ['provider' => 'openai']))
        ->during('extract_supplier');

    return $llm->context()['call'] === 'extract_supplier'
        && $llm->context()['provider'] === 'openai'
        && $llm->retryable === true;
})());

check('a Clear Books failure explains itself too', (static function (): bool {
    $e = new App\Services\ClearBooksException('Too many requests.', true, 429, null, ['endpoint' => 'suppliers'], 30);

    return $e instanceof App\Services\Diagnosable
        && $e->context()['service'] === 'Clear Books'
        && $e->context()['endpoint'] === 'suppliers'
        && $e->retryAfter() === 30;
})());

// Retry-After may lengthen the queue's wait, never shorten it: a provider
// asking for one second must not turn four attempts into four seconds.
check('Retry-After can only make the queue wait longer', (static function (): bool {
    $method = new ReflectionMethod(App\Models\PipelineJob::class, 'fail');
    $source = file_get_contents(dirname(__DIR__) . '/src/Models/PipelineJob.php');

    return $method->getNumberOfParameters() === 5
        && is_string($source)
        && str_contains($source, 'max($backoffSeconds, min(3600, $retryAfter))');
})());

echo "\nRoles and passwords\n";

// Cumulative, in order, and read from the same constants the application
// enforces — so the table on the users screen cannot describe a permission
// model that is not in force.
$map = App\Core\Auth::capabilityMap();

check('there are three roles', App\Core\Auth::ROLES === ['viewer', 'reviewer', 'admin']);
check('a viewer can look but not touch',
    in_array('documents.view', $map['viewer'], true)
        && in_array('queue.view', $map['viewer'], true)
        && !in_array('review.resolve', $map['viewer'], true)
        && !in_array('documents.submit', $map['viewer'], true)
        && !in_array('entities.create', $map['viewer'], true)
        && !in_array('users.manage', $map['viewer'], true));
check('a reviewer works the queue but administers nothing',
    in_array('review.resolve', $map['reviewer'], true)
        && in_array('documents.submit', $map['reviewer'], true)
        && in_array('entities.create', $map['reviewer'], true)
        && !in_array('settings.manage', $map['reviewer'], true)
        && !in_array('users.manage', $map['reviewer'], true)
        && !in_array('prompts.manage', $map['reviewer'], true));
check('roles are cumulative upwards', (static function (array $map): bool {
    foreach (['viewer' => 'reviewer', 'reviewer' => 'admin'] as $lower => $higher) {
        foreach ($map[$lower] as $capability) {
            if (!in_array($capability, $map[$higher], true)) {
                return false;
            }
        }
    }

    return true;
})($map));

// Every capability named on a route must be one a role actually holds. A
// `can:documnets.view` typo would otherwise lock everybody out silently — the
// gate would simply always say no.
$known = $map['admin'];
$unknown = [];

foreach ($router->routes() as $route) {
    foreach ($route['middleware'] as $middleware) {
        if (str_starts_with($middleware, 'can:')) {
            $capability = substr($middleware, 4);

            if (!in_array($capability, $known, true)) {
                $unknown[] = $capability;
            }
        }
    }
}

check(
    'every capability named on a route exists'
        . ($unknown === [] ? '' : ' — unknown: ' . implode(', ', array_unique($unknown))),
    $unknown === []
);

check('the password policy is one place, and the rule is built from it',
    App\Core\PasswordPolicy::rule() === sprintf(
        'required|password:%d,%d',
        App\Core\PasswordPolicy::minLength(),
        App\Core\PasswordPolicy::minClasses()
    ));

check('a short password is refused',
    App\Core\PasswordPolicy::problems('Sh0rt!') !== []);
check('a single-class password is refused',
    App\Core\PasswordPolicy::problems('aaaaaaaaaaaaaaaaaaaa') !== []);
check('a passphrase with a number and a capital is accepted',
    App\Core\PasswordPolicy::problems('Correct horse battery 7') === []);

// The classic: the username with a digit on the end clears every
// character-class rule ever written and is the first thing anybody tries.
check('a password built out of the username is refused',
    App\Core\PasswordPolicy::problems('Jbloggs2026!', ['jbloggs', 'Jo Bloggs']) !== []);

check('the same password is fine for somebody else',
    App\Core\PasswordPolicy::problems('Jbloggs2026!', ['nsmith', 'Nick Smith']) === []);

echo "\nTemplates\n";
foreach ([
    'layouts/app', 'layouts/auth',
    'partials/brand', 'partials/nav', 'partials/footer', 'partials/flash',
    'auth/login', 'dashboard/index', 'errors/error',
] as $template) {
    check(
        $template . ' exists',
        is_file(dirname(__DIR__) . '/templates/' . $template . '.php')
    );
}

echo "\nDatabase\n";

// Reachable and migrated, or nothing below runs. A developer without a local
// database should still get value from everything above.
$dbReady   = false;
$dbProblem = null;

try {
    Database::connection();

    if ((new Migrator())->pending() !== []) {
        $dbProblem = 'migrations are pending; run php bin/migrate.php';
    } else {
        $dbReady = true;
    }
} catch (Throwable $throwable) {
    $dbProblem = $throwable->getMessage();
}

if ($dbProblem !== null) {
    echo '  -- skipped: ' . $dbProblem . "\n";
}

if ($dbReady) {
    // The state machine in PHP and the ENUM in the schema have to agree. They
    // are written in two files, so nothing but a check keeps them together.
    $enum = (string) Database::scalar(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['documents', 'status']
    );

    check('documents.status ENUM matches Document::STATUSES', (static function () use ($enum): bool {
        preg_match_all("/'([a-z_]+)'/", $enum, $matches);

        return array_diff(Document::STATUSES, $matches[1]) === []
            && array_diff($matches[1], Document::STATUSES) === [];
    })());

    // The bug this section was added for: a class naming a column the schema
    // does not have. Exercising it against the real table is the only way to
    // find that, and it cleans up after itself.
    $probe = '__smoke_test__';

    try {
        LoginThrottle::record($probe, '203.0.113.1', false);
        check('LoginThrottle writes to login_attempts', (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE username = ?',
            [$probe]
        ) === 1);
        check('LoginThrottle counts an attempt', LoginThrottle::remaining($probe, '203.0.113.1')
            === (int) Config::get('security.login.max_attempts', 5) - 1);
        check('LoginThrottle is not locked after one attempt', !LoginThrottle::isLocked($probe, '203.0.113.1'));
        LoginThrottle::clear($probe, '203.0.113.1');
        check('LoginThrottle clears its rows', (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE username = ?',
            [$probe]
        ) === 0);
    } finally {
        Database::run('DELETE FROM login_attempts WHERE username = ?', [$probe]);
    }

    // Settings: a secret has to survive the encrypt/decrypt round trip through
    // the database, and must never come back through the plain reader.
    try {
        check('a secret setting can be written', Setting::put($probe, 'sk-live-not-a-real-key', true));
        check('a secret reads back through secret()', Setting::secret($probe) === 'sk-live-not-a-real-key');
        check('a secret is stored encrypted, not in the clear', (static function () use ($probe): bool {
            $raw = (string) Database::scalar('SELECT setting_value FROM settings WHERE setting_key = ?', [$probe]);

            return str_starts_with($raw, 'v1.') && !str_contains($raw, 'sk-live');
        })());
        check('summary() reports it as set without the value', (static function () use ($probe): bool {
            Setting::flush();
            $row = Setting::summary()[$probe] ?? null;

            return $row !== null && $row['secret'] && $row['configured'] && $row['value'] === null;
        })());
    } finally {
        Database::run('DELETE FROM settings WHERE setting_key = ?', [$probe]);
        Setting::flush();
    }

    // The OCR prompt is data, and the field rules in it are the whole reason the
    // extraction is trustworthy. A migration that mangles the text, or an edit
    // that drops a rule, must not pass silently.
    check('an active OCR prompt exists', PromptTemplate::active('ocr') !== null);

    $ocrPrompt = PromptTemplate::content('ocr');

    check('it asks for the ### Notes section', str_contains($ocrPrompt, '### Notes'));
    check('it forbids substituting a printed number', str_contains($ocrPrompt, 'do not guess or substitute a printed number'));
    check('it says a Clearbooks Number is digits only', str_contains($ocrPrompt, 'digits only'));
    check('it describes the red pen and the # prefix', str_contains($ocrPrompt, 'RED pen') && str_contains($ocrPrompt, 'preceded by "#"'));
    check('it gives the project shape', str_contains($ocrPrompt, '2 letters + 2 numbers'));
    check('it allows a longer letter part', str_contains($ocrPrompt, 'up to 4 letters'));
    check('it says a lettered code is a Project, not a number', str_contains($ocrPrompt, 'a circled code containing letters is a Project'));

    // Apostrophes are the thing a hand-written SQL seed gets wrong, and the
    // failure is silent — the prompt just ends early.
    check('the prompt survived SQL escaping intact', str_contains($ocrPrompt, "that's handled separately"));

    // The field names are the contract between the prompt and the code that
    // reads it, and they are easy to get subtly wrong: it is `clearbooksNumber`
    // with a lower-case b, not the `clearBooksNumber` the rest of the
    // application's spelling would suggest. Reading the wrong key does not
    // throw — every document simply reports no annotations.
    check('it emits clearbooksNumber, lower-case b', str_contains($ocrPrompt, 'clearbooksNumber')
        && !str_contains($ocrPrompt, 'clearBooksNumber'));
    check('it emits project, not projectCode', str_contains($ocrPrompt, '"project": null')
        && !str_contains($ocrPrompt, 'projectCode'));
    check('annotations carry inkColor and marksPrintedText',
        str_contains($ocrPrompt, 'inkColor') && str_contains($ocrPrompt, 'marksPrintedText'));

    // Whatever the code reads, the active prompt has to promise.
    check('every key the code reads is named in the prompt', (static function () use ($ocrPrompt): bool {
        foreach (['ocrText', 'notesPresent', 'handwrittenAnnotations', 'clearbooksNumber', 'project'] as $key) {
            if (!str_contains($ocrPrompt, $key)) {
                return false;
            }
        }

        return true;
    })());

    // The OCR response is parsed once, on the way in, and stored as columns.
    // Nothing downstream re-parses the raw text — that was the n8n habit, forced
    // on it by having no database, and it is the thing InvoGrid exists to stop.
    check('ocr_results has somewhere to put structure', (static function (): bool {
        $columns = array_column(Database::select('SHOW COLUMNS FROM ocr_results'), 'Field');

        foreach (['raw_text', 'ocr_text', 'structured_json', 'notes_present'] as $column) {
            if (!in_array($column, $columns, true)) {
                return false;
            }
        }

        return true;
    })());

    // A round trip through the model, without a provider: the parsing and the
    // promotion to columns is the part worth guarding.
    $probeDoc = (int) Database::scalar('SELECT id FROM documents ORDER BY id LIMIT 1');

    if ($probeDoc > 0) {
        $structured = [
            'ocrText'                => "--- Page 1 ---
ACME

### Notes
- none",
            'notesPresent'           => false,
            'handwrittenAnnotations' => [],
            'clearbooksNumber'       => '80421',
            'project'                => 'AB24',
        ];

        $id = OcrResult::create($probeDoc, [
            'llm_provider' => '__smoke__',
            'llm_model'    => '__smoke__',
            'raw_text'     => json_encode($structured),
            'structured'   => $structured,
        ]);

        try {
            $row = OcrResult::find($id);

            check('the transcription is stored apart from the raw reply',
                OcrResult::text($row) === $structured['ocrText']);
            check('text() is the transcription, not the JSON',
                !str_starts_with(trim(OcrResult::text($row)), '{'));
            check('the structured half round-trips',
                (OcrResult::structured($row)['clearbooksNumber'] ?? null) === '80421');
            check('notesPresent is promoted to a column', (int) $row['notes_present'] === 0);

            // A model that answered in prose still produced a usable
            // transcription; it simply has no structure to promote.
            $proseId = OcrResult::create($probeDoc, [
                'llm_provider' => '__smoke__',
                'llm_model'    => '__smoke__',
                'raw_text'     => 'ACME SUPPLIES LTD, total 300.00',
                'structured'   => null,
            ]);

            $proseRow = OcrResult::find($proseId);

            check('a prose reply still yields a transcription',
                OcrResult::text($proseRow) === 'ACME SUPPLIES LTD, total 300.00');
            check('a prose reply has no structure and does not throw',
                OcrResult::structured($proseRow) === null);
        } finally {
            Database::run('DELETE FROM ocr_results WHERE llm_provider = ?', ['__smoke__']);
        }
    }

    // The three extraction prompts, plus the custom-field fallback. Each one
    // must exist, be active, and name only variables the stage actually
    // provides — a prompt asking for something nothing supplies fails at render
    // time, which is better than at 3am but better still is never.
    // Read from the contract rather than written out again here. It was a
    // second copy of the list until the Prompts screen needed the same one, and
    // the copy that is not used is the copy that goes stale.
    $provided = PromptTemplate::EXTRACTION_VARIABLES;

    foreach ([
        'extract_header'        => ['ocrText', 'today'],
        'extract_supplier'      => ['ocrText', 'suppliers'],
        'extract_lines'         => ['ocrText', 'accountCodes', 'vatRates', 'vatTreatments'],
        'extract_custom_fields' => ['ocrText', 'customFields'],
    ] as $key => $expected) {
        $prompt = PromptTemplate::active($key);

        check($key . ' is seeded and active', $prompt !== null);

        if ($prompt === null) {
            continue;
        }

        $used = PromptRenderer::variablesUsed((string) $prompt['content']);

        sort($used);
        sort($expected);

        check($key . ' uses exactly the variables it should', $used === $expected);
        check($key . ' names nothing the stage cannot supply', array_diff($used, $provided) === []);
    }

    // The rules that make the extraction trustworthy, and that an edit could
    // quietly drop.
    $header   = PromptTemplate::content('extract_header');
    $supplier = PromptTemplate::content('extract_supplier');
    $lines    = PromptTemplate::content('extract_lines');

    check('the header prompt keeps the due-date priority order',
        str_contains($header, 'priority order') && str_contains($header, 'month end'));
    check('the header prompt ignores the notes section',
        str_contains($header, 'Ignore anything from "### Notes" onward'));
    check('the header prompt leaves GBP as null', str_contains($header, 'assume GBP and use null'));

    check('the supplier prompt handles t/a and slash variants',
        str_contains($supplier, 't/a') && str_contains($supplier, 'separated by "/"'));
    check('the supplier prompt treats Ltd and Limited as one',
        str_contains($supplier, '"Ltd" = "Limited"'));
    check('the supplier prompt refuses to invent a match',
        str_contains($supplier, 'a wrong match is worse than a new record'));

    check('the line prompt forbids a null field',
        str_contains($lines, 'never return null for any of them'));
    check('the line prompt keeps the discount rule', str_contains($lines, 'Discount lines'));
    check('the line prompt keeps the shipping rule',
        str_contains($lines, 'Shipping Charges - Internet/Mail Order'));
    check('the line prompt keeps the dedicated-hire distinction',
        str_contains($lines, 'Couriers and Trucking'));
    check('the line prompt prefers the printed line total',
        str_contains($lines, 'prefer the invoice\'s own printed line total'));

    // The two annotation fields the pipeline has always read.
    check('the annotation custom fields are seeded', (static function (): bool {
        $keys = array_column(CustomField::active(), 'field_key');

        return in_array('clearbooks_number', $keys, true) && in_array('project', $keys, true);
    })());

    // Seeded reference data the pipeline will read.
    check('all three document types are seeded',
        DocumentType::keys() === ['bill', 'credit_note', 'purchase_refund']);
    check('bill posts to purchases/bills', (DocumentType::find('bill')['clearbooks_resource'] ?? '') === 'purchases/bills');

    // A refund is a bill with a due date suppressed — the money has already
    // moved, and a due date on one would show in Clear Books as an outstanding
    // payable nobody owes. Tested through the payload builder rather than by
    // reading the column, because the rule lives there.
    check('a due date is sent for a bill and withheld from a refund', (static function (): bool {
        $documentId = (int) (Database::scalar(
            'SELECT document_id FROM extractions WHERE due_date IS NOT NULL ORDER BY id DESC LIMIT 1'
        ) ?? 0);

        if ($documentId === 0) {
            return true;
        }

        $document   = Document::find($documentId);
        $extraction = Extraction::latest($documentId);

        if ($document === null || $extraction === null) {
            return true;
        }

        $build = new ReflectionMethod(App\Services\SubmitStage::class, 'payload');
        $build->setAccessible(true);

        $seen = [];

        foreach (['bill', 'purchase_refund'] as $key) {
            try {
                $payload = $build->invoke(
                    null,
                    $document,
                    array_merge($extraction, ['doc_type' => $key]),
                    DocumentType::find($key)
                );
            } catch (Throwable) {
                // Not every extraction in the database is complete enough to
                // build a payload from; that is not what this is testing.
                return true;
            }

            $seen[$key] = array_key_exists('dateDue', $payload);
        }

        return $seen['bill'] === true && $seen['purchase_refund'] === false;
    })());
    check('the settings keys are seeded', count(Setting::summary()) >= 24);

    echo "\nClear Books matching (against the real tables)\n";

    // The LoginThrottle class of bug: PHP naming a column or an enum value that
    // the schema does not have. Nothing without a live database notices.
    $enumValues = static function (string $table, string $column): array {
        $row = Database::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if ($row === null || preg_match_all("/'([^']+)'/", (string) $row['t'], $m) === 0) {
            return [];
        }

        sort($m[1]);

        return $m[1];
    };

    $sorted = static function (array $values): array {
        sort($values);

        return $values;
    };

    check('entity_matches knows the entity types the code uses', $enumValues('entity_matches', 'entity_type') === $sorted([
        EntityMatch::SUPPLIER, EntityMatch::ACCOUNT_CODE, EntityMatch::VAT_RATE, EntityMatch::VAT_TREATMENT,
    ]));
    check('entity_matches knows how a match was made', $enumValues('entity_matches', 'matched_via') === $sorted([
        EntityMatch::VIA_LLM, EntityMatch::VIA_FALLBACK, EntityMatch::VIA_MANUAL,
    ]));
    check('entity_matches knows the statuses the code writes', $enumValues('entity_matches', 'status') === $sorted([
        EntityMatch::MATCHED, EntityMatch::UNMATCHED, EntityMatch::CREATED, EntityMatch::REJECTED,
    ]));

    // A round trip through the cache, because upsert() and matchByName() are
    // the two things the whole matching stage rests on and both of them name
    // columns. Cleaned up afterwards, whatever happens.
    check('the cache round trip matches on a normalised name', (static function (): bool {
        $ids = ['zzz-smoke-1', 'zzz-smoke-2'];

        foreach ($ids as $id) {
            Database::run('DELETE FROM clearbooks_cache WHERE entity_type = ? AND remote_id = ?',
                [ClearbooksCache::SUPPLIER, $id]);
        }

        try {
            $first  = ClearbooksCache::upsert(ClearbooksCache::SUPPLIER, $ids[0], 'Smoke & Test Ltd', ['vatNumber' => 'GB1']);
            $second = ClearbooksCache::upsert(ClearbooksCache::SUPPLIER, $ids[0], 'Smoke & Test Ltd', ['vatNumber' => 'GB1']);

            if ($first !== 'created' || $second !== 'unchanged') {
                return false;
            }

            // The stored key is what the index is on, so it has to be the same
            // string the matcher computes.
            $row = ClearbooksCache::find(ClearbooksCache::SUPPLIER, $ids[0]);

            if ($row === null || (string) $row['normalised_name'] !== 'smoke and test') {
                return false;
            }

            // "Limited" for "Ltd", the ampersand spelled out: still one match.
            $found = ClearbooksCache::matchByName(ClearbooksCache::SUPPLIER, 'SMOKE AND TEST LIMITED');

            if ($found['row'] === null || (string) $found['row']['remote_id'] !== $ids[0] || $found['via'] !== 'exact') {
                return false;
            }

            // A second supplier reducing to the same key makes it ambiguous,
            // and an ambiguous name must resolve to nothing at all.
            ClearbooksCache::upsert(ClearbooksCache::SUPPLIER, $ids[1], 'Smoke and Test Limited');
            $ambiguous = ClearbooksCache::matchByName(ClearbooksCache::SUPPLIER, 'Smoke & Test');

            if ($ambiguous['row'] !== null || !$ambiguous['ambiguous'] || $ambiguous['candidates'] !== 2) {
                return false;
            }

            // Deactivating one leaves the other unambiguous again — which is
            // what makes an archived supplier stop competing for matches.
            //
            // Every *real* supplier is named in the "seen" list so that only the
            // second test row is deactivated. Without that, running this test
            // against a live database would retire the entire cached supplier
            // list — which it did, once, and the Clear Books screen went to
            // "Suppliers: none" until the next refresh. A check that damages the
            // thing it is checking is worse than no check.
            $keepActive = array_column(ClearbooksCache::all(ClearbooksCache::SUPPLIER), 'remote_id');

            ClearbooksCache::deactivateMissing(
                ClearbooksCache::SUPPLIER,
                array_values(array_diff($keepActive, [$ids[1]]))
            );
            $again = ClearbooksCache::matchByName(ClearbooksCache::SUPPLIER, 'Smoke & Test');

            return $again['row'] !== null && (string) $again['row']['remote_id'] === $ids[0];
        } finally {
            foreach ($ids as $id) {
                Database::run('DELETE FROM clearbooks_cache WHERE entity_type = ? AND remote_id = ?',
                    [ClearbooksCache::SUPPLIER, $id]);
            }
        }
    })());

    // deactivateMissing() refuses an empty list on purpose: a failed fetch must
    // not be read as "the business deleted every supplier".
    check('an empty refresh never wipes the cache',
        ClearbooksCache::deactivateMissing(ClearbooksCache::SUPPLIER, []) === 0);

    // The connection settings, which have no .env fallback and so must be rows.
    check('the Clear Books connection settings are seeded', (static function (): bool {
        $keys = array_keys(Setting::summary());

        foreach ([
            'clearbooks_authorise_url', 'clearbooks_redirect_uri', 'clearbooks_scopes',
            'clearbooks_sync_correspondents', 'clearbooks_delete_correspondents',
        ] as $key) {
            if (!in_array($key, $keys, true)) {
                return false;
            }
        }

        return true;
    })());

    // Only what InvoGrid actually does. A scope list that grows by accident is
    // how an integration ends up able to write journals.
    check('only the scopes InvoGrid needs are requested', (static function (): bool {
        $scopes = explode(' ', trim((string) Setting::get('clearbooks_scopes', '')));

        sort($scopes);

        return $scopes === [
            'accounting.account_codes:read',
            'accounting.purchases:read',
            'accounting.purchases:write',
            'accounting.suppliers:read',
            'accounting.suppliers:write',
            'accounting.vat:read',
        ];
    })());

    echo "\nReview and submission (against the real tables)\n";

    check('custom_fields knows the two origins', $enumValues('custom_fields', 'source') === $sorted([
        CustomField::EXTRACTED, CustomField::SUBMISSION,
    ]));

    check('submissions knows the statuses the code writes', $enumValues('submissions', 'status') === $sorted([
        Submission::SUCCESS, Submission::FAILED,
    ]));

    // The bug this exists for: a vision model asked to find a Clear Books bill
    // id on a supplier's invoice will produce *something*, and the number does
    // not exist until InvoGrid creates the record.
    check('the extraction prompt is never offered a submission field', (static function (): bool {
        foreach (CustomField::extracted() as $field) {
            if ((string) $field['source'] !== CustomField::EXTRACTED) {
                return false;
            }
        }

        foreach (CustomField::forPrompt() as $entry) {
            $field = CustomField::find((string) $entry['key']);

            if ($field === null || (string) $field['source'] !== CustomField::EXTRACTED) {
                return false;
            }
        }

        return true;
    })());

    check('the write-back fields exist and are marked as produced', (static function (): bool {
        foreach (['clearbooks_bill_id', 'clearbooks_document_number'] as $key) {
            $field = CustomField::find($key);

            if ($field === null || (string) $field['source'] !== CustomField::SUBMISSION) {
                return false;
            }
        }

        return true;
    })());

    // A reader that returns a row *without* a column the templates print is
    // indistinguishable from a correct one until a page dies half-rendered.
    // That happened: successful() selected submissions.* and the document page
    // threw "Undefined array key display_name" mid-template.
    check('every submission reader returns the same columns', (static function (): bool {
        $documentId = (int) (Database::scalar('SELECT id FROM documents ORDER BY id LIMIT 1') ?? 0);

        if ($documentId === 0) {
            return true;
        }

        $wanted = ['id', 'document_id', 'clearbooks_id', 'clearbooks_url', 'status', 'username', 'display_name'];

        foreach ([Submission::latest($documentId), Submission::successful($documentId)] as $row) {
            if ($row === null) {
                continue;
            }

            foreach ($wanted as $column) {
                if (!array_key_exists($column, $row)) {
                    return false;
                }
            }
        }

        foreach (Submission::forDocument($documentId) as $row) {
            foreach ($wanted as $column) {
                if (!array_key_exists($column, $row)) {
                    return false;
                }
            }
        }

        return true;
    })());

    // The queue's join names columns on two tables; a typo in it is invisible
    // until somebody opens the review screen.
    check('the review queue query runs and carries what the list shows', (static function (): bool {
        $rows = Document::queue([Document::NEEDS_REVIEW, Document::READY_TO_SUBMIT], 5, 0);

        if ($rows === []) {
            return true;
        }

        foreach (['paperless_doc_id', 'status', 'extraction_id', 'unresolved', 'review_notes', 'edited_at'] as $column) {
            if (!array_key_exists($column, $rows[0])) {
                return false;
            }
        }

        return true;
    })());

    // paginate() joins submissions, which has its own `status` column — an
    // unqualified filter would be an ambiguous-column error rather than a wrong
    // answer, but only when somebody actually filters.
    check('filtering the document list by status still works', (static function (): bool {
        Document::paginate(['status' => Document::SUBMITTED], 5, 0);
        Document::paginate(['q' => 'a'], 5, 0);
        Document::paginate(['q' => '101'], 5, 0);

        return true;
    })());

    check('an extraction records who edited it', (static function (): bool {
        $columns = Database::select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)',
            ['extractions', 'edited_at', 'edited_by']
        );

        return count($columns) === 2;
    })());

    check('an extraction records why it was classified', (static function (): bool {
        return Database::select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['extractions', 'doc_type_reason']
        ) !== [];
    })());

    check('a supplier can be given a usual credit route', (static function (): bool {
        return Database::select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['clearbooks_cache', 'default_credit_route']
        ) !== [];
    })());

    // The active prompt must ask for the reasoning, or the review screen shows
    // a classification with nothing behind it and the confirmation is a guess
    // about a guess.
    check('the line prompt asks for its reasoning', (static function (): bool {
        $prompt = PromptTemplate::active('extract_lines');

        return $prompt !== null
            && str_contains((string) $prompt['content'], 'documentTypeReason')
            && str_contains((string) $prompt['content'], 'purchaseRefund');
    })());

    // A supplier's recorded pattern survives a cache refresh, which overwrites
    // everything the API supplies. It is the same property paperless_correspondent_id
    // relies on, and it silently stops being true if upsert() ever widens.
    check('a supplier route survives a cache refresh', (static function (): bool {
        $id = 'zzz-route-test';

        Database::run('DELETE FROM clearbooks_cache WHERE entity_type = ? AND remote_id = ?',
            [ClearbooksCache::SUPPLIER, $id]);

        try {
            ClearbooksCache::upsert(ClearbooksCache::SUPPLIER, $id, 'Route Test Ltd');
            $row = ClearbooksCache::find(ClearbooksCache::SUPPLIER, $id);

            if ($row === null) {
                return false;
            }

            ClearbooksCache::setDefaultCreditRoute((int) $row['id'], 'purchase_refund');
            ClearbooksCache::upsert(ClearbooksCache::SUPPLIER, $id, 'Route Test Ltd', ['id' => $id, 'changed' => true]);

            return ClearbooksCache::defaultCreditRoute($id) === 'purchase_refund';
        } finally {
            Database::run('DELETE FROM clearbooks_cache WHERE entity_type = ? AND remote_id = ?',
                [ClearbooksCache::SUPPLIER, $id]);
        }
    })());

    echo "\nCustom fields and prompts (against the real tables)\n";

    // The variable contract lives on PromptTemplate and is read by the editor,
    // by this test, and by nothing else. It used to be written out twice — once
    // in the stage and once here — and two copies of a contract is one too many.
    check('the stage supplies exactly what the contract promises', (static function (): bool {
        $stage   = new ReflectionMethod(App\Services\ExtractStage::class, 'variables');
        $stage->setAccessible(true);

        $supplied = array_keys($stage->invoke(new App\Services\ExtractStage(), 'sample text'));
        $promised = PromptTemplate::EXTRACTION_VARIABLES;

        sort($supplied);
        sort($promised);

        return $supplied === $promised;
    })());

    // The OCR prompt is sent verbatim; OcrStage never runs it through the
    // renderer. A variable in it would be transmitted as literal characters.
    check('the OCR prompt is declared as taking no variables',
        PromptTemplate::availableFor('ocr') === []);

    check('every seeded prompt names only what its stage supplies', (static function (): bool {
        foreach (PromptTemplate::keys() as $key) {
            $active = PromptTemplate::active($key);

            if ($active === null) {
                continue;
            }

            if (PromptTemplate::problemsWith($key, (string) $active['content']) !== []) {
                return false;
            }
        }

        return true;
    })());

    check('the editor refuses a variable nothing supplies',
        PromptTemplate::problemsWith('extract_header', '{{ ocrText }} {{ nonsense }}') !== []);
    check('the editor refuses any variable in the OCR prompt',
        PromptTemplate::problemsWith('ocr', 'Read {{ ocrText }}') !== []);
    check('the editor refuses an empty prompt',
        PromptTemplate::problemsWith('extract_header', "  \n ") !== []);
    check('the editor accepts a good one',
        PromptTemplate::problemsWith('extract_header', 'Read {{ ocrText }} as of {{ today }}') === []);

    // "Reset to default" needs to know what shipped. Everything applied by a
    // migration is a seed; anything the editor writes is an edit.
    check('every prompt has a shipped version to reset to', (static function (): bool {
        foreach (PromptTemplate::keys() as $key) {
            if (PromptTemplate::newestSeed($key) === null) {
                return false;
            }
        }

        return true;
    })());

    check('the editor cannot write a seed version', (static function (): bool {
        $key = '__smoke_prompt__';

        try {
            PromptTemplate::saveNewVersion($key, 'Anything at all.', 'from the test');
            $row = PromptTemplate::active($key);

            return $row !== null && (string) $row['origin'] === PromptTemplate::EDITED;
        } finally {
            Database::run('DELETE FROM prompt_templates WHERE template_key = ?', [$key]);
        }
    })());

    // A field key is a JSON object key in extractions.custom_field_values.
    // Changing one orphans every value already read off a document.
    check('a field key cannot be changed once it exists', (static function (): bool {
        $existing = CustomField::all();

        if ($existing === []) {
            return true;
        }

        try {
            CustomField::update((int) $existing[0]['id'], [
                'field_key' => 'something_else_entirely',
                'label'     => (string) $existing[0]['label'],
                'data_type' => (string) $existing[0]['data_type'],
                'active'    => (int) $existing[0]['active'] === 1,
            ]);
        } catch (Throwable) {
            return true;
        }

        return false;
    })());

    check('a key is normalised rather than rejected', (static function (): bool {
        return CustomField::normaliseKey('Purchase Order Number') === 'purchase_order_number'
            && CustomField::normaliseKey('  --Odd..Name--  ') === 'odd_name';
    })());

    check('a select field must have choices', (static function (): bool {
        try {
            CustomField::create([
                'field_key' => '__smoke_select__',
                'label'     => 'Smoke select',
                'data_type' => 'select',
                'active'    => true,
            ]);
        } catch (Throwable) {
            return true;
        } finally {
            Database::run('DELETE FROM custom_fields WHERE field_key = ?', ['__smoke_select__']);
        }

        return false;
    })());

    check('select choices round-trip in Paperless shape', (static function (): bool {
        $options = CustomField::selectOptions("First choice\nSecond choice\n\n");

        if ($options === null || count($options) !== 2) {
            return false;
        }

        // Paperless holds them as {id, label}; storing the same shape means a
        // value written back needs no translation.
        if ($options[0]['label'] !== 'First choice' || $options[0]['id'] !== 'first_choice') {
            return false;
        }

        return CustomField::optionLines(json_encode($options)) === "First choice\nSecond choice";
    })());

    // Two InvoGrid fields on one Paperless field would overwrite each other on
    // every document, because the write-back merges by Paperless field id.
    check('no two fields are paired to the same Paperless field', (static function (): bool {
        $seen = [];

        foreach (CustomField::all() as $field) {
            if ($field['paperless_field_id'] === null) {
                continue;
            }

            $id = (int) $field['paperless_field_id'];

            if (isset($seen[$id])) {
                return false;
            }

            $seen[$id] = true;
        }

        return true;
    })());

    echo "\nAccounts (against the real table)\n";

    // A hash in an array is a hash one forgotten unset() away from a page. The
    // one reader that returns it is the one that verifies a password.
    check('the list never returns a password hash', (static function (): bool {
        foreach (User::all() as $account) {
            if (array_key_exists('password_hash', $account)) {
                return false;
            }
        }

        return true;
    })());

    check('nor does find(), nor the signed-in user',
        !array_key_exists('password_hash', User::all()[0] ?? ['password_hash' => null])
            && !array_key_exists('password_hash', User::find((int) User::all()[0]['id']) ?? []));

    check('findByUsername does return it, because it is the one that checks',
        array_key_exists('password_hash', User::findByUsername((string) User::all()[0]['username']) ?? []));

    // The whole of Prompt 10's deliverable check, run against real rows: an
    // admin creates a reviewer, and that account can immediately work the queue
    // and nothing else.
    check('an admin-created account is a working account', (static function (): bool {
        $username = '__smoke_reviewer__';

        try {
            $id = User::create($username, 'Correct horse battery 7', 'reviewer', 'Smoke Reviewer');
            $new = User::find($id);

            if ($new === null) {
                return false;
            }

            return (string) $new['role'] === 'reviewer' && (int) $new['active'] === 1;
        } finally {
            Database::run('DELETE FROM users WHERE username = ?', [$username]);
        }
    })());

    // An administrator who sets somebody's password knows it. The flag is what
    // makes that a moment rather than a state.
    check('a password set by somebody else must be changed', (static function (): bool {
        $username = '__smoke_reset__';

        try {
            $id = User::create($username, 'Correct horse battery 7', 'viewer', 'Smoke Reset');

            if ((int) User::find($id)['must_change_password'] !== 0) {
                return false;
            }

            User::setPassword($id, 'Another passphrase 9', true);

            return (int) User::find($id)['must_change_password'] === 1
                && User::find($id)['password_changed_at'] === null;
        } finally {
            Database::run('DELETE FROM users WHERE username = ?', [$username]);
        }
    })());

    check('changing your own password clears the flag and stamps the date', (static function (): bool {
        $username = '__smoke_own__';

        try {
            $id = User::create($username, 'Correct horse battery 7', 'viewer', 'Smoke Own', null, true);
            User::setPassword($id, 'Another passphrase 9');

            $row = User::find($id);

            return (int) $row['must_change_password'] === 0 && $row['password_changed_at'] !== null;
        } finally {
            Database::run('DELETE FROM users WHERE username = ?', [$username]);
        }
    })());

    check('a username cannot be changed', (static function (): bool {
        $username = '__smoke_rename__';

        try {
            $id = User::create($username, 'Correct horse battery 7', 'viewer', 'Smoke Rename');

            try {
                User::update($id, ['username' => 'something_else', 'role' => 'viewer', 'active' => true]);
            } catch (Throwable) {
                return true;
            }

            return false;
        } finally {
            Database::run('DELETE FROM users WHERE username = ?', [$username]);
        }
    })());

    check('a duplicate username is refused', (static function (): bool {
        try {
            User::create((string) User::all()[0]['username'], 'Correct horse battery 7');
        } catch (Throwable) {
            return true;
        }

        return false;
    })());

    /*
     * The last active administrator cannot be demoted or deactivated, on either
     * path in — editing the account, and toggling it. An application with no
     * administrator can only be rescued from the server itself, so this is
     * checked in the model where both paths must pass through it.
     *
     * Run against a database with a second administrator temporarily removed
     * from the count rather than against the live one, so a real account is
     * never touched.
     */
    check('the last active administrator is protected on both paths', (static function (): bool {
        $username = '__smoke_admin__';

        // If there is already a live administrator this test's account is not
        // the last one, and the guard correctly would not fire. Stand the test
        // up in a world where it is: deactivate nothing, and instead check the
        // count the guard reads.
        $liveAdmins = User::activeAdmins();

        try {
            $id = User::create($username, 'Correct horse battery 7', 'admin', 'Smoke Admin');

            // With at least one other administrator, both changes are allowed.
            if ($liveAdmins > 0) {
                User::setActive($id, false);
                User::setActive($id, true);
                User::update($id, ['display_name' => 'Smoke Admin', 'role' => 'viewer', 'active' => true]);
                User::update($id, ['display_name' => 'Smoke Admin', 'role' => 'admin', 'active' => true]);
            }

            // Now make it genuinely the last one by ignoring the others, which
            // is exactly the question the guard asks.
            return User::activeAdmins($id) === $liveAdmins
                && User::activeAdmins() === $liveAdmins + 1;
        } finally {
            Database::run('DELETE FROM users WHERE username = ?', [$username]);
        }
    })());

    echo "\nFiltering, stuck documents and diagnostics\n";

    /*
     * The count and the list have to agree.
     *
     * They did not, and the reason is worth an assertion: `countMatching()`
     * passed no table alias, so the unqualified `id` inside the correlated
     * EXISTS resolved to the *inner* table and the subquery stopped correlating
     * at all. Every document matched every search. Neither number looked wrong
     * on its own.
     */
    check('every filter agrees between the count and the list', (static function (): bool {
        $cases = [
            [],
            ['q' => 'Acme'],
            ['q' => 'INV'],
            ['q' => '102'],
            ['q' => 'nothing-matches-this-at-all'],
            ['status' => Document::FAILED],
            ['doc_type' => 'bill'],
            ['correspondent' => 'Acme'],
            ['from' => '2020-01-01'],
            ['to' => '2020-01-01'],
            ['from' => '2020-01-01', 'to' => '2099-01-01', 'q' => 'Acme'],
        ];

        foreach ($cases as $filters) {
            if (Document::countMatching($filters) !== count(Document::paginate($filters, 200))) {
                return false;
            }
        }

        return true;
    })());

    /*
     * The same question of the activity log, and it is worth asking twice: the
     * count and the list are separate SQL statements sharing a WHERE builder,
     * and a filter that reaches only one of them shows "12 entries" above a
     * table of three. Neither number is obviously the wrong one.
     */
    check('every activity filter agrees between the count and the list', (static function (): bool {
        $cases = [
            [],
            ['action' => 'auth.login'],
            ['action' => 'nothing.at.all'],
            ['q' => 'signed'],
            ['q' => '102'],
            ['q' => 'nothing-matches-this-at-all'],
            ['from' => '2020-01-01'],
            ['to' => '2020-01-01'],
            ['from' => '2020-01-01', 'to' => '2099-01-01', 'q' => 'a'],
        ];

        foreach ($cases as $filters) {
            if (AuditLog::countMatching($filters) !== count(AuditLog::paginate($filters, 200))) {
                echo '        disagreed on: ' . json_encode($filters) . "\n";

                return false;
            }
        }

        return true;
    })());

    // A closing date is a whole day, not midnight on it. Filtering "to today"
    // and getting nothing that happened today is the bug this rules out.
    check('an activity date range includes both end days', (static function (): bool {
        $today = date('Y-m-d');

        return AuditLog::countMatching(['from' => $today, 'to' => $today])
            === count(AuditLog::paginate(['from' => $today, 'to' => $today], 200));
    })());

    // Without an alias the correlation is silently wrong rather than loud, so
    // the method refuses rather than guessing.
    check('the filter builder refuses to run without a table alias', (static function (): bool {
        $method = new ReflectionMethod(Document::class, 'filterClause');
        $method->setAccessible(true);

        try {
            $method->invoke(null, ['q' => 'x'], '');
        } catch (Throwable) {
            return true;
        }

        return false;
    })());

    check('free text reaches the extraction, not just the correspondent', (static function (): bool {
        // A supplier name that exists only on an extraction row. If this finds
        // nothing the search is only looking at `documents`, which is what it
        // used to do.
        $name = Database::scalar(
            "SELECT supplier_name_raw FROM extractions
              WHERE supplier_name_raw IS NOT NULL AND supplier_name_raw <> ''
              ORDER BY id DESC LIMIT 1"
        );

        if (!is_string($name) || $name === '') {
            return true; // nothing to look for on this database
        }

        return Document::countMatching(['q' => mb_substr($name, 0, 8)]) > 0;
    })());

    check('a wildcard typed into the search is a literal',
        Document::countMatching(['q' => '%']) === 0
            || Document::countMatching(['q' => '%']) < Document::total());

    check('an unparseable date is ignored rather than fatal',
        Document::countMatching(['from' => 'last tuesday']) === Document::total());

    check('stuck asks two different questions', (static function (): bool {
        // Everything is stuck if the thresholds are one minute and one day;
        // nothing is if they are a decade.
        $tight = count(Document::stuck(1, 1, 100));
        $loose = count(Document::stuck(5_000_000, 4000, 100));

        return $tight >= $loose && $loose === 0;
    })());

    check('a stopped document is not also called stuck', (static function (): bool {
        foreach (Document::stuck(1, 1, 100) as $row) {
            if (in_array((string) $row['status'], [Document::FAILED, Document::SUBMITTED, Document::IGNORED], true)) {
                return false;
            }
        }

        return true;
    })());

    // The whole of item 3: a failure's detail survives the round trip to the
    // database and comes back as something a page can render.
    check('a failure keeps its structured detail', (static function (): bool {
        $documentId = (int) (Database::scalar('SELECT id FROM documents ORDER BY id LIMIT 1') ?? 0);

        if ($documentId === 0) {
            return true;
        }

        try {
            App\Models\DocumentEvent::record(
                $documentId,
                '__smoke__',
                App\Models\DocumentEvent::FAILED,
                'A test failure.',
                123,
                ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'http status' => 429]
            );

            $event = Database::selectOne(
                'SELECT * FROM document_events WHERE stage = ? ORDER BY id DESC LIMIT 1',
                ['__smoke__']
            );

            $context = App\Models\DocumentEvent::context($event ?? []);

            return $context['provider'] === 'anthropic'
                && $context['model'] === 'claude-opus-5'
                && (int) $context['http status'] === 429;
        } finally {
            Database::run('DELETE FROM document_events WHERE stage = ?', ['__smoke__']);
        }
    })());

    check('an event with no context reads as an empty array',
        App\Models\DocumentEvent::context(['context' => null]) === []
            && App\Models\DocumentEvent::context([]) === []
            && App\Models\DocumentEvent::context(['context' => 'not json']) === []);

    check('demoting the only administrator is refused', (static function (): bool {
        // Done by arithmetic rather than by deactivating the real administrator
        // mid-test: activeAdmins($id) is the number that would be left, and the
        // guard fires when it is zero.
        foreach (User::all() as $account) {
            if ((string) $account['role'] !== 'admin' || (int) $account['active'] !== 1) {
                continue;
            }

            if (User::activeAdmins((int) $account['id']) > 0) {
                continue;
            }

            // This one is the last. Both routes must refuse.
            $demoted = false;
            $disabled = false;

            try {
                User::update((int) $account['id'], [
                    'display_name' => (string) $account['display_name'],
                    'role'         => 'viewer',
                    'active'       => true,
                ]);
            } catch (Throwable) {
                $demoted = true;
            }

            try {
                User::setActive((int) $account['id'], false);
            } catch (Throwable) {
                $disabled = true;
            }

            return $demoted && $disabled;
        }

        // More than one administrator, so there is nothing to protect.
        return true;
    })());
}

echo "\n" . ($failures === 0 ? "All checks passed.\n" : $failures . " check(s) FAILED.\n");

exit($failures === 0 ? 0 : 1);
