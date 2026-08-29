<?php

declare(strict_types=1);

namespace App\Services\Llm;

/**
 * What a provider gave back, in the shape the rest of the application wants.
 *
 * Deliberately provider-neutral: nothing downstream should be able to tell
 * whether OpenAI or Anthropic produced it, beyond the two fields that say so
 * for the audit trail.
 */
final class LlmResponse
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly int $durationMs = 0,
        public readonly array $raw = [],
    ) {
    }

    /**
     * The response parsed as JSON, when the prompt asked for JSON.
     *
     * Models wrap JSON in a ```json fence more often than not, however firmly
     * the prompt says not to, so the fence is stripped before parsing rather
     * than treated as a failure.
     *
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        $text = trim($this->text);

        if ($text === '') {
            return null;
        }

        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text);
        }

        // Some models add a sentence before the JSON. Take the outermost object.
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
