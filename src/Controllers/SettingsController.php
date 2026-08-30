<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Setting;
use App\Models\SettingSchema;
use App\Services\ClearBooksClient;
use App\Services\Llm\LlmFactory;
use App\Services\PaperlessClient;
use Throwable;

/**
 * Everything an administrator sets once and rarely revisits.
 *
 * This is what `php bin/console.php settings:set` was standing in for. The
 * console command stays — a credential has to be settable before anyone can
 * sign in to change it, and an install script has no browser — but nothing
 * here needs it any more.
 *
 * Three rules the screen exists to keep:
 *
 *  - **A secret is never sent back to a browser.** The form shows whether one
 *    is set, never what it is; an empty box means "leave it alone", and there
 *    is a separate checkbox for clearing one. Nothing on this page reads
 *    `Setting::secret()`, and `tests/smoke.php` fails if a template does.
 *  - **A secret is refused rather than stored in the clear.** `Setting::put()`
 *    returns false when APP_KEY is unusable, and this says so in those words
 *    instead of reporting a save that did not happen.
 *  - **The .env fallback stays a fallback.** The form is filled from the stored
 *    row, not from `Setting::get()`, so saving one field does not quietly copy
 *    every .env value into the database and freeze it there.
 *
 * One form per card, each posting to its own section, so an error on a Clear
 * Books address does not throw away what was typed into the model boxes.
 */
final class SettingsController extends Controller
{
    public function index(): void
    {
        $paperless = $this->paperlessDocumentTypes();

        $this->view('admin/settings', [
            'pageTitle'      => 'Application settings',
            'sections'       => $this->sectionData(),
            'keyUsable'      => Crypto::hasKey(),
            'connected'      => ClearBooksClient::isConnected(),
            'docTypes'       => DocumentType::all(false),
            'paperlessTypes' => $paperless['types'],
            'paperlessError' => $paperless['error'],
        ]);
    }

    /**
     * Save one card.
     *
     * Every field in the section is considered, not only the ones that came
     * back changed: a cleared box is a deliberate act, and treating "absent"
     * as "unchanged" would make emptying a field impossible. A checkbox is the
     * one exception the browser forces — an unticked one is not posted at all,
     * which is why booleans are read as "present or not" rather than validated
     * into existence.
     */
    public function save(string $section): void
    {
        if (!SettingSchema::isSection($section)) {
            $this->notFound('There is no such settings section.');
        }

        $fields = SettingSchema::forSection($section);
        $target = '/admin/settings#' . $section;

        $rules  = [];
        $labels = [];

        foreach ($fields as $key => $field) {
            $rules[$key]  = (string) $field['rule'];
            $labels[$key] = (string) $field['label'];
        }

        $input = $this->validate($rules, $labels, $target);

        $changed = [];
        $refused = [];

        foreach ($fields as $key => $field) {
            $isSecret = $field['type'] === SettingSchema::SECRET;
            $supplied = (string) ($input[$key] ?? '');

            if ($isSecret) {
                $clearing = Request::boolean($key . '__clear');

                if (!$clearing && $supplied === '') {
                    continue; // an empty box means "leave this one alone"
                }

                if (!Setting::put($key, $clearing ? '' : $supplied, true)) {
                    $refused[] = (string) $field['label'];
                    continue;
                }

                $changed[] = $clearing ? $key . ' (cleared)' : $key;
                continue;
            }

            $value = $field['type'] === SettingSchema::BOOLEAN
                ? (Request::boolean($key) ? '1' : '0')
                : $supplied;

            if ($value === (Setting::stored($key) ?? '')) {
                continue;
            }

            Setting::put($key, $value, false);
            $changed[] = $key;
        }

        // The worker is long-lived and holds its own copy, but this process
        // has one too and the redirect renders from it.
        Setting::flush();

        foreach ($refused as $label) {
            Flash::error(
                $label . ' was not saved: it is a secret and APP_KEY is missing or unreadable, so it '
                . 'cannot be encrypted. Run php bin/console.php key:generate and put the result in '
                . '.env. Nothing here will ever write a credential in the clear.'
            );
        }

        if ($changed !== []) {
            // The keys, never the values — this log is readable by anyone with
            // audit.view, and half of these rows are credentials.
            AuditLog::record('settings.updated', null, sprintf(
                '%s changed %s: %s.',
                Auth::displayName(),
                SettingSchema::SECTIONS[$section]['title'],
                implode(', ', $changed)
            ));

            Flash::success(count($changed) === 1
                ? 'Saved. One setting changed.'
                : 'Saved. ' . count($changed) . ' settings changed.');
        } elseif ($refused === []) {
            Flash::info('Nothing was different, so nothing was written.');
        }

        Response::redirect($target);
    }

    /**
     * The Paperless document type each InvoGrid type maps onto.
     *
     * It lives in `document_types` rather than as a settings row because it is
     * per-document-type by nature — adding a type stays one insert — but this
     * is where it is edited, and until now it was only settable in SQL.
     *
     * A type may be left unmapped. That is not an error: the write-back simply
     * leaves the Paperless document type alone, which is the right behaviour
     * for a site that does not use them.
     */
    public function saveDocumentTypes(): void
    {
        $submitted = Request::post('paperless_document_type_id', []);
        $target    = '/admin/settings#document-types';

        if (!is_array($submitted)) {
            $this->failValidation(['paperless_document_type_id' => 'The mapping form was malformed.'], $target);
        }

        $known   = $this->paperlessDocumentTypes()['types'];
        $byId    = [];
        foreach ($known as $type) {
            $byId[(int) ($type['id'] ?? 0)] = (string) ($type['name'] ?? '');
        }

        $changed = [];

        foreach (DocumentType::all(false) as $type) {
            $id  = (int) $type['id'];
            $raw = trim((string) ($submitted[$id] ?? ''));

            if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_INT) === false) {
                $this->failValidation(
                    ['paperless_document_type_id' => 'A Paperless document type id must be a whole number.'],
                    $target
                );
            }

            $new = $raw === '' ? null : (int) $raw;
            $old = $type['paperless_document_type_id'] === null
                ? null
                : (int) $type['paperless_document_type_id'];

            if ($new === $old) {
                continue;
            }

            // Only checked against the fetched list when there is one. An
            // unreachable Paperless must not stop an id being corrected —
            // that is exactly when somebody is likely to be fixing it.
            if ($new !== null && $byId !== [] && !array_key_exists($new, $byId)) {
                $this->failValidation(
                    ['paperless_document_type_id' => 'Paperless has no document type with id ' . $new . '.'],
                    $target
                );
            }

            DocumentType::setPaperlessType($id, $new);

            $changed[] = sprintf(
                '%s → %s',
                (string) $type['type_key'],
                $new === null ? 'unmapped' : ($byId[$new] ?? ('id ' . $new))
            );
        }

        if ($changed === []) {
            Flash::info('Nothing was different, so nothing was written.');
            Response::redirect($target);
        }

        AuditLog::record('settings.document_types', null, sprintf(
            '%s changed the Paperless document type mapping: %s.',
            Auth::displayName(),
            implode(', ', $changed)
        ));

        Flash::success('Saved. The new mapping is used on the next document submitted.');
        Response::redirect($target);
    }

    /**
     * Prove a credential actually works, rather than that it is present.
     *
     * `isConfigured()` answers "is there a string in the box", which is not the
     * question anyone has. Both clients already carry a `ping()` written for
     * this screen — a cheap authenticated call — and the Clear Books screen has
     * done the same thing since it was built.
     *
     * The model test is a real API call and costs a fraction of a penny. It is
     * a button somebody presses, never something done on page load, for exactly
     * that reason.
     */
    public function test(string $target): void
    {
        $anchor = match ($target) {
            'paperless'                  => '/admin/settings#paperless',
            'llm_ocr', 'llm_extraction'  => '/admin/settings#llm',
            default                      => null,
        };

        if ($anchor === null) {
            $this->notFound('There is nothing by that name to test.');
        }

        try {
            if ($target === 'paperless') {
                if (!PaperlessClient::isConfigured()) {
                    Flash::warning('Paperless needs an address and an API token before it can be tested.');
                    Response::redirect($anchor);
                }

                $result = (new PaperlessClient())->ping();
            } else {
                $stage = substr($target, 4);

                // Asked before the client is built, not after: the constructor
                // throws on a missing key, and "No Anthropic API key is
                // configured" arriving as an exception reads like a fault
                // rather than like the ordinary state it is.
                if (!LlmFactory::isConfigured($stage)) {
                    $provider = SettingSchema::providerLabel(LlmFactory::provider($stage));

                    Flash::warning(sprintf(
                        'That stage is set to %s, and there is no %s API key yet.',
                        $provider,
                        $provider
                    ));
                    Response::redirect($anchor);
                }

                $result = LlmFactory::forStage($stage)->ping();
            }
        } catch (Throwable $e) {
            // A configuration mistake reaches here as an exception rather than
            // as a false: an unknown provider, an unusable gateway URL. Saying
            // what it was beats "the test failed".
            Flash::error('The test could not be run: ' . $e->getMessage());
            Response::redirect($anchor);
        }

        if ($result['ok']) {
            Flash::success($result['message']);
        } else {
            Flash::error($result['message']);
        }

        Response::redirect($anchor);
    }

    // --- Plumbing ----------------------------------------------------------

    /**
     * The cards, each with its fields resolved for display.
     *
     * A secret contributes `configured` and nothing else. Everything else
     * contributes the **stored** value plus, when that is empty and .env
     * supplies one, what the application is falling back to — so the screen
     * can tell "nobody has set this" from "this is set, elsewhere".
     *
     * @return array<string,array<string,mixed>>
     */
    private function sectionData(): array
    {
        $out = [];

        foreach (SettingSchema::SECTIONS as $name => $section) {
            $fields = [];

            foreach (SettingSchema::forSection($name) as $key => $field) {
                $isSecret = $field['type'] === SettingSchema::SECRET;

                $fields[$key] = $field + [
                    'key'        => $key,
                    'stored'     => $isSecret ? '' : (string) (Setting::stored($key) ?? ''),
                    'configured' => Setting::isConfigured($key),
                    'fallback'   => Setting::hasEnvFallback($key),

                    // Null for a secret, by design: that a fallback is in force
                    // is worth saying, what it holds is not.
                    'fallbackValue' => $isSecret ? null : Setting::envFallbackValue($key),
                ];
            }

            $out[$name] = $section + ['fields' => $fields];
        }

        return $out;
    }

    /**
     * Paperless's own document types, for the mapping picker.
     *
     * An empty list with a reason rather than an exception: the rest of this
     * screen is perfectly usable without Paperless, and a settings page that
     * will not render because a remote service is down is worse than one that
     * says so. Mirrors `PaperlessFields::available()`.
     *
     * @return array{types:array<int,array<string,mixed>>,error:?string}
     */
    private function paperlessDocumentTypes(): array
    {
        if (!PaperlessClient::isConfigured()) {
            return ['types' => [], 'error' => 'Paperless is not configured yet, so its document types cannot be listed.'];
        }

        try {
            $types = (new PaperlessClient())->documentTypes();
        } catch (Throwable $e) {
            return ['types' => [], 'error' => 'Paperless could not be reached: ' . $e->getMessage()];
        }

        usort($types, static fn (array $a, array $b): int => strcasecmp(
            (string) ($a['name'] ?? ''),
            (string) ($b['name'] ?? '')
        ));

        return ['types' => $types, 'error' => null];
    }
}
