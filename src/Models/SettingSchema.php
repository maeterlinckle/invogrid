<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Llm\LlmFactory;

/**
 * What the Settings screen may edit, and how each row should be presented.
 *
 * One declarative table rather than a form built by hand, for the same reason
 * `document_types` is a table rather than an enum: adding a setting should be
 * an entry here plus a seeded row, not a controller change and a template
 * change that have to be kept in step. The controller validates from this and
 * the template renders from it; neither holds a list of its own.
 *
 * **Not every settings row appears here, deliberately.** Three kinds are left
 * out:
 *
 *  - `logo_*` — the Branding screen owns those, and a storage path typed by
 *    hand into a text box is a path to a file that is not there.
 *  - `clearbooks_access_token`, `clearbooks_refresh_token` and
 *    `clearbooks_token_expires_at` — the consent flow writes them. Offering
 *    them as fields invites somebody to break a working connection by hand,
 *    and there is nothing useful they could type.
 *  - `clearbooks_invoice_sync_interval_minutes` and
 *    `clearbooks_invoice_sync_last_run` — already on the Clear Books screen,
 *    beside the sync they govern. A second copy here would be two controls for
 *    one value, and the second is never the one somebody looks at.
 *
 * `tests/smoke.php` asserts that every key named here exists as a seeded row,
 * so a typo is a failed test rather than a field that silently never saves.
 */
final class SettingSchema
{
    /** Field kinds. `secret` is the one with special handling everywhere. */
    public const TEXT     = 'text';
    public const URL      = 'url';
    public const INTEGER  = 'integer';
    public const BOOLEAN  = 'boolean';
    public const SELECT   = 'select';
    public const SECRET   = 'secret';
    public const TEXTAREA = 'textarea';

    /**
     * How each provider spells its own name.
     *
     * `ucfirst()` offers "Openai", and a screen that cannot spell the name of
     * the thing it is configuring does not inspire much confidence about what
     * it does with the key. A provider missing from here falls back to
     * `ucfirst()` rather than disappearing.
     */
    public const PROVIDER_LABELS = ['anthropic' => 'Anthropic', 'openai' => 'OpenAI'];

    /**
     * The cards, in the order they appear. A section name is also the URL
     * segment its form posts to, so it has to stay `[a-z_]+`.
     *
     * @var array<string,array{title:string,blurb:string}>
     */
    public const SECTIONS = [
        'instance' => [
            'title' => 'This instance',
            'blurb' => 'What InvoGrid calls itself, on screen and on paper.',
        ],
        'ingest' => [
            'title' => 'Incoming documents',
            'blurb' => 'What InvoGrid will accept from the upload page, and from any ingest route added later.',
        ],
        'clearbooks' => [
            'title' => 'Clear Books',
            'blurb' => 'The application credentials and the addresses. Authorising the connection happens on the Clear Books screen — this is what it needs before it can.',
        ],
        'llm' => [
            'title' => 'Language models',
            'blurb' => 'Which model reads a page, and which one turns the reading into fields. Separate choices because they are different jobs with different costs.',
        ],
        'rendering' => [
            'title' => 'Page images',
            'blurb' => 'How a PDF page becomes the image the vision model is shown. Larger is not always better, and it is always more expensive.',
        ],
        'thresholds' => [
            'title' => 'Noticing trouble',
            'blurb' => 'How long something may sit still before the dashboard calls it stuck.',
        ],
    ];

    /**
     * Every editable setting.
     *
     * `rule` is a `Validator` rule string. Without `required` it is applied
     * only when the box is not left empty — empty means "no value", which is
     * legitimate for most of these and means "leave it alone" for a secret.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function fields(): array
    {
        $providers = [];

        foreach (LlmFactory::PROVIDERS as $provider) {
            $providers[$provider] = self::providerLabel($provider);
        }

        return [
            // --- This instance --------------------------------------------
            'organisation_name' => [
                'section' => 'instance',
                'label'   => 'Organisation name',
                'type'    => self::TEXT,
                'rule'    => 'required|max:120',
                'hint'    => 'Appears in the footer and on every printed document summary.',
            ],
            'flash_auto_hide_seconds' => [
                'section' => 'instance',
                'label'   => 'Fade a success message after',
                'type'    => self::INTEGER,
                'rule'    => 'integer|min_value:0|max_value:60',
                'hint'    => 'Seconds. 0 keeps it on screen until it is dismissed. Warnings and errors never fade whatever this says — a failure that vanishes on its own is a failure nobody read.',
            ],

            // --- Incoming documents ----------------------------------------
            'ingest_max_upload_mb' => [
                'section' => 'ingest',
                'label'   => 'Largest document accepted',
                'type'    => self::INTEGER,
                'rule'    => 'required|integer|min_value:1|max_value:200',
                'hint'    => 'Megabytes. A colour A4 page scanned at 300dpi is a couple of megabytes, so 25 is roomy for an ordinary invoice. PHP’s own upload_max_filesize and post_max_size still outrank this and cannot be raised from here — the upload page quotes whichever limit is actually the smallest.',
            ],

            // --- Clear Books -----------------------------------------------
            'clearbooks_client_id' => [
                'section' => 'clearbooks',
                'label'   => 'Client id',
                'type'    => self::TEXT,
                'rule'    => 'max:191',
                'hint'    => 'Issued by Clear Books when the application credentials are created. Not generated here.',
            ],
            'clearbooks_client_secret' => [
                'section' => 'clearbooks',
                'label'   => 'Client secret',
                'type'    => self::SECRET,
                'rule'    => 'max:255',
                'hint'    => 'Issued with the client id, and shown by Clear Books once.',
            ],
            'clearbooks_business_id' => [
                'section' => 'clearbooks',
                'label'   => 'Business id',
                'type'    => self::TEXT,
                'rule'    => 'max:64',
                'hint'    => 'Which Clear Books business documents are posted into.',
            ],
            'clearbooks_base_url' => [
                'section'     => 'clearbooks',
                'label'       => 'API address',
                'type'        => self::URL,
                'rule'        => 'url|max:255',
                'hint'        => 'Where the API lives — not the same host as the web interface.',
                'placeholder' => 'https://api.clearbooks.co.uk',
            ],
            'clearbooks_web_url' => [
                'section'     => 'clearbooks',
                'label'       => 'Web address',
                'type'        => self::URL,
                'rule'        => 'url|max:255',
                'hint'        => 'Used to build the “Open in Clear Books” link a reviewer follows.',
                'placeholder' => 'https://secure.clearbooks.co.uk',
            ],
            'clearbooks_authorise_url' => [
                'section' => 'clearbooks',
                'label'   => 'Authorisation address',
                'type'    => self::URL,
                'rule'    => 'url|max:255',
                'hint'    => 'Where a person is sent to grant consent. If the connect button leads to a 404, this and the API address are the first pair to check: they are different hosts and easy to swap.',
            ],
            'clearbooks_redirect_uri' => [
                'section' => 'clearbooks',
                'label'   => 'Redirect address',
                'type'    => self::URL,
                'rule'    => 'url|max:255',
                'hint'    => 'Where Clear Books sends the browser back to. Leave it empty unless this instance is reached on a different address from APP_URL — empty means APP_URL plus /admin/clearbooks/callback. Whatever is used has to be the address registered with Clear Books.',
            ],
            'clearbooks_scopes' => [
                'section' => 'clearbooks',
                'label'   => 'Scopes requested',
                'type'    => self::TEXTAREA,
                'rule'    => 'required|max:1000',
                'hint'    => 'Exactly what InvoGrid needs and no more: read access to the lists it caches, write access to the two things it creates. Sales, payments, journals and bank feeds are deliberately absent, and tests/smoke.php asserts this list — a scope added by accident fails a test rather than quietly granting the integration the run of the ledger. A change takes effect at the next authorisation, not immediately.',
            ],
            'clearbooks_cache_ttl_minutes' => [
                'section' => 'clearbooks',
                'label'   => 'Cached lists stay fresh for',
                'type'    => self::INTEGER,
                'rule'    => 'integer|min_value:5|max_value:10080',
                'hint'    => 'Minutes. How long suppliers, account codes and VAT rates are used before a refresh is due. The cron refresh and the “refresh now” button both fetch regardless.',
            ],
            'clearbooks_attach_pdf' => [
                'section' => 'clearbooks',
                'label'   => 'Attach the PDF to the Clear Books record',
                'type'    => self::BOOLEAN,
                'rule'    => 'boolean',
                'hint'    => 'On: the accounts package holds the evidence, not just a reference to it.',
            ],

            // --- Language models -------------------------------------------
            'llm_ocr_provider' => [
                'section' => 'llm',
                'label'   => 'Reading a page — provider',
                'type'    => self::SELECT,
                'rule'    => 'required|in:' . implode(',', LlmFactory::PROVIDERS),
                'options' => $providers,
                'hint'    => 'The vision call that turns page images into a transcription.',
            ],
            'llm_ocr_model' => [
                'section'     => 'llm',
                'label'       => 'Reading a page — model',
                'type'        => self::TEXT,
                'rule'        => 'required|max:120',
                'hint'        => 'A model identifier spelled as the provider spells it. Not checked against a list here: a model released tomorrow should not need a deploy.',
                'placeholder' => 'claude-sonnet-5',
            ],
            'llm_extraction_provider' => [
                'section' => 'llm',
                'label'   => 'Turning it into fields — provider',
                'type'    => self::SELECT,
                'rule'    => 'required|in:' . implode(',', LlmFactory::PROVIDERS),
                'options' => $providers,
                'hint'    => 'The structured calls that produce the header, the lines and the custom fields.',
            ],
            'llm_extraction_model' => [
                'section'     => 'llm',
                'label'       => 'Turning it into fields — model',
                'type'        => self::TEXT,
                'rule'        => 'required|max:120',
                'hint'        => 'As above. This one never sees the page, only the transcription.',
                'placeholder' => 'claude-sonnet-5',
            ],
            'anthropic_api_key' => [
                'section' => 'llm',
                'label'   => 'Anthropic API key',
                'type'    => self::SECRET,
                'rule'    => 'max:255',
                'hint'    => 'Needed only if a stage above is set to Anthropic.',
            ],
            'openai_api_key' => [
                'section' => 'llm',
                'label'   => 'OpenAI API key',
                'type'    => self::SECRET,
                'rule'    => 'max:255',
                'hint'    => 'Needed only if a stage above is set to OpenAI.',
            ],
            'anthropic_base_url' => [
                'section'     => 'llm',
                'label'       => 'Anthropic gateway',
                'type'        => self::URL,
                'rule'        => 'url|max:255',
                'hint'        => 'Optional. A base URL to call instead of the provider directly; the path is appended, so give the host only. Empty means go direct.',
                'placeholder' => 'https://gateway.example.com',
            ],
            'openai_base_url' => [
                'section'     => 'llm',
                'label'       => 'OpenAI gateway',
                'type'        => self::URL,
                'rule'        => 'url|max:255',
                'hint'        => 'Optional, as above.',
                'placeholder' => 'https://gateway.example.com',
            ],

            // --- Page images -----------------------------------------------
            'pdf_render_dpi' => [
                'section' => 'rendering',
                'label'   => 'Render at',
                'type'    => self::INTEGER,
                'rule'    => 'integer|min_value:72|max_value:400',
                'hint'    => 'Dots per inch, 72 to 400. The renderer clamps to that range, so a value outside it would be accepted here and then ignored there. 200 reads small print without producing images that cost more than they are worth.',
            ],
            'pdf_max_edge_px' => [
                'section' => 'rendering',
                'label'   => 'Longest edge at most',
                'type'    => self::INTEGER,
                'rule'    => 'integer|min_value:512|max_value:4096',
                'hint'    => 'Pixels, 512 to 4096, and clamped to that range by the renderer for the same reason. A page rendered larger is scaled down before it is sent: most vision models resize anyway, and paying to upload pixels that are then thrown away is the commonest waste here.',
            ],
            'pdf_render_format' => [
                'section' => 'rendering',
                'label'   => 'Image format',
                'type'    => self::SELECT,
                'rule'    => 'required|in:jpeg,png',
                'options' => ['jpeg' => 'JPEG — smaller', 'png' => 'PNG — lossless'],
                'hint'    => 'JPEG unless a supplier uses fine line art that compression is visibly spoiling.',
            ],

            // --- Noticing trouble ------------------------------------------
            'stuck_pipeline_minutes' => [
                'section' => 'thresholds',
                'label'   => 'Stuck in the pipeline after',
                'type'    => self::INTEGER,
                'rule'    => 'integer|min_value:1|max_value:1440',
                'hint'    => 'Minutes. This catches the failure nothing else complains about: the queue has given up retrying, the status is not “failed”, and the document simply rots.',
            ],
            'stuck_review_days' => [
                'section' => 'thresholds',
                'label'   => 'Stuck in review after',
                'type'    => self::INTEGER,
                'rule'    => 'integer|min_value:1|max_value:365',
                'hint'    => 'Days. Long enough that a fortnight off does not fill the dashboard with warnings, short enough that a forgotten bill is noticed.',
            ],
        ];
    }

    /**
     * The fields in one section, in declaration order.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function forSection(string $section): array
    {
        return array_filter(
            self::fields(),
            static fn (array $field): bool => $field['section'] === $section
        );
    }

    public static function isSection(string $section): bool
    {
        return array_key_exists($section, self::SECTIONS);
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::fields());
    }

    /** A provider's name as it spells it, for a label or a sentence. */
    public static function providerLabel(string $provider): string
    {
        return self::PROVIDER_LABELS[$provider] ?? ucfirst($provider);
    }

    /** Is this key one a secret is stored under? */
    public static function isSecret(string $key): bool
    {
        return (self::fields()[$key]['type'] ?? '') === self::SECRET;
    }
}
