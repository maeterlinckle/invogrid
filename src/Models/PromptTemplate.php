<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Services\PromptRenderer;
use RuntimeException;

/**
 * Versioned LLM prompts, edited in the application rather than in the source.
 *
 * An edit writes a **new version** and deactivates the old one rather than
 * overwriting. Two reasons, both learned the hard way by everyone who has run a
 * prompt in production: a change that makes things worse has to be revertible
 * in one click, and every result has to be able to say which prompt produced
 * it — otherwise "the extraction got worse last Tuesday" is unanswerable.
 */
final class PromptTemplate
{
    public const SEED   = 'seed';
    public const EDITED = 'edited';

    /**
     * The variables `ExtractStage` offers, which it offers to **every** one of
     * its calls — a prompt takes what it names and ignores the rest.
     *
     * @var array<int,string>
     */
    public const EXTRACTION_VARIABLES = [
        'ocrText', 'today', 'suppliers', 'accountCodes', 'vatRates', 'vatTreatments', 'customFields',
    ];

    /**
     * What each prompt may name, by key.
     *
     * The contract, in one place, read by the editor to validate a save and by
     * `tests/smoke.php` to check the seeded prompts. It was written out twice
     * before that — once in the stage and once in the test — and two copies of
     * a contract is one copy too many.
     *
     * **`ocr` is deliberately empty.** That prompt is sent to the model
     * verbatim, alongside the page images; `OcrStage` never runs it through
     * `PromptRenderer`. A `{{ ocrText }}` added to it would be transmitted as
     * those literal characters, and the model would answer confidently about
     * nothing. The editor refuses it rather than letting somebody find that out
     * from the results.
     *
     * @var array<string,array<int,string>>
     */
    public const AVAILABLE = [
        'ocr'                   => [],
        'extract_header'        => self::EXTRACTION_VARIABLES,
        'extract_supplier'      => self::EXTRACTION_VARIABLES,
        'extract_lines'         => self::EXTRACTION_VARIABLES,
        'extract_custom_fields' => self::EXTRACTION_VARIABLES,
    ];

    /** What each variable expands to, said in one line for the editor. */
    public const VARIABLE_HELP = [
        'ocrText'       => 'The full transcription of the scan, its "### Notes" section included.',
        'today'         => "Today's date as YYYY-MM-DD, for working out relative wording like \"30 days\".",
        'suppliers'     => 'Every cached Clear Books supplier, with its Clear Books and Paperless ids.',
        'accountCodes'  => 'The cached purchase account codes. Sales-only codes are not in the list.',
        'vatRates'      => 'The cached purchase VAT rates, each with its percentage.',
        'vatTreatments' => 'The cached purchase VAT treatments.',
        'customFields'  => 'Whatever is active on the Custom fields screen right now, with each hint.',
    ];

    /** What a prompt is for, for somebody deciding whether to edit it. */
    public const PURPOSE = [
        'ocr'                   => 'Reads the page images and returns the transcription, the handwritten annotations and the two high-value fields. Sent with the images, and **verbatim** — no variables.',
        'extract_header'        => 'Titles, dates, reference and currency, from the transcription.',
        'extract_supplier'      => 'Matches the issuer against the cached Clear Books supplier list.',
        'extract_lines'         => 'Classifies the document and reads the line items, account codes and VAT.',
        'extract_custom_fields' => 'The fallback, asked only about custom fields the cheaper routes could not resolve.',
    ];

    /**
     * The variables a prompt may name, or null when the key is unknown.
     *
     * @return array<int,string>|null
     */
    public static function availableFor(string $key): ?array
    {
        return self::AVAILABLE[$key] ?? null;
    }

    /**
     * What is wrong with this content for this key — an empty list if nothing.
     *
     * Checked when the editor saves rather than when a document runs. The
     * renderer throws on an unknown name, which is correct but happens with a
     * document already in the pipeline and a person nowhere near it.
     *
     * @return array<int,string>
     */
    public static function problemsWith(string $key, string $content): array
    {
        $problems  = [];
        $available = self::availableFor($key);

        if (trim($content) === '') {
            return ['A prompt cannot be empty.'];
        }

        if ($available === null) {
            return $problems;
        }

        $used    = PromptRenderer::variablesUsed($content);
        $unknown = array_values(array_diff($used, $available));

        if ($unknown === []) {
            return $problems;
        }

        if ($available === []) {
            $problems[] = 'This prompt is sent to the model exactly as written, so '
                . self::listOf($unknown) . ' would be transmitted as those literal characters '
                . 'rather than being filled in. Remove them, or put the wording in one of the '
                . 'extraction prompts instead.';

            return $problems;
        }

        $problems[] = 'Nothing supplies ' . self::listOf($unknown)
            . '. Available here: ' . implode(', ', array_map(
                static fn (string $name): string => '{{ ' . $name . ' }}',
                $available
            )) . '.';

        return $problems;
    }

    /** @param array<int,string> $names */
    private static function listOf(array $names): string
    {
        return implode(' and ', array_filter([
            implode(', ', array_map(static fn (string $n): string => '{{ ' . $n . ' }}', array_slice($names, 0, -1))),
            '{{ ' . end($names) . ' }}',
        ]));
    }

    /** @return array<string,mixed>|null */
    public static function active(string $key): ?array
    {
        return Database::selectOne(
            'SELECT * FROM prompt_templates WHERE template_key = ? AND is_active = 1 ORDER BY version DESC LIMIT 1',
            [$key]
        );
    }

    /**
     * The active prompt's text, or a clear failure.
     *
     * Throwing rather than falling back to some built-in default: a stage
     * running against a prompt nobody chose produces results nobody can
     * explain.
     */
    public static function content(string $key): string
    {
        $row = self::active($key);

        if ($row === null) {
            throw new RuntimeException(
                'No active "' . $key . '" prompt. Run the migrations, or activate one in Settings.'
            );
        }

        return (string) $row['content'];
    }

    /** @return array<int,array<string,mixed>> */
    public static function versions(string $key): array
    {
        return Database::select(
            'SELECT p.*, u.username, u.display_name
               FROM prompt_templates p
               LEFT JOIN users u ON u.id = p.updated_by
              WHERE p.template_key = ?
              ORDER BY p.version DESC',
            [$key]
        );
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['template_key'],
            Database::select('SELECT DISTINCT template_key FROM prompt_templates ORDER BY template_key')
        );
    }

    /**
     * Save an edit as the next version, and make it the active one.
     *
     * The whole thing is one transaction: a moment where a key has two active
     * versions, or none, is a moment a pipeline stage could run in.
     */
    public static function saveNewVersion(string $key, string $content, string $label = ''): int
    {
        Database::beginTransaction();

        try {
            $next = ((int) Database::scalar(
                'SELECT COALESCE(MAX(version), 0) FROM prompt_templates WHERE template_key = ?',
                [$key]
            )) + 1;

            Database::run('UPDATE prompt_templates SET is_active = 0 WHERE template_key = ?', [$key]);

            $id = Database::insert('prompt_templates', [
                'template_key' => $key,
                'version'      => $next,
                'label'        => mb_substr($label, 0, 120),

                // Anything saved from the editor is an edit, whatever it says.
                // Only a migration writes a `seed`, which is what makes "reset
                // to default" a question with an answer.
                'origin'       => self::EDITED,
                'content'      => $content,
                'is_active'    => 1,
                'updated_by'   => Auth::id(),
            ]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();

            throw $e;
        }

        return $id;
    }

    /**
     * The newest version that shipped with the application.
     *
     * What "reset to default" resets *to*. Not version 1: the OCR prompt's
     * version 1 was written to a specification before the real text was
     * available, and version 2 — the production prompt from the flow this
     * replaces — is the one anybody resetting actually wants back.
     *
     * @return array<string,mixed>|null
     */
    public static function newestSeed(string $key): ?array
    {
        return Database::selectOne(
            'SELECT * FROM prompt_templates WHERE template_key = ? AND origin = ? ORDER BY version DESC LIMIT 1',
            [$key, self::SEED]
        );
    }

    /**
     * Is the active version the one that shipped?
     *
     * Shown on the list, because "has anybody changed this" is the first
     * question asked when the extraction starts behaving differently.
     */
    public static function isDefault(string $key): bool
    {
        $active = self::active($key);
        $seed   = self::newestSeed($key);

        return $active !== null && $seed !== null && (int) $active['id'] === (int) $seed['id'];
    }

    /** Make an existing version the active one again. */
    public static function activate(int $id): void
    {
        $row = Database::selectOne('SELECT template_key FROM prompt_templates WHERE id = ?', [$id]);

        if ($row === null) {
            throw new RuntimeException('No such prompt version.');
        }

        Database::beginTransaction();

        try {
            Database::run('UPDATE prompt_templates SET is_active = 0 WHERE template_key = ?', [$row['template_key']]);
            Database::run('UPDATE prompt_templates SET is_active = 1 WHERE id = ?', [$id]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();

            throw $e;
        }
    }
}
