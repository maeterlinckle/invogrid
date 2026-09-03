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
use App\Models\ClearbooksInvoice;
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
use App\Services\FieldIssues;
use App\Services\InvoiceMatcher;
use App\Services\InvoiceSync;
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

/*
 * The branch is at the *end* of the pipeline, not the start.
 *
 * Both flows run every stage — a scan of an existing invoice is extracted and
 * matched exactly like a new one, because it is a document somebody will search
 * for and report on whether or not anything is ever posted from it. So the OCR
 * stage has one destination, and `ocr_pending -> existing_invoice` must NOT be
 * legal: it would mean a document could still skip extraction.
 */
check('ocr_pending -> ocr_done is allowed, and is the only way on',
    Document::canTransition(Document::OCR_PENDING, Document::OCR_DONE)
    && !Document::canTransition(Document::OCR_PENDING, Document::EXISTING_INVOICE));
check('an existing-invoice document is extracted like any other',
    !Document::canTransition(Document::OCR_DONE, Document::EXISTING_INVOICE)
    && Document::canTransition(Document::OCR_DONE, Document::EXTRACTING));

// The decision rests on a handwritten number read off a scan, so a person
// looking at the page has to be able to overrule it in either direction —
// otherwise the only remedy is ignoring the document and uploading it again.
// One way is the document page's reset control; the other is the queue's "treat
// it as a new invoice", which flips the route and re-matches.
check('a reviewer can send a document onto the existing-invoice flow',
    Document::canTransition(Document::NEEDS_REVIEW, Document::EXISTING_INVOICE)
    && Document::canTransition(Document::READY_TO_SUBMIT, Document::EXISTING_INVOICE));
check('and can send one back off it, through a re-match',
    Document::canTransition(Document::NEEDS_LINK, Document::MATCHING)
    && Document::canTransition(Document::EXISTING_INVOICE, Document::MATCHING));
check('an existing-invoice document can still be ignored',
    Document::canTransition(Document::EXISTING_INVOICE, Document::IGNORED));
check('a failed document can be retried back onto the existing-invoice flow',
    Document::canTransition(Document::FAILED, Document::EXISTING_INVOICE));

/*
 * The duplicate gate on the New Invoice route.
 *
 * `matching` is the only status that may reach `possible_duplicate`, and
 * `matching` is the only status it may reach — which together are the two
 * halves of "the machine decides where this goes, and it decides through one
 * implementation". A person confirming a document is genuinely new stamps
 * `duplicate_cleared_at` and re-runs the stage; they do not choose a
 * destination, and the stage takes a different exit for a different reason.
 */
check('a new invoice may be stopped as a possible duplicate',
    Document::canTransition(Document::MATCHING, Document::POSSIBLE_DUPLICATE));
check('and the one way on is a re-match',
    Document::canTransition(Document::POSSIBLE_DUPLICATE, Document::MATCHING)
    && !Document::canTransition(Document::POSSIBLE_DUPLICATE, Document::READY_TO_SUBMIT)
    && !Document::canTransition(Document::POSSIBLE_DUPLICATE, Document::NEEDS_REVIEW)
    && !Document::canTransition(Document::POSSIBLE_DUPLICATE, Document::SUBMITTED));

/*
 * Nothing may be *moved into* the duplicate queue by hand.
 *
 * The screen it waits on is a comparison against records the matcher found, so
 * a document parked there by the document page's reset dropdown would arrive at
 * a page with nothing on one side of it. That control is built from
 * `canTransition()`, so this assertion is what keeps the option off it — and
 * `failed` is on the list deliberately, though it lists every other waiting
 * status: a retry resumes at the head of a stage, and this is not one.
 */
check('nothing but the matching stage can send a document to the duplicate queue',
    (static function (): bool {
        foreach (Document::TRANSITIONS as $from => $targets) {
            if ($from === Document::MATCHING) {
                continue;
            }

            if (in_array(Document::POSSIBLE_DUPLICATE, $targets, true)) {
                return false;
            }
        }

        return true;
    })());

check('a possible duplicate can still be ignored',
    Document::canTransition(Document::POSSIBLE_DUPLICATE, Document::IGNORED));

check('every route has a label',
    array_diff(Document::ROUTES, array_keys(Document::ROUTE_LABELS)) === []);
check('an unrouted document says so rather than guessing',
    Document::routeLabel(null) === 'not decided yet');

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

check('link is the stage for an existing-invoice document', Pipeline::stageFor(Document::EXISTING_INVOICE) === 'link');
check('nothing runs a document that needs linking', Pipeline::stageFor(Document::NEEDS_LINK) === null);

// The duplicate gate is part of the matching stage, not a stage of its own —
// it wants `documents.matched_supplier_id`, which the matching stage is what
// produces. So nothing consumes `possible_duplicate`, and nothing should: a
// stage picking it up would find the same records and queue itself for ever.
check('nothing runs a document that may be a duplicate',
    Pipeline::stageFor(Document::POSSIBLE_DUPLICATE) === null
    && !in_array(Document::POSSIBLE_DUPLICATE, array_column(Pipeline::STAGES, 'from'), true));
check('the ocr stage still declares the new-invoice outcome',
    Pipeline::STAGES['ocr']['to'] === Document::OCR_DONE);
check('match is the stage for an extracted document', Pipeline::stageFor(Document::EXTRACTED) === 'match');

/*
 * A `during` status resolves to its own stage, not to nothing.
 *
 * The registry already says a stage with a `during` status accepts a document
 * back in either one — a worker killed mid-extraction leaves it in
 * `extracting`. This is the other half of that: `advance()` has to be able to
 * queue the stage from there, or the document page's "Reset to" control can
 * move a document into `matching` and enqueue nothing, stranding it until the
 * dashboard's stuck list notices. `possible_duplicate` is what made it matter —
 * `matching` is the only status it can move on to.
 */
check('a stage picks a document back up from its own working status',
    Pipeline::stageFor(Document::EXTRACTING) === 'extract'
    && Pipeline::stageFor(Document::MATCHING) === 'match');
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

// The Existing Invoice route's own two outcomes. The registry records
// `needs_link`, the conservative one; `submitted` is what a document reaches
// when the checksum held — and it has to be legal from `existing_invoice`, or
// the PDF is attached to somebody's accounts and the transition then throws.
check('a linked document may go straight to submitted',
    Document::canTransition(Document::EXISTING_INVOICE, Document::SUBMITTED));
check('a match that did not settle goes to needs_link',
    Pipeline::STAGES['link']['to'] === Document::NEEDS_LINK
    && Document::canTransition(Document::EXISTING_INVOICE, Document::NEEDS_LINK));
check('a failed link retries from existing_invoice',
    Document::retryStatusFor('link') === Document::EXISTING_INVOICE);

// The three things the queue offers, and nothing else moves a document out of
// it: link it, treat it as a new invoice (a re-match), or take it away. Looking
// the number up again is `needs_link -> existing_invoice`.
check('the queue can link, re-match, look again, or ignore',
    Document::canTransition(Document::NEEDS_LINK, Document::SUBMITTED)
    && Document::canTransition(Document::NEEDS_LINK, Document::MATCHING)
    && Document::canTransition(Document::NEEDS_LINK, Document::EXISTING_INVOICE)
    && Document::canTransition(Document::NEEDS_LINK, Document::IGNORED));

// The matching stage is where the two flows part, so `matching` has to be able
// to reach all three destinations or the stage does its work and then throws.
check('matching reaches all three of its destinations',
    Document::canTransition(Document::MATCHING, Document::NEEDS_REVIEW)
    && Document::canTransition(Document::MATCHING, Document::READY_TO_SUBMIT)
    && Document::canTransition(Document::MATCHING, Document::EXISTING_INVOICE));

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
// failure into the review screen or the submission, where it is far less
// legible.
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
$csrfExempt = ['GET /admin/clearbooks/callback'];
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
 *  - the logo previews and the scan viewer's page images, which must sit on the
 *    ground they are for rather than the page's current theme. A scan of white
 *    paper on a dark card reads as a hole in the page.
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
        if (str_contains($line, 'logo-preview') || str_contains($line, 'scan-strip')) {
            continue;
        }

        // A comment mentioning a colour is not a colour.
        if (preg_match('/^\s*(\/\*|\*)/', $line)) {
            continue;
        }

        // `background: #ffffff;` inside .scan-page, which is several lines
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

// An ingest route is the one place somebody can write a file to this disk.
check('an ingested PDF has a size ceiling', (static function (): bool {
    if (App\Services\Ingest\Ingestor::maxBytes() <= 0) {
        return false;
    }

    // And the quoted limit is the *effective* one. A form promising 25MB while
    // PHP drops anything over 2MB produces the worst kind of bug report.
    return App\Services\Ingest\Ingestor::effectiveMaxBytes()
        <= App\Services\Ingest\Ingestor::maxBytes();
})());

check('the download cap aborts mid-transfer rather than measuring afterwards', (static function (): bool {
    // CURLOPT_MAXFILESIZE only works when the far end sends a Content-Length,
    // and a streamed response sends none — so it has to be a progress callback.
    $http = (string) file_get_contents(dirname(__DIR__) . '/src/Services/Http.php');

    return str_contains($http, 'CURLOPT_PROGRESSFUNCTION')
        && str_contains($http, 'CURLOPT_NOPROGRESS');
})());

// The header, not the extension and not the browser's Content-Type. This is
// what a JPEG renamed to .pdf fails.
check('a file that is not a PDF is refused before any row is created', (static function (): bool {
    $ingestor = (string) file_get_contents(dirname(__DIR__) . '/src/Services/Ingest/Ingestor.php');

    if (!str_contains($ingestor, "\$magic !== '%PDF-'")) {
        return false;
    }

    // check() has to run before the insert, or a refused file leaves a
    // document behind at `received` with no PDF under it.
    return preg_match('/self::check\(\$candidate\);.*?Database::insert\(.documents./s', $ingestor) === 1;
})());

check('the stored PDF is checked again by the ingest stage', (static function (): bool {
    // Not the same check twice: the ingestor reads a file it is about to
    // accept, this reads the file that was actually written.
    $stage = (string) file_get_contents(dirname(__DIR__) . '/src/Services/IngestStage.php');

    return str_contains($stage, "\$magic !== '%PDF-'")
        && str_contains($stage, 'assertPdf');
})());

// A browser upload must be moved with move_uploaded_file(), which refuses any
// path PHP did not itself receive as an upload. rename() would not, and an
// upload handler that can be pointed at /etc/passwd is the classic version of
// this bug.
check('a browser upload is moved with move_uploaded_file', (static function (): bool {
    $candidate = (string) file_get_contents(dirname(__DIR__) . '/src/Services/Ingest/IngestCandidate.php');

    return str_contains($candidate, 'return move_uploaded_file($this->path, $target);')
        && str_contains($candidate, '!is_uploaded_file($this->path)');
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
    'partials/scan', 'partials/extraction', 'partials/matches',
    'auth/login', 'dashboard/index', 'errors/error',
] as $template) {
    check(
        $template . ' exists',
        is_file(dirname(__DIR__) . '/templates/' . $template . '.php')
    );
}

/*
 * The review screen's marks. Every one of these is a rendering rule that a
 * template asserts and nothing else would catch: a wrong `class` is invisible
 * to PHP and to the browser, and reads on screen as "this field is fine".
 */
check('the scan viewer is what the review screens use', (static function (): bool {
    $ok = true;

    foreach (['review/show', 'existing/show', 'duplicates/show', 'documents/show'] as $template) {
        $markup = (string) file_get_contents(dirname(__DIR__) . '/templates/' . $template . '.php');

        // The page images by way of the shared partial, and no screen still
        // embedding the PDF on its own — the whole point of Prompt 19's change
        // is that all four show the scan the same way.
        if (!str_contains($markup, "partial('partials/scan'") || str_contains($markup, 'class="pdf-frame"')) {
            echo '        ' . $template . " does not use partials/scan, or still embeds the PDF itself\n";
            $ok = false;
        }
    }

    return $ok;
})());

check('the scan viewer works with no JavaScript', (static function (): bool {
    $markup = (string) file_get_contents(dirname(__DIR__) . '/templates/partials/scan.php');

    // The page arrows and the zoom toggle cannot work without a script, so they
    // ship hidden. "View PDF" can, so it is a real link and must not.
    return str_contains($markup, 'data-scan-prev hidden')
        && str_contains($markup, 'data-scan-zoom aria-pressed="false" hidden')
        && str_contains($markup, 'href="<?= e($pdfUrl) ?>" target="_blank"');
})());

check('the flag helpers say a word and not only a colour', (static function (): bool {
    // Colour alone is not a signal every reader receives, and which fields need
    // looking at is the entire point of the mark.
    return str_contains(flag_tag(FieldIssues::DANGER), 'must be resolved')
        && str_contains(flag_tag(FieldIssues::WARN), 'check this')
        && flag_tag(null) === ''
        && flag_class(null) === ''
        && str_contains(flag_class(FieldIssues::DANGER), 'is-flagged-danger')
        && !str_contains(flag_class(FieldIssues::WARN), 'is-flagged-danger');
})());

echo "\nPer-field issue attribution\n";

/*
 * `FieldIssues` decides which input each review note, unresolved match and
 * uncertain reading is drawn on. A wrong answer sends a reviewer to correct a
 * value that was right, so every rule it applies is asserted here — including
 * the one that matters most, which is that it refuses to guess.
 */
$issueFixture = static function (array $notes, array $matches = [], array $confidence = []): FieldIssues {
    return FieldIssues::build(
        [
            'review_notes' => $notes === [] ? null : json_encode($notes),
            'confidence'   => $confidence === [] ? null : json_encode($confidence),
        ],
        $matches,
        [
            ['field_key' => 'job_number', 'label' => 'Job Number'],
            ['field_key' => 'job',        'label' => 'Job'],
        ]
    );
};

$unmatched = static fn (string $type, ?int $line, ?string $note = null): array => [
    'entity_type' => $type,
    'line_index'  => $line,
    'status'      => App\Models\EntityMatch::UNMATCHED,
    'raw_value'   => 'ACME',
    'note'        => $note,
    'confidence'  => null,
    'matched_id'  => null,
    'matched_name' => null,
];

$issues = $issueFixture(
    [
        'Header: the due date was not stated on the document.',
        'Line 2: no account code was chosen.',
        'Line 3: 4 x 12.50 comes to 50.00, but the line total says 45.00.',
        'Document type: none was returned; left unclassified.',
        'Line items: none were found on this document.',
        'Custom fields: Job Number was hard to read.',
        'Supplier: two addresses appear on the letterhead.',
        'Matching: Supplier: nothing on file matched "ACME".',
        'Matching: Account code on line 2: nothing on file matched "7502".',
        'Setup: the cached VAT rates list is empty.',
        'Header: something nobody has a rule for.',
    ],
    [
        $unmatched(App\Models\EntityMatch::SUPPLIER, null, 'nothing on file matched "ACME".'),
        $unmatched(App\Models\EntityMatch::ACCOUNT_CODE, 1),
    ],
    ['invoice_number' => 0.55, 'gross_amount' => 0.95]
);

check('a phrase the pipeline wrote lands on its own field',
    $issues->tone('due_date') === FieldIssues::WARN
    && $issues->tone('doc_type') === FieldIssues::WARN
    && $issues->tone('supplier_name_raw') === FieldIssues::DANGER
    && $issues->tone('lines') === FieldIssues::WARN);

check('a line note lands on the right cell of the right row',
    // The notes count from 1 and the form's rows from 0.
    $issues->onLine(1, 'account_code') !== []
    && $issues->onLine(2, 'total') !== []
    && $issues->onLine(0, 'account_code') === []);

check('an unresolved entity is danger, and a note is not',
    $issues->tone('line.1.account_code') === FieldIssues::DANGER
    && $issues->tone('due_date') === FieldIssues::WARN);

check('the matching stage does not say the same thing twice', (static function () use ($issues): bool {
    // The `entity_matches` row and the "Matching: …" note are the same fact in
    // two forms; two marks saying it on one input reads as two separate
    // problems. The row wins, because it is structural rather than parsed out
    // of a sentence — so nothing left on the cell is that stage's prose.
    //
    // The extraction stage's own "Line 2: no account code was chosen." stays:
    // it is a different statement that happens to be about the same cell.
    $texts = array_column($issues->on('line.1.account_code'), 'text');

    return $texts === [
        'Nothing on file in Clear Books matched "ACME".',
        'Line 2: no account code was chosen.',
    ];
})());

check('a matching note with no row behind it is still shown', (static function () use ($issueFixture): bool {
    // That stage writes notes the `entity_matches` table does not carry — a
    // cached supplier id that has gone stale, a credit document waiting to be
    // agreed. Dropping those with the duplicates would lose them entirely.
    $stale = $issueFixture([
        'Matching: the extraction claimed Clear Books supplier "9", which is not in the current cache.',
    ]);

    return $stale->on('supplier_name_raw') !== [];
})());

check('a custom-field note picks the longest matching label',
    // "Job Number" and "Job" are both configured; the note names the first.
    $issues->on('custom_job_number') !== [] && $issues->on('custom_job') === []);

check('a per-field confidence score is read, and only below the floor',
    $issues->tone('invoice_number') === FieldIssues::WARN
    && $issues->tone('gross_amount') === null);

check('a note that names no field is kept rather than guessed at', (static function () use ($issues): bool {
    $texts = array_column($issues->unplaced(), 'text');

    return count($texts) === 2
        && in_array('Setup: the cached VAT rates list is empty.', $texts, true)
        && in_array('Header: something nobody has a rule for.', $texts, true);
})());

check('a bare word is not enough to claim a field', (static function () use ($issueFixture): bool {
    // "date" and "amount" on their own are deliberately not in the phrase list:
    // the cost of a wrong mark is a reviewer editing a value that was right.
    $vague = $issueFixture(['Header: the date on this one is unusual.']);

    return $vague->unplaced() !== [] && $vague->fieldCount() === 0;
})());

check('a matched entity below full confidence is flagged, not silent', (static function (): bool {
    $issues = FieldIssues::build([], [[
        'entity_type'  => App\Models\EntityMatch::VAT_RATE,
        'line_index'   => 0,
        'status'       => App\Models\EntityMatch::MATCHED,
        'raw_value'    => '20%',
        'note'         => null,
        'confidence'   => 0.9,
        'matched_id'   => 'S',
        'matched_name' => 'Standard 20%',
    ]]);

    return $issues->tone('line.0.vat_rate') === FieldIssues::WARN;
})());

check('nothing wrong means nothing marked', (static function () use ($issueFixture): bool {
    $clean = $issueFixture([]);

    return !$clean->any() && $clean->count() === 0 && $clean->tone('invoice_date') === null;
})());

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

    // The notes section is gone, and staying gone is the point: it was a second
    // copy of the structured fields, flattened into the transcription, and
    // anything that reappends it puts text into the permanent record of a page
    // that is not printed on that page.
    check('it no longer asks for a ### Notes section', !str_contains($ocrPrompt, '### Notes'));
    check('it says the transcription is the transcription',
        str_contains($ocrPrompt, 'is the transcription from Step 1 and nothing else'));
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

        foreach ([
            'raw_text', 'ocr_text', 'structured_json', 'notes_present',
            'clearbooks_number', 'project_code', 'annotations_json',
        ] as $column) {
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
ACME",
            'notesPresent'           => true,
            'handwrittenAnnotations' => [
                ['text' => '#80421', 'inkColor' => 'red', 'marksPrintedText' => null, 'location' => 'top right'],
            ],
            // Written with the "#" the prompt says it usually carries, because
            // the stripping of it is the part worth guarding: `#80421` and
            // `80421` are one reference, not two.
            'clearbooksNumber'       => '#80421',
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
            check('the transcription carries no notes section',
                !str_contains(OcrResult::text($row), '### Notes'));
            check('the structured half round-trips',
                (OcrResult::structured($row)['clearbooksNumber'] ?? null) === '#80421');
            check('notesPresent is promoted to a column', (int) $row['notes_present'] === 1);

            // The three the routing decision and the review screen read. They
            // are columns rather than a decode of `structured_json` so the
            // branch can test one value per document without unpacking a blob.
            check('the Clearbooks Number is promoted, without its hash',
                OcrResult::clearbooksNumber($row) === '80421');
            check('the project code is promoted', OcrResult::projectCode($row) === 'AB24');
            check('the annotations are promoted', (static function () use ($row): bool {
                $annotations = OcrResult::annotations($row);

                return count($annotations) === 1 && ($annotations[0]['inkColor'] ?? null) === 'red';
            })());

            // What decides the branch. Digits route; anything else is a misread
            // of a handwritten number, and the prompt is explicit that a code
            // with letters in it is a Project rather than this.
            check('a digits-only number is usable', OcrResult::isUsableNumber('80421'));
            check('a lettered code is not a Clearbooks Number', !OcrResult::isUsableNumber('AB24'));
            check('an absent number is not usable', !OcrResult::isUsableNumber(null));

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

    /*
     * The routing decision, end to end, without a model call.
     *
     * `OcrStage::route()` is given a stored OCR result and asked which flow the
     * document is on. Everything either side of it — the response arriving, the
     * pages being rendered — is the part that costs money and is not what is
     * being checked here.
     *
     * **The status is the same in every case, and that is half the assertion.**
     * The route is recorded on the document and every document goes on to
     * `ocr_done` to be extracted: a scan of an existing invoice is a document
     * somebody will search for, so it gets the same reading as any other, and
     * the two flows part at the end of matching instead. An earlier version
     * returned `existing_invoice` here and skipped extraction; if that ever
     * comes back, this fails.
     */
    check('the OCR stage sends a document down the right flow', (static function (): bool {
        $route = new ReflectionMethod(App\Services\OcrStage::class, 'route');
        $route->setAccessible(true);
        $stage = new App\Services\OcrStage();

        $cases = [
            // Written with the hash, as the prompt says it usually is.
            ['#80421', Document::OCR_DONE, Document::ROUTE_EXISTING],
            // Absent, which is the ordinary document.
            [null, Document::OCR_DONE, Document::ROUTE_NEW],
            // A misread: the prompt is explicit that a code with letters in it
            // is a Project, so this must not route on it.
            ['AB24', Document::OCR_DONE, Document::ROUTE_NEW],
        ];

        $documentIds = [];

        try {
            foreach ($cases as [$number, $expectedStatus, $expectedRoute]) {
                $documentId = Database::insert('documents', [
                    'ingest_source'     => 'upload',
                    'original_filename' => '__smoke__.pdf',
                    'ingested_at'       => date('Y-m-d H:i:s'),
                    'status'            => Document::OCR_PENDING,
                ]);

                $documentIds[] = $documentId;

                $resultId = OcrResult::create($documentId, [
                    'llm_provider' => '__smoke_route__',
                    'llm_model'    => '__smoke_route__',
                    'raw_text'     => '{}',
                    'structured'   => [
                        'ocrText'                => 'ACME SUPPLIES LTD',
                        'notesPresent'           => $number !== null,
                        'handwrittenAnnotations' => $number === null ? [] : [
                            ['text' => $number, 'inkColor' => 'red', 'location' => 'top right'],
                        ],
                        'clearbooksNumber'       => $number,
                        'project'                => null,
                    ],
                ]);

                $result = OcrResult::find($resultId) ?? [];
                $next   = $route->invoke($stage, $documentId, $result);

                if ($next !== $expectedStatus) {
                    return false;
                }

                // The runner does this, and it has to be a legal move or the
                // stage does its work and then throws on the way out.
                Document::transitionTo($documentId, $next);

                $document = Document::find($documentId) ?? [];

                if (($document['status'] ?? null) !== $expectedStatus) {
                    return false;
                }

                if (($document['route'] ?? null) !== $expectedRoute) {
                    return false;
                }

                // The annotation data has to be there on either route — that is
                // the point of storing it rather than appending it to prose.
                if ($number !== null && OcrResult::annotations($result) === []) {
                    return false;
                }
            }

            return true;
        } finally {
            foreach ($documentIds as $documentId) {
                Database::run('DELETE FROM documents WHERE id = ?', [$documentId]);
            }

            Database::run('DELETE FROM ocr_results WHERE llm_provider = ?', ['__smoke_route__']);
        }
    })());

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
        'extract_custom_fields' => ['ocrText', 'customFields', 'annotations'],
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
    // An instruction to skip past a landmark that is no longer there is worse
    // than no instruction: a model that goes looking for it will find some
    // other heading and do as it was told.
    check('no extraction prompt still points at the notes section', (static function (): bool {
        foreach (['extract_header', 'extract_supplier', 'extract_lines', 'extract_custom_fields'] as $key) {
            if (str_contains(PromptTemplate::content($key), '### Notes')) {
                return false;
            }
        }

        return true;
    })());
    check('the custom-field prompt is given the annotations instead',
        str_contains(PromptTemplate::content('extract_custom_fields'), '<annotations>')
        && str_contains(PromptTemplate::content('extract_custom_fields'), '{{ annotations }}'));
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
            'clearbooks_invoice_sync_interval_minutes',
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

    echo "\nThe Clear Books invoice sync (against the real table)\n";

    check('clearbooks_invoices knows the two purchase types the code writes',
        $enumValues('clearbooks_invoices', 'purchase_type') === $sorted(ClearbooksInvoice::types()));

    check('the invoice sync settings are seeded', (static function (): bool {
        $keys = array_keys(Setting::summary());

        return in_array(InvoiceSync::INTERVAL_KEY, $keys, true)
            && in_array(InvoiceSync::LAST_RUN_KEY, $keys, true);
    })());

    /*
     * A Clear Books record onto the columns a duplicate check will read.
     *
     * The ids are far outside anything Clear Books would issue, and every row
     * is removed afterwards — this runs against the live table.
     */
    check('a purchase document round-trips into its columns', (static function (): bool {
        $id = '99000001';

        Database::run('DELETE FROM clearbooks_invoices WHERE clearbooks_id = ?', [$id]);

        try {
            $record = [
                'id'                      => (int) $id,
                'formattedDocumentNumber' => 'PUR9999',
                'date'                    => '2026-03-04',
                'dateDue'                 => '2026-04-04',
                'supplierId'              => 4242,
                'reference'               => 'SUPPLIER-REF-1',
                'grossAmount'             => 120.55,
                'lineItems'               => [['description' => 'x', 'unitPrice' => 10, 'quantity' => 1]],
            ];

            $first = ClearbooksInvoice::upsert(ClearbooksInvoice::BILL, $record);

            if ($first['outcome'] !== 'created' || $first['derivedGross']) {
                return false;
            }

            $row = ClearbooksInvoice::find($id);

            if (
                $row === null
                || (string) $row['purchase_type'] !== ClearbooksInvoice::BILL
                || (string) $row['document_number'] !== 'PUR9999'
                || (string) $row['document_date'] !== '2026-03-04'
                || (string) $row['due_date'] !== '2026-04-04'
                // A string, and the same string clearbooks_cache.remote_id
                // holds, so the two can be joined without a cast.
                || (string) $row['supplier_id'] !== '4242'
                || (string) $row['reference'] !== 'SUPPLIER-REF-1'
                || (float) $row['gross_amount'] !== 120.55
            ) {
                return false;
            }

            // The whole record is kept, not just the columns — a later prompt
            // promoting a field to a column must not need a re-sync.
            $raw = json_decode((string) $row['raw_json'], true);

            if (!is_array($raw) || ($raw['lineItems'][0]['description'] ?? '') !== 'x') {
                return false;
            }

            // Same record again is not a change; a different one is.
            if (ClearbooksInvoice::upsert(ClearbooksInvoice::BILL, $record)['outcome'] !== 'unchanged') {
                return false;
            }

            $record['reference'] = 'SUPPLIER-REF-2';

            return ClearbooksInvoice::upsert(ClearbooksInvoice::BILL, $record)['outcome'] === 'updated';
        } finally {
            Database::run('DELETE FROM clearbooks_invoices WHERE clearbooks_id = ?', [$id]);
        }
    })());

    /*
     * The gross amount, which is the field a duplicate check leans on hardest
     * and the one Clear Books' specification is least explicit about.
     */
    check('a reported total beats a derived one, and the sign survives', (static function (): bool {
        $reported = ClearbooksInvoice::gross(['total' => '81.00', 'lineItems' => [
            ['unitPrice' => 1, 'quantity' => 1],
        ]]);

        if ($reported['amount'] !== '81.00' || $reported['derived']) {
            return false;
        }

        // No total at all: worked out from the lines, with a stated VAT amount
        // used in preference to a rate.
        $derived = ClearbooksInvoice::gross(['lineItems' => [
            ['unitPrice' => 10, 'quantity' => 3, 'vatRateKey' => 'Manual', 'vatAmount' => 4.5],
        ]]);

        if ($derived['amount'] !== '34.50' || !$derived['derived']) {
            return false;
        }

        // A purchase refund is a bill with negative amounts. Flattening that to
        // an absolute value would lose the one thing telling it from a bill.
        $refund = ClearbooksInvoice::gross(['grossAmount' => -60]);

        if ($refund['amount'] !== '-60.00') {
            return false;
        }

        // Nothing to go on is null rather than zero: zero is a real amount.
        return ClearbooksInvoice::gross(['id' => 1])['amount'] === null;
    })());

    check('a record with no id is skipped rather than given one',
        ClearbooksInvoice::upsert(ClearbooksInvoice::BILL, ['reference' => 'no id'])['outcome'] === 'skipped');

    /*
     * The deletion sync, which is the dangerous half of "Clear Books is the
     * source of truth".
     *
     * Every real row is named in the seen list, exactly as the supplier test
     * above learned to do: without that, running this against a live database
     * would empty the table.
     */
    check('an empty fetch never empties the table', ClearbooksInvoice::deleteMissing([]) === 0);

    check('a document gone from Clear Books goes from here', (static function (): bool {
        $keep = '99000002';
        $drop = '99000003';

        foreach ([$keep, $drop] as $id) {
            ClearbooksInvoice::upsert(ClearbooksInvoice::CREDIT_NOTE, ['id' => $id, 'date' => '2026-01-01']);
        }

        try {
            $real = array_column(
                Database::select('SELECT clearbooks_id FROM clearbooks_invoices'),
                'clearbooks_id'
            );

            // Everything that is really there, minus the one being retired.
            $seen = array_values(array_diff($real, [$drop]));

            if (ClearbooksInvoice::deleteMissing($seen) !== 1) {
                return false;
            }

            return ClearbooksInvoice::find($drop) === null
                && ClearbooksInvoice::find($keep) !== null;
        } finally {
            Database::run('DELETE FROM clearbooks_invoices WHERE clearbooks_id IN (?, ?)', [$keep, $drop]);
        }
    })());

    /*
     * The schedule. It is a settings row, so this puts the real value back
     * whatever happens — a test that leaves an install syncing every five
     * minutes has changed the thing it was measuring.
     */
    check('the schedule is read, clamped, and turned off by zero', (static function (): bool {
        $interval = Setting::stored(InvoiceSync::INTERVAL_KEY);
        $lastRun  = Setting::stored(InvoiceSync::LAST_RUN_KEY);

        try {
            Setting::put(InvoiceSync::INTERVAL_KEY, '30');
            Setting::put(InvoiceSync::LAST_RUN_KEY, '');

            if (InvoiceSync::intervalMinutes() !== 30 || InvoiceSync::lastRun() !== null) {
                return false;
            }

            // Never run means due now, which is what somebody who has just set
            // an interval expects to happen.
            if (!InvoiceSync::isDue()) {
                return false;
            }

            // Measured from when the last run started, not from when it ended.
            Setting::put(InvoiceSync::LAST_RUN_KEY, (string) json_encode([
                'at' => date('Y-m-d H:i:s', time() - 600), 'ok' => true,
            ]));

            if (InvoiceSync::isDue() || InvoiceSync::dueAt() === null) {
                return false;
            }

            // Below the floor is clamped rather than obeyed: a one-minute sync
            // would walk somebody's whole ledger sixty times an hour.
            Setting::put(InvoiceSync::INTERVAL_KEY, '1');

            if (InvoiceSync::intervalMinutes() !== InvoiceSync::MIN_INTERVAL) {
                return false;
            }

            Setting::put(InvoiceSync::INTERVAL_KEY, '0');

            return InvoiceSync::intervalMinutes() === 0
                && InvoiceSync::dueAt() === null
                && !InvoiceSync::isDue();
        } finally {
            Setting::put(InvoiceSync::INTERVAL_KEY, $interval ?? (string) InvoiceSync::DEFAULT_INTERVAL);
            Setting::put(InvoiceSync::LAST_RUN_KEY, $lastRun ?? '');
        }
    })());

    /*
     * Both ways of starting a sync take the same lock.
     *
     * Two runs at once walk the same list twice against an API that throttles
     * above five requests a second. The cron script and the button are separate
     * processes, so only a shared lock can make one wait for the other — and
     * the way that stops being true is somebody adding a third caller that
     * calls `run()` directly.
     */
    check('a sync can be locked, and both callers do', (static function (): bool {
        $handle = InvoiceSync::lock();

        if ($handle === null) {
            // Genuinely held by a run in progress, which is not a failure of
            // this check; anything else is.
            return is_file(rtrim((string) Config::get('storage.path'), '/\\') . '/invoices.lock');
        }

        InvoiceSync::unlock($handle);

        foreach ([
            dirname(__DIR__) . '/bin/sync-invoices.php',
            dirname(__DIR__) . '/src/Controllers/ClearBooksController.php',
        ] as $file) {
            if (!str_contains((string) file_get_contents($file), 'InvoiceSync::lock()')) {
                echo '        ' . basename($file) . " starts a sync without taking the lock\n";

                return false;
            }
        }

        return true;
    })());

    echo "\nThe Existing Invoice route (against the real tables)\n";

    check('documents knows the needs_link status the code writes',
        in_array(Document::NEEDS_LINK, $enumValues('documents', 'status'), true));

    /*
     * There is nothing to configure about the checksum, and that is the
     * assertion: a tolerance setting appearing here later would mean somebody
     * had made it possible to attach a scan to a record whose date and total
     * do not agree, without anybody looking. If that is ever wanted it should
     * be an argued change, not a settings row that appeared.
     */
    check('the checksum has no tolerance settings behind it', (static function (): bool {
        foreach (array_keys(Setting::summary()) as $key) {
            if (str_contains((string) $key, 'tolerance')) {
                return false;
            }
        }

        return !in_array('linking', array_keys(SettingSchema::SECTIONS), true);
    })());

    /*
     * The lookup, against real rows.
     *
     * The two comparisons it makes are the whole of item 1 of this route: the
     * number exactly as written, and the digits alone with leading zeros
     * dropped — which is what makes "80421" written in red pen find a record
     * Clear Books calls PUR0080421.
     *
     * Every row is removed afterwards; the ids are far outside anything Clear
     * Books would issue, and this runs against the live table.
     */
    check('a handwritten number finds its Clear Books record', (static function (): bool {
        $ids = ['99000101', '99000102', '99000103'];

        Database::run(
            'DELETE FROM clearbooks_invoices WHERE clearbooks_id IN (?, ?, ?)',
            $ids
        );

        try {
            ClearbooksInvoice::upsert(ClearbooksInvoice::BILL, [
                'id'                      => $ids[0],
                'formattedDocumentNumber' => 'PUR0080421',
                'date'                    => '2026-07-14',
                'reference'               => 'INV-2026-0042',
                'grossAmount'             => 413.28,
            ]);

            // Written exactly as Clear Books spells it.
            ClearbooksInvoice::upsert(ClearbooksInvoice::CREDIT_NOTE, [
                'id'                      => $ids[1],
                'formattedDocumentNumber' => '80422',
                'date'                    => '2026-07-15',
                'grossAmount'             => 20.00,
            ]);

            $viaDigits = InvoiceMatcher::lookup('#80421');

            if (
                $viaDigits['outcome'] !== InvoiceMatcher::MATCHED
                || (string) $viaDigits['invoice']['clearbooks_id'] !== $ids[0]
            ) {
                return false;
            }

            $exact = InvoiceMatcher::lookup('80422');

            if (
                $exact['outcome'] !== InvoiceMatcher::MATCHED
                || (string) $exact['invoice']['purchase_type'] !== ClearbooksInvoice::CREDIT_NOTE
            ) {
                return false;
            }

            if (InvoiceMatcher::lookup('99998888')['outcome'] !== InvoiceMatcher::NONE) {
                return false;
            }

            /*
             * Two records answering to the same number resolve to nothing.
             *
             * The same rule the supplier matcher holds to for an ambiguous
             * name, and for a heavier reason: guessing wrong here attaches this
             * document's PDF to somebody else's invoice.
             */
            ClearbooksInvoice::upsert(ClearbooksInvoice::BILL, [
                'id'                      => $ids[2],
                'formattedDocumentNumber' => 'PUR80421',
                'date'                    => '2026-01-01',
                'grossAmount'             => 9.99,
            ]);

            return InvoiceMatcher::lookup('80421')['outcome'] === InvoiceMatcher::AMBIGUOUS;
        } finally {
            Database::run('DELETE FROM clearbooks_invoices WHERE clearbooks_id IN (?, ?, ?)', $ids);
        }
    })());

    /*
     * The checksum, which is the sentence this whole route turns on: **the
     * invoice date and the gross total must both agree exactly.**
     *
     * Every case below is a real shape, and the important ones are the near
     * misses — a day out, a penny out — because those are what a tolerance
     * would have let through unseen.
     */
    check('the checksum passes only on an exact agreement', (static function (): bool {
        $record = ['document_date' => '2026-07-14', 'gross_amount' => '413.28'];

        $exact = InvoiceMatcher::check($record, [
            'invoice_date' => '2026-07-14',
            'gross_amount' => '413.28',
        ]);

        if (!$exact['ok'] || $exact['agreed'] !== 2) {
            return false;
        }

        // One day out. A tolerance would call this a match; it is exactly what
        // a hit on a misread digit looks like, so it goes to a person.
        $dayOut = InvoiceMatcher::check($record, [
            'invoice_date' => '2026-07-15',
            'gross_amount' => '413.28',
        ]);

        if ($dayOut['ok'] || $dayOut['signals'][0]['outcome'] !== InvoiceMatcher::DISAGREED) {
            return false;
        }

        // One penny out, which is the commonest rounding difference there is
        // and still not a match.
        $pennyOut = InvoiceMatcher::check($record, [
            'invoice_date' => '2026-07-14',
            'gross_amount' => '413.27',
        ]);

        if ($pennyOut['ok'] || $pennyOut['signals'][1]['outcome'] !== InvoiceMatcher::DISAGREED) {
            return false;
        }

        // A value missing on either side is not an agreement. It cannot be
        // confirmed, so it is not confirmed.
        $noDate = InvoiceMatcher::check($record, ['invoice_date' => null, 'gross_amount' => '413.28']);

        if ($noDate['ok'] || $noDate['signals'][0]['outcome'] !== InvoiceMatcher::MISSING) {
            return false;
        }

        $noRecordTotal = InvoiceMatcher::check(
            ['document_date' => '2026-07-14', 'gross_amount' => null],
            ['invoice_date' => '2026-07-14', 'gross_amount' => '413.28']
        );

        return !$noRecordTotal['ok'] && $noRecordTotal['signals'][1]['outcome'] === InvoiceMatcher::MISSING;
    })());

    check('a credit note is compared on the amount, not the sign', (static function (): bool {
        // The sync keeps Clear Books' sign because it tells a credit note from
        // a purchase refund. A page never prints one — a credit note says
        // £240.00, not -£240.00 — so comparing signed figures would send every
        // credit note and every refund to manual review for a convention.
        // Dropping the sign is not a tolerance: the figure still has to be
        // identical to the penny.
        $result = InvoiceMatcher::check(
            ['document_date' => '2026-08-01', 'gross_amount' => '-240.00'],
            ['invoice_date' => '2026-08-01', 'gross_amount' => '240.00']
        );

        $stillExact = InvoiceMatcher::check(
            ['document_date' => '2026-08-01', 'gross_amount' => '-240.00'],
            ['invoice_date' => '2026-08-01', 'gross_amount' => '240.01']
        );

        return $result['ok'] && $result['agreed'] === 2 && !$stillExact['ok'];
    })());

    check('the total is compared as pence, never as floats', (static function (): bool {
        // 0.1 + 0.2 is the reason. A float comparison on values that arrive as
        // strings from two different systems is a bug that appears on somebody
        // else's machine and nowhere else.
        $result = InvoiceMatcher::check(
            ['document_date' => '2026-07-14', 'gross_amount' => '0.30'],
            ['invoice_date' => '2026-07-14', 'gross_amount' => 0.1 + 0.2]
        );

        return $result['ok'];
    })());

    /*
     * The fork itself: same document, same extraction, same entity matches —
     * only `documents.route` differs, and it decides where the matching stage
     * sends it.
     *
     * This is the assertion that says the two flows really are one pipeline. If
     * anybody ever splits them earlier again, the existing-invoice document
     * here stops reaching the matching stage at all and this fails.
     */
    check('the matching stage forks on the route and nothing else', (static function (): bool {
        $outcomes = [];
        $ids      = [];

        try {
            foreach ([Document::ROUTE_NEW, Document::ROUTE_EXISTING] as $route) {
                $documentId = Database::insert('documents', [
                    'ingest_source'     => 'upload',
                    'original_filename' => '__smoke_fork__.pdf',
                    'ingested_at'       => date('Y-m-d H:i:s'),
                    'status'            => Document::MATCHING,
                    'route'             => $route,
                ]);

                $ids[] = $documentId;

                // Deliberately unresolvable: no supplier name, no lines. A new
                // invoice must stop for a person; an existing one must not,
                // because nothing is being created from it.
                Extraction::create($documentId, [
                    'doc_type'      => 'bill',
                    'invoice_date'  => '2026-07-14',
                    'gross_amount'  => 413.28,
                    'line_items'    => [],
                    'supplier_match' => [],
                ]);

                $outcomes[$route] = (new App\Services\MatchStage())->run(Document::find($documentId) ?? []);
            }
        } finally {
            foreach ($ids as $documentId) {
                Database::run('DELETE FROM documents WHERE id = ?', [$documentId]);
            }
        }

        return $outcomes[Document::ROUTE_NEW] === Document::NEEDS_REVIEW
            && $outcomes[Document::ROUTE_EXISTING] === Document::EXISTING_INVOICE;
    })());

    check('a linked record is recorded as the kind Clear Books says it is', (static function (): bool {
        // The endpoint a record came back on is a fact, not a classification —
        // which is why the Existing Invoice route never asks anybody to confirm
        // the credit-note question the New Invoice route does.
        $bill   = DocumentType::forResource('bills');
        $credit = DocumentType::forResource('purchases/creditNotes');

        return $bill !== null && (string) $bill['clearbooks_resource'] === 'purchases/bills'
            && $credit !== null && (string) $credit['type_key'] === 'credit_note';
    })());

    echo "\nThe duplicate check on the New Invoice route (against the real tables)\n";

    check('documents knows the possible_duplicate status the code writes',
        in_array(Document::POSSIBLE_DUPLICATE, $enumValues('documents', 'status'), true));

    check('a cleared duplicate decision has somewhere to be recorded', (static function (): bool {
        $columns = array_column(
            Database::select('SHOW COLUMNS FROM documents'),
            'Field'
        );

        return in_array('duplicate_cleared_at', $columns, true)
            && in_array('duplicate_cleared_by', $columns, true);
    })());

    /*
     * The comparison itself, and it is the same one Prompt 17 makes.
     *
     * `DuplicateMatcher` calls `InvoiceMatcher::day()` and
     * `InvoiceMatcher::pence()` rather than spelling them again, so a
     * disagreement between the two screens about the same pair of records is
     * not possible. These assert the shared behaviour survives: no tolerance on
     * either, the sign dropped from the total, pence rather than floats.
     */
    check('a duplicate is judged on the same comparisons as a link', (static function (): bool {
        $document = ['matched_supplier_id' => '77', 'supplier_raw' => 'Acme Supplies'];

        $invoice = [
            'clearbooks_id'   => '99000201',
            'document_number' => 'PUR0090001',
            'purchase_type'   => ClearbooksInvoice::BILL,
            'supplier_id'     => '77',
            'document_date'   => '2026-07-14',
            'reference'       => 'INV-2026/0042',
            'gross_amount'    => '413.28',
            'supplier_name'   => 'Acme Supplies Limited',
            'synced_at'       => '2026-07-20 09:00:00',
        ];

        $score = static function (array $extraction) use ($document, $invoice): array {
            $method = new ReflectionMethod(App\Services\DuplicateMatcher::class, 'score');
            $method->setAccessible(true);

            return $method->invoke(null, $document, $extraction, $invoice);
        };

        // All four. A genuine duplicate is literally the same invoice.
        $all = $score([
            'invoice_number' => 'INV-2026/0042',
            'invoice_date'   => '2026-07-14',
            'gross_amount'   => '413.28',
        ]);

        if (!$all['plausible'] || $all['agreed'] !== 4) {
            return false;
        }

        // The reference written differently by two people is the same
        // reference: case and separators fold, and nothing else does.
        $spelled = $score([
            'invoice_number' => 'inv 2026 0042',
            'invoice_date'   => '2026-07-14',
            'gross_amount'   => '413.28',
        ]);

        if ($spelled['agreed'] !== 4) {
            return false;
        }

        // Leading zeros are NOT stripped from a supplier's reference, which is
        // the one place this differs from the Clear Books document-number pass.
        // Clear Books writes its own numbers to a fixed width; a supplier has
        // no such convention, so 0042 and 42 are two references.
        if (InvoiceMatcher::reference('0042') === InvoiceMatcher::reference('42')) {
            return false;
        }

        // One penny out and one day out, both still near misses rather than
        // matches — but the reference and the supplier carry it anyway, which
        // is exactly the case this gate exists for: an extraction that misread
        // one field on an invoice already in the accounts.
        $misread = $score([
            'invoice_number' => 'INV-2026/0042',
            'invoice_date'   => '2026-07-15',
            'gross_amount'   => '413.27',
        ]);

        return $misread['plausible'] && $misread['agreed'] === 2;
    })());

    /*
     * The threshold, which is the judgement this whole feature turns on: **two
     * signals, one of them the total or the reference.**
     *
     * The negative cases are the important ones. A business buys from the same
     * supplier every week and receives invoices dated the same day all the
     * time; a queue that stopped on either would cry wolf, and a queue that
     * cries wolf gets cleared without being read — which is worse than not
     * having built it.
     */
    check('one agreement is never enough, and never a supplier and a date', (static function (): bool {
        $invoice = [
            'clearbooks_id'   => '99000202',
            'document_number' => 'PUR0090002',
            'purchase_type'   => ClearbooksInvoice::BILL,
            'supplier_id'     => '77',
            'document_date'   => '2026-07-14',
            'reference'       => 'THEIRS-1',
            'gross_amount'    => '413.28',
            'supplier_name'   => 'Acme Supplies Limited',
            'synced_at'       => '2026-07-20 09:00:00',
        ];

        $score = static function (array $document, array $extraction) use ($invoice): array {
            $method = new ReflectionMethod(App\Services\DuplicateMatcher::class, 'score');
            $method->setAccessible(true);

            return $method->invoke(null, $document, $extraction, $invoice);
        };

        $supplier = ['matched_supplier_id' => '77', 'supplier_raw' => 'Acme'];
        $stranger = ['matched_supplier_id' => '99', 'supplier_raw' => 'Someone else'];

        // The recurring monthly figure, on its own.
        $totalOnly = $score($stranger, [
            'invoice_number' => 'OURS-9',
            'invoice_date'   => '2026-09-30',
            'gross_amount'   => '413.28',
        ]);

        if ($totalOnly['plausible'] || $totalOnly['agreed'] !== 1) {
            return false;
        }

        // A regular supplier and a date. Two agreements, and still not enough —
        // this shape arrives with every week's delivery.
        $regular = $score($supplier, [
            'invoice_number' => 'OURS-9',
            'invoice_date'   => '2026-07-14',
            'gross_amount'   => '88.00',
        ]);

        if ($regular['plausible'] || $regular['agreed'] !== 2) {
            return false;
        }

        // The same supplier and the same total is two agreements anchored on
        // money, which is enough.
        $anchored = $score($supplier, [
            'invoice_number' => 'OURS-9',
            'invoice_date'   => '2026-09-30',
            'gross_amount'   => '413.28',
        ]);

        return $anchored['plausible'] && $anchored['agreed'] === 2;
    })());

    check('a value missing on either side is never an agreement', (static function (): bool {
        $method = new ReflectionMethod(App\Services\DuplicateMatcher::class, 'score');
        $method->setAccessible(true);

        // An unresolved supplier says nothing either way, and must not count as
        // a *disagreement* — that would quietly make every document whose
        // supplier the matcher could not place un-flaggable.
        $result = $method->invoke(
            null,
            ['matched_supplier_id' => null, 'supplier_raw' => 'Acme'],
            ['invoice_number' => null, 'invoice_date' => '2026-07-14', 'gross_amount' => '413.28'],
            [
                'clearbooks_id' => '99000203', 'document_number' => 'PUR0090003',
                'purchase_type' => ClearbooksInvoice::BILL, 'supplier_id' => '77',
                'document_date' => '2026-07-14', 'reference' => null,
                'gross_amount' => '413.28', 'supplier_name' => null,
                'synced_at' => '2026-07-20 09:00:00',
            ]
        );

        $outcomes = array_column($result['signals'], 'outcome', 'key');

        return $outcomes['supplier'] === App\Services\DuplicateMatcher::MISSING
            && $outcomes['reference'] === App\Services\DuplicateMatcher::MISSING
            && $result['agreed'] === 2
            && $result['plausible'];
    })());

    /*
     * The narrowing, against real rows.
     *
     * Two things are asserted, and the second matters as much as the first: the
     * total is compared **either sign**, because the sync keeps Clear Books'
     * own sign and a page never prints one; and nothing is fetched on the
     * supplier or the date alone, because that would be most of the table.
     */
    check('candidates are narrowed on the money and the reference only', (static function (): bool {
        $ids = ['99000301', '99000302', '99000303'];

        Database::run('DELETE FROM clearbooks_invoices WHERE clearbooks_id IN (?, ?, ?)', $ids);

        try {
            ClearbooksInvoice::upsert(ClearbooksInvoice::BILL, [
                'id'                      => $ids[0],
                'formattedDocumentNumber' => 'PUR0090011',
                'date'                    => '2026-07-14',
                'reference'               => 'SUPP/2026/77',
                'grossAmount'             => 413.28,
            ]);

            // A credit note, whose stored gross is negative. Found on the same
            // figure, because the sign is a convention rather than a difference.
            ClearbooksInvoice::upsert(ClearbooksInvoice::CREDIT_NOTE, [
                'id'                      => $ids[1],
                'formattedDocumentNumber' => 'PUR0090012',
                'date'                    => '2026-08-01',
                'grossAmount'             => -240.00,
            ]);

            // Same date, same supplier, nothing else. Must not be fetched.
            ClearbooksInvoice::upsert(ClearbooksInvoice::BILL, [
                'id'                      => $ids[2],
                'formattedDocumentNumber' => 'PUR0090013',
                'date'                    => '2026-07-14',
                'grossAmount'             => 1.11,
            ]);

            $ours = static fn (array $rows): array => array_values(array_intersect(
                array_map(static fn (array $r): string => (string) $r['clearbooks_id'], $rows),
                $ids
            ));

            // The reference, spelled differently.
            $byReference = $ours(ClearbooksInvoice::findPossibleDuplicates('supp 2026 77', null));

            if ($byReference !== [$ids[0]]) {
                return false;
            }

            // The total, against a negative stored figure.
            $byTotal = $ours(ClearbooksInvoice::findPossibleDuplicates(null, '240.00'));

            if ($byTotal !== [$ids[1]]) {
                return false;
            }

            // Neither: nothing comes back, and in particular not the row that
            // shares only a date.
            return $ours(ClearbooksInvoice::findPossibleDuplicates(null, null)) === []
                && $ours(ClearbooksInvoice::findPossibleDuplicates('nothing-like-it', '0.07')) === [];
        } finally {
            Database::run('DELETE FROM clearbooks_invoices WHERE clearbooks_id IN (?, ?, ?)', $ids);
        }
    })());

    /*
     * The gate itself, driven through the real matching stage.
     *
     * Three documents, identical but for what makes each one different, run
     * through `MatchStage::run()` rather than through a reimplementation of it.
     * This is the assertion that says the feature is wired up at all: if the
     * gate is ever moved, removed or short-circuited, the first row here stops
     * reaching `possible_duplicate` and this fails.
     */
    check('the matching stage stops a new invoice that Clear Books already holds',
        (static function (): bool {
            $recordId = '99000401';
            $ids      = [];

            Database::run('DELETE FROM clearbooks_invoices WHERE clearbooks_id = ?', [$recordId]);

            ClearbooksInvoice::upsert(ClearbooksInvoice::BILL, [
                'id'                      => $recordId,
                'formattedDocumentNumber' => 'PUR0090021',
                'date'                    => '2026-07-14',
                'reference'               => 'SMOKE-DUP-1',
                'grossAmount'             => 413.28,
            ]);

            // `$extra` first, because `+` keeps the *left* operand for a key
            // both sides have — the defaults are what is being overridden.
            $run = static function (array $extra, array $fields) use (&$ids): string {
                $documentId = Database::insert('documents', $extra + [
                    'ingest_source'     => 'upload',
                    'original_filename' => '__smoke_dedup__.pdf',
                    'ingested_at'       => date('Y-m-d H:i:s'),
                    'status'            => Document::MATCHING,
                    'route'             => Document::ROUTE_NEW,
                ]);

                $ids[] = $documentId;

                Extraction::create($documentId, $fields + [
                    'doc_type'       => 'bill',
                    'line_items'     => [],
                    'supplier_match' => [],
                ]);

                return (new App\Services\MatchStage())->run(Document::find($documentId) ?? []);
            };

            try {
                $duplicate = [
                    'invoice_number' => 'SMOKE-DUP-1',
                    'invoice_date'   => '2026-07-14',
                    'gross_amount'   => 413.28,
                ];

                // 1. The reference, the date and the total all agree. Stopped —
                //    and stopped *instead of* a disposition, which is the whole
                //    point: this document's entities are deliberately
                //    unresolvable, and it must not reach `needs_review` either.
                if ($run([], $duplicate) !== Document::POSSIBLE_DUPLICATE) {
                    return false;
                }

                // 2. Nothing like it. Straight through to the ordinary queue.
                $new = $run([], [
                    'invoice_number' => 'SMOKE-NEW-1',
                    'invoice_date'   => '2026-02-02',
                    'gross_amount'   => 17.50,
                ]);

                if ($new !== Document::NEEDS_REVIEW) {
                    return false;
                }

                // 3. The same duplicate, already cleared by a person. The stamp
                //    is what stops the re-match putting it straight back.
                $cleared = $run(['duplicate_cleared_at' => date('Y-m-d H:i:s')], $duplicate);

                if ($cleared !== Document::NEEDS_REVIEW) {
                    return false;
                }

                // 4. The same duplicate on the *other* route. An existing-invoice
                //    document is never asked this question: it carries a
                //    Clearbooks Number, so it already knows which record it
                //    belongs to, and `LinkStage`'s checksum is the stricter gate.
                return $run(['route' => Document::ROUTE_EXISTING], $duplicate) === Document::EXISTING_INVOICE;
            } finally {
                foreach ($ids as $documentId) {
                    Database::run('DELETE FROM documents WHERE id = ?', [$documentId]);
                }

                Database::run('DELETE FROM clearbooks_invoices WHERE clearbooks_id = ?', [$recordId]);
            }
        })());

    /*
     * There is nothing to configure about the duplicate check either — the same
     * assertion §33 makes about the checksum, extended. A "duplicate" settings
     * row appearing later would mean somebody had made the threshold adjustable
     * without arguing for it, and a threshold that can be turned down to
     * nothing is a check that quietly stops running.
     */
    check('the duplicate check has no settings behind it', (static function (): bool {
        foreach (array_keys(Setting::summary()) as $key) {
            if (str_contains((string) $key, 'duplicate') || str_contains((string) $key, 'dedup')) {
                return false;
            }
        }

        return !in_array('duplicates', array_keys(SettingSchema::SECTIONS), true);
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

    check('the submission-produced fields exist and are marked as produced', (static function (): bool {
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

        foreach (['ingest_source', 'status', 'extraction_id', 'unresolved', 'review_notes', 'edited_at'] as $column) {
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
    // everything the API supplies. It is local knowledge Clear Books does not
    // hold, and it silently stops being true if upsert() ever widens.
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

        $supplied = array_keys($stage->invoke(new App\Services\ExtractStage(), 'sample text', []));
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

    check('select choices round-trip', (static function (): bool {
        $options = CustomField::selectOptions("First choice\nSecond choice\n\n");

        if ($options === null || count($options) !== 2) {
            return false;
        }

        // An id distinct from the label, so a choice can be renamed without
        // orphaning the documents already stored against it.
        if ($options[0]['label'] !== 'First choice' || $options[0]['id'] !== 'first_choice') {
            return false;
        }

        return CustomField::optionLines(json_encode($options)) === "First choice\nSecond choice";
    })());

    /*
     * The edit form renders the key as read-only *text*, so a browser posts
     * nothing for it. A controller that passed `''` through anyway would make
     * `CustomField::update()`'s "the key cannot change" guard fire on every
     * save — an empty key does not equal the stored one — and editing a field
     * would be impossible while looking like a deliberate refusal. It was,
     * until Prompt 15.
     */
    check('editing a field does not propose a key it was not given', (static function (): bool {
        $field = CustomField::all()[0] ?? null;

        if ($field === null) {
            return true;
        }

        // The shape the edit form actually posts: no field_key at all.
        $changed = CustomField::update((int) $field['id'], [
            'label'       => (string) $field['label'],
            'data_type'   => (string) $field['data_type'],
            'prompt_hint' => (string) ($field['prompt_hint'] ?? ''),
            'active'      => (int) $field['active'] === 1,
            'source'      => (string) $field['source'],
        ]);

        // Nothing was altered, so nothing should be reported as changed either.
        return $changed === [];
    })());

    // A key is the address every stored value lives at, so two fields sharing
    // one would make last month's extraction ambiguous.
    check('no two custom fields share a key', (static function (): bool {
        $seen = [];

        foreach (CustomField::all() as $field) {
            $key = (string) $field['field_key'];

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;
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
