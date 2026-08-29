<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\ClearbooksCache;
use App\Models\CustomField;
use App\Models\PromptTemplate;
use App\Services\PromptRenderer;
use Throwable;

/**
 * The live prompts, editable without a deploy.
 *
 * Every edit writes a **new version** and makes it active; nothing is
 * overwritten. Two reasons, both familiar to anyone who has run a prompt in
 * production: a change that makes things worse has to be revertible in one
 * click, and every result has to be able to say which prompt produced it —
 * otherwise "the extraction got worse last Tuesday" is unanswerable.
 *
 * The screen does two things beyond a text box, and both are about catching a
 * mistake here rather than at three in the morning:
 *
 *  - **it validates the variables on save.** `PromptRenderer` throws on a name
 *    nothing supplies, which is correct but happens with a document already in
 *    the pipeline and the person who typed it long gone;
 *  - **it shows what each variable currently expands to.** `{{ customFields }}`
 *    is whatever is active on the Custom fields screen at the moment the
 *    document runs, and being able to see that is the difference between
 *    trusting the injection and hand-listing the fields in the prompt "to be
 *    safe" — which is exactly what it is there to prevent.
 */
final class PromptController extends Controller
{
    public function index(): void
    {
        $prompts = [];

        foreach (PromptTemplate::keys() as $key) {
            $active = PromptTemplate::active($key);

            $prompts[$key] = [
                'active'    => $active,
                'purpose'   => PromptTemplate::PURPOSE[$key] ?? '',
                'variables' => PromptTemplate::availableFor($key) ?? [],
                'isDefault' => PromptTemplate::isDefault($key),
                'versions'  => count(PromptTemplate::versions($key)),
                'used'      => $active === null
                    ? []
                    : PromptRenderer::variablesUsed((string) $active['content']),
            ];
        }

        $this->view('admin/prompts', [
            'pageTitle' => 'Prompts',
            'prompts'   => $prompts,
        ]);
    }

    /** One prompt: its text, its history, and what its variables hold today. */
    public function edit(string $key): void
    {
        $active = PromptTemplate::active($key);

        if ($active === null && PromptTemplate::availableFor($key) === null) {
            $this->notFound('No such prompt.');
        }

        $this->view('admin/prompt-edit', [
            'pageTitle'  => 'Prompt: ' . $key,
            'key'        => $key,
            'active'     => $active,
            'purpose'    => PromptTemplate::PURPOSE[$key] ?? '',
            'variables'  => PromptTemplate::availableFor($key) ?? [],
            'help'       => PromptTemplate::VARIABLE_HELP,
            'preview'    => $this->preview(),
            'versions'   => PromptTemplate::versions($key),
            'seed'       => PromptTemplate::newestSeed($key),
            'isDefault'  => PromptTemplate::isDefault($key),
        ]);
    }

    /** Save an edit as the next version. */
    public function save(string $key): void
    {
        if (PromptTemplate::availableFor($key) === null && PromptTemplate::active($key) === null) {
            $this->notFound('No such prompt.');
        }

        $content  = (string) Request::post('content', '');
        $problems = PromptTemplate::problemsWith($key, $content);

        if ($problems !== []) {
            // Not saved at all. A prompt that will not render is worse than the
            // one it would have replaced, and half-saving it to "let them fix it
            // later" means the next document runs on the broken one.
            Flash::error($problems[0]);
            Flash::old(['content' => $content]);
            Response::redirect('/admin/prompts/' . rawurlencode($key));
        }

        $active = PromptTemplate::active($key);

        if ($active !== null && (string) $active['content'] === $content) {
            Flash::info('Nothing was changed, so no new version was written.');
            Response::redirect('/admin/prompts/' . rawurlencode($key));
        }

        try {
            $id = PromptTemplate::saveNewVersion($key, $content, (string) Request::post('label', ''));
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/admin/prompts/' . rawurlencode($key));
        }

        $new = PromptTemplate::active($key);

        AuditLog::record('prompts.edited', null, sprintf(
            '%s saved "%s" as version %s (was version %s).%s',
            Auth::displayName(),
            $key,
            $new['version'] ?? '?',
            $active['version'] ?? 'none',
            trim((string) Request::post('label', '')) === ''
                ? ''
                : ' Note: ' . mb_substr(trim((string) Request::post('label', '')), 0, 200)
        ));

        Flash::success(sprintf(
            'Saved as version %s and made active. It is what the next document sees; version %s is kept and can be brought back.',
            $new['version'] ?? '?',
            $active['version'] ?? 'the previous one'
        ));

        Response::redirect('/admin/prompts/' . rawurlencode($key));
    }

    /** Bring an earlier version back. */
    public function activate(string $key, string $id): void
    {
        $was = PromptTemplate::active($key);

        try {
            PromptTemplate::activate((int) $id);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/admin/prompts/' . rawurlencode($key));
        }

        $now = PromptTemplate::active($key);

        AuditLog::record('prompts.activated', null, sprintf(
            '%s made "%s" version %s active again (was version %s).',
            Auth::displayName(),
            $key,
            $now['version'] ?? '?',
            $was['version'] ?? 'none'
        ));

        Flash::success('Version ' . ($now['version'] ?? '?') . ' is active again.');
        Response::redirect('/admin/prompts/' . rawurlencode($key));
    }

    /**
     * Back to what shipped.
     *
     * Re-activates the newest seeded version rather than writing a copy of it,
     * so the history stays honest and the reset is undone the same way any
     * other version switch is.
     */
    public function reset(string $key): void
    {
        $seed = PromptTemplate::newestSeed($key);

        if ($seed === null) {
            Flash::error('There is no shipped version of this prompt to go back to.');
            Response::redirect('/admin/prompts/' . rawurlencode($key));
        }

        $was = PromptTemplate::active($key);

        PromptTemplate::activate((int) $seed['id']);

        AuditLog::record('prompts.reset', null, sprintf(
            '%s reset "%s" to the shipped version %s (was version %s).',
            Auth::displayName(),
            $key,
            $seed['version'],
            $was['version'] ?? 'none'
        ));

        Flash::success('Back to the version that shipped (v' . $seed['version']
            . '). Your edits are still in the history and can be brought back.');

        Response::redirect('/admin/prompts/' . rawurlencode($key));
    }

    /**
     * What each variable would expand to right now.
     *
     * Truncated hard: the supplier list can be hundreds of entries, and the
     * question this answers is "is my field in there", not "show me everything".
     *
     * @return array<string,array{value:string,summary:string}>
     */
    private function preview(): array
    {
        $lists = [
            'suppliers'     => ClearbooksCache::forPrompt(ClearbooksCache::SUPPLIER),
            'accountCodes'  => ClearbooksCache::forPrompt(ClearbooksCache::ACCOUNT_CODE),
            'vatRates'      => ClearbooksCache::forPrompt(ClearbooksCache::VAT_RATE),
            'vatTreatments' => ClearbooksCache::forPrompt(ClearbooksCache::VAT_TREATMENT),
            'customFields'  => CustomField::forPrompt(),
        ];

        $preview = [
            'ocrText' => [
                'value'   => '(the transcription of whichever document is being run)',
                'summary' => 'Filled in per document.',
            ],
            'today' => [
                'value'   => date('Y-m-d'),
                'summary' => 'Today.',
            ],
        ];

        foreach ($lists as $name => $rows) {
            $encoded = PromptRenderer::encodeList($rows);

            $preview[$name] = [
                'value'   => mb_strlen($encoded) > 1500 ? mb_substr($encoded, 0, 1500) . "\n…" : $encoded,
                'summary' => $rows === []
                    ? 'Empty — nothing is cached, and a model given an empty list invents values.'
                    : count($rows) . ' ' . ($name === 'customFields' ? 'field' : 'entry')
                        . (count($rows) === 1 ? '' : 's') . ' as things stand.',
            ];
        }

        return $preview;
    }
}
