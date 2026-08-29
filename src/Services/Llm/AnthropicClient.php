<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Setting;
use App\Services\Http;
use App\Services\HttpResponse;
use App\Services\HttpTransportException;

/**
 * Anthropic Messages API, over plain HTTP.
 *
 * Raw HTTP rather than the official PHP SDK because this application has no
 * Composer and no vendor directory at all — that is a project-wide decision,
 * not a preference about this endpoint. The request shape below is the
 * documented wire format.
 *
 * Authentication is `x-api-key` plus `anthropic-version`, not a bearer token.
 */
final class AnthropicClient implements LlmClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION  = '2023-06-01';

    /**
     * The whole request, base64 included, must fit inside 32 MB.
     *
     * Checked before sending: a rejected 40 MB request costs the same wall
     * clock as an accepted one and tells the user nothing useful.
     */
    private const MAX_REQUEST_BYTES = 32 * 1024 * 1024;

    /**
     * Transcribing a dense multi-page invoice is a long generation, and a
     * truncated transcript is worse than none — the extraction stage would
     * happily read half a document and report totals that do not add up.
     */
    private const MAX_TOKENS = 16000;

    /** Vision calls are slow. This is the whole request, not per page. */
    private const TIMEOUT = 300;

    private string $apiKey;
    private string $model;
    private string $endpoint;

    /**
     * $endpoint exists so the client can be pointed at a local stub in tests.
     * Nothing in the application passes it; production always talks to
     * api.anthropic.com.
     */
    public function __construct(?string $apiKey = null, ?string $model = null, ?string $endpoint = null)
    {
        $this->apiKey   = $apiKey ?? (string) Setting::secret('anthropic_api_key');
        $this->model    = $model ?? (string) Setting::get('llm_ocr_model', 'claude-opus-5');
        $this->endpoint = $endpoint ?? self::ENDPOINT;

        if ($this->apiKey === '') {
            throw new LlmException('No Anthropic API key is configured. Set one in Settings.');
        }
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function ocr(array $images, string $prompt): LlmResponse
    {
        if ($images === []) {
            throw new LlmException('No page images to transcribe.');
        }

        $content = [];
        $encoded = 0;

        // Images first, then the instruction. A model reads the request in
        // order, and the instruction lands better once it has seen what it is
        // being asked about.
        foreach ($images as $image) {
            $encoded += $image->encodedBytes();

            $content[] = [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $image->mediaType(),
                    'data'       => $image->base64(),
                ],
            ];
        }

        if ($encoded > self::MAX_REQUEST_BYTES) {
            throw new LlmException(sprintf(
                'These %d pages come to %d MB encoded, past Anthropic\'s 32 MB request limit. '
                . 'Lower the render DPI in Settings, or split the document.',
                count($images),
                (int) round($encoded / 1024 / 1024)
            ));
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        return $this->send($content);
    }

    /**
     * Send one user turn and turn the reply into an LlmResponse.
     *
     * Shared by `ocr()` and `complete()` so the two differ only in what they put
     * in the content array — every rule about thinking, fallbacks, refusals and
     * truncation applies identically to both.
     *
     * @param array<int,array<string,mixed>> $content
     */
    private function send(array $content): LlmResponse
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => self::MAX_TOKENS,

            // Reading a biro annotation and deciding whether a number is a
            // Clear Books reference or a printed invoice number is a judgement,
            // not a transcription. Adaptive thinking is what makes it one.
            'thinking'   => ['type' => 'adaptive'],

            'messages'   => [
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $headers = $this->headers();

        // Safety classifiers occasionally decline a benign request. The
        // server-side fallback re-runs it on another model in the same round
        // trip, which is far better than a document stuck in `failed` because
        // one scan tripped a classifier. Only sent for the model families that
        // accept the parameter — on anything else it is a 400.
        if ($this->supportsServerSideFallback()) {
            $payload['fallbacks']          = 'default';
            $headers['anthropic-beta']     = 'server-side-fallback-2026-07-01';
        }

        $started = microtime(true);

        try {
            /*
             * Two retries inside the call.
             *
             * A completion is safe to repeat: repeating it costs money and
             * produces text, and produces nothing else — unlike a Clear Books
             * POST, which may have written a bill before the connection
             * dropped. Handling a 429 here rather than by failing the stage
             * matters because extraction makes four calls, and losing the
             * fourth to a rate limit throws away the three that worked.
             */
            $response = Http::postJson($this->endpoint, $payload, $headers, self::TIMEOUT, 2);
        } catch (HttpTransportException $e) {
            throw new LlmException($e->getMessage(), true, null, [
                'provider' => 'anthropic',
                'model'    => $this->model,
                'endpoint' => $this->endpoint,
                'took ms'  => (int) round((microtime(true) - $started) * 1000),
            ]);
        }

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if (!$response->ok()) {
            throw $this->error($response);
        }

        $body = $response->json();

        if ($body === null) {
            throw new LlmException('Anthropic answered with something that is not JSON.', true);
        }

        // Checked before the content is read, not after: on a refusal the
        // content array can be empty, and code that reaches for content[0]
        // breaks in a way that reads like a parsing bug.
        $stopReason = (string) ($body['stop_reason'] ?? '');

        if ($stopReason === 'refusal') {
            $category = (string) ($body['stop_details']['category'] ?? 'unspecified');

            throw new LlmException(
                'Anthropic declined this request (' . $category . '). '
                . 'This is unusual for a purchase invoice — check the scan is what you think it is, '
                . 'or switch the provider in Settings.'
            );
        }

        $text = $this->text($body);

        if (trim($text) === '') {
            throw new LlmException('Anthropic returned an empty answer.', true);
        }

        if ($stopReason === 'max_tokens') {
            // Silently keeping a truncated transcript is the failure that
            // produces confidently wrong totals two stages later.
            throw new LlmException(
                'The answer was cut off at the token limit. The document is probably too long '
                . 'to handle in one call.'
            );
        }

        return new LlmResponse(
            text:             $text,
            provider:         $this->provider(),
            model:            (string) ($body['model'] ?? $this->model),
            promptTokens:     isset($body['usage']['input_tokens']) ? (int) $body['usage']['input_tokens'] : null,
            completionTokens: isset($body['usage']['output_tokens']) ? (int) $body['usage']['output_tokens'] : null,
            durationMs:       $elapsed,
            raw:              ['stop_reason' => $stopReason, 'usage' => $body['usage'] ?? []],
        );
    }

    public function complete(string $prompt): LlmResponse
    {
        return $this->send([['type' => 'text', 'text' => $prompt]]);
    }

    public function ping(): array
    {
        try {
            $response = Http::postJson(
                $this->endpoint,
                [
                    'model'      => $this->model,
                    'max_tokens' => 16,
                    'messages'   => [['role' => 'user', 'content' => 'Reply with the single word: ok']],
                ],
                $this->headers(),
                30
            );
        } catch (HttpTransportException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if ($response->ok()) {
            return ['ok' => true, 'message' => 'Connected, and ' . $this->model . ' answered.'];
        }

        return ['ok' => false, 'message' => $this->error($response)->getMessage()];
    }

    /**
     * Only the Opus 5 and Fable 5 families take the `fallbacks` parameter.
     * Sending it to anything else is a 400, which would turn a working setup
     * into a broken one the moment somebody chose a cheaper model in Settings.
     */
    private function supportsServerSideFallback(): bool
    {
        return str_starts_with($this->model, 'claude-opus-5')
            || str_starts_with($this->model, 'claude-fable-5');
    }

    /** @param array<string,mixed> $body */
    private function text(array $body): string
    {
        $parts = [];

        // Only the text blocks. With adaptive thinking the content array also
        // carries thinking blocks, whose text is empty by default and is not
        // ours to read in any case.
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return implode("\n", $parts);
    }

    private function error(HttpResponse $response): LlmException
    {
        $body    = $response->json();
        $message = (string) ($body['error']['message'] ?? $response->errorSummary());

        // 429 and 5xx are worth another go in a minute; a bad key or a
        // malformed request will fail identically forever.
        $retryable = $response->status === 429 || $response->status >= 500;

        $friendly = match ($response->status) {
            401 => 'Anthropic rejected the API key.',
            403 => 'That Anthropic key is not permitted to use ' . $this->model . '.',
            404 => 'Anthropic has no model called ' . $this->model . '. Check the model id in Settings.',
            413 => 'The request was too large for Anthropic. Lower the render DPI in Settings.',
            429 => 'Anthropic rate-limited the request. It will be retried.',
            default => 'Anthropic returned ' . $response->status . '. ' . $message,
        };

        return new LlmException($friendly, $retryable, $response->status, [
            'provider' => 'anthropic',
            'model'    => $this->model,

            // The provider's own words, kept whole enough to be worth reading
            // and short enough not to fill a page. The friendly message above
            // is a translation, and a translation is exactly what you do not
            // want when the translation is the thing that is wrong.
            'answered' => mb_substr(trim($message), 0, 400),

            'took ms'  => $response->durationMs,
        ], Http::retryAfter($response));
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::VERSION,
        ];
    }
}
