<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Setting;

/**
 * Builds the client for a pipeline stage from the settings.
 *
 * The one place that reads `llm_<stage>_provider` and `llm_<stage>_model`, so
 * a stage asks for "whatever runs OCR" and never for a named provider. Choosing
 * per stage rather than globally is deliberate: transcription and structured
 * extraction have different strengths and different costs, and a site may
 * reasonably want a different model for each.
 */
final class LlmFactory
{
    /** The stages that pick a provider. Adding one is a settings row plus a case here. */
    public const STAGES = ['ocr', 'extraction'];

    public const PROVIDERS = ['anthropic', 'openai'];

    public static function forStage(string $stage): LlmClient
    {
        if (!in_array($stage, self::STAGES, true)) {
            throw new LlmException('No LLM configuration for stage ' . $stage . '.');
        }

        $provider = self::provider($stage);
        $model    = self::model($stage);

        return match ($provider) {
            'anthropic' => new AnthropicClient(null, $model, self::endpoint('anthropic')),
            'openai'    => new OpenAiClient(null, $model, self::endpoint('openai')),
            default     => throw new LlmException(
                'Unknown LLM provider "' . $provider . '" for the ' . $stage . ' stage. '
                . 'It must be one of: ' . implode(', ', self::PROVIDERS) . '.'
            ),
        };
    }

    /**
     * The endpoint to call, or null for the provider's own.
     *
     * The setting holds a base URL — `https://gateway.example.com` — and the
     * path is appended here, so an administrator does not have to know that one
     * provider's path is `/v1/messages` and the other's is
     * `/v1/chat/completions`. Empty is the normal case and means "go direct".
     */
    private static function endpoint(string $provider): ?string
    {
        $base = trim((string) Setting::get($provider . '_base_url', ''));

        if ($base === '') {
            return null;
        }

        return rtrim($base, '/') . match ($provider) {
            'anthropic' => '/v1/messages',
            'openai'    => '/v1/chat/completions',
        };
    }

    public static function provider(string $stage): string
    {
        return (string) Setting::get('llm_' . $stage . '_provider', 'anthropic');
    }

    public static function model(string $stage): string
    {
        return (string) Setting::get('llm_' . $stage . '_model', 'claude-opus-5');
    }

    /**
     * Is the provider chosen for this stage actually usable?
     *
     * Used by the dashboard's setup checklist, which should complain about the
     * key for the provider in use rather than about every key.
     */
    public static function isConfigured(string $stage): bool
    {
        return match (self::provider($stage)) {
            'anthropic' => Setting::isConfigured('anthropic_api_key'),
            'openai'    => Setting::isConfigured('openai_api_key'),
            default     => false,
        };
    }

    /** The settings key holding the API key for a stage's provider. */
    public static function keyName(string $stage): string
    {
        return self::provider($stage) === 'openai' ? 'openai_api_key' : 'anthropic_api_key';
    }
}
