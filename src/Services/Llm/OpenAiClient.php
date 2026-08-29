<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Setting;
use App\Services\Http;
use App\Services\HttpResponse;
use App\Services\HttpTransportException;

/**
 * OpenAI chat completions, over plain HTTP.
 *
 * The same shape as the Anthropic client, deliberately: both implement
 * `LlmClient`, both are chosen by a setting, and nothing downstream can tell
 * which one ran except by reading the two provenance fields.
 *
 * Images go as `image_url` content parts carrying a `data:` URI, which is how
 * this API takes an image that is not already on the web.
 */
final class OpenAiClient implements LlmClient
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /** Comfortably inside the request-size ceiling, with room for the prompt. */
    private const MAX_REQUEST_BYTES = 20 * 1024 * 1024;

    private const MAX_TOKENS = 16000;
    private const TIMEOUT    = 300;

    private string $apiKey;
    private string $model;
    private string $endpoint;

    /**
     * $endpoint exists so the client can be pointed at a local stub in tests.
     * Nothing in the application passes it; production always talks to
     * api.openai.com.
     */
    public function __construct(?string $apiKey = null, ?string $model = null, ?string $endpoint = null)
    {
        $this->apiKey   = $apiKey ?? (string) Setting::secret('openai_api_key');
        $this->model    = $model ?? (string) Setting::get('llm_ocr_model', 'gpt-4o');
        $this->endpoint = $endpoint ?? self::ENDPOINT;

        if ($this->apiKey === '') {
            throw new LlmException('No OpenAI API key is configured. Set one in Settings.');
        }
    }

    public function provider(): string
    {
        return 'openai';
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

        $parts   = [];
        $encoded = 0;

        foreach ($images as $image) {
            $encoded += $image->encodedBytes();

            $parts[] = [
                'type'      => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $image->mediaType() . ';base64,' . $image->base64(),

                    // The scans carry small handwriting; "low" would downsample
                    // to a thumbnail and lose exactly what is being read.
                    'detail' => 'high',
                ],
            ];
        }

        if ($encoded > self::MAX_REQUEST_BYTES) {
            throw new LlmException(sprintf(
                'These %d pages come to %d MB encoded, which is more than the request can carry. '
                . 'Lower the render DPI in Settings, or split the document.',
                count($images),
                (int) round($encoded / 1024 / 1024)
            ));
        }

        $parts[] = ['type' => 'text', 'text' => $prompt];

        return $this->respond($parts);
    }

    /**
     * Send one user turn and turn the reply into an LlmResponse.
     *
     * Shared by `ocr()` and `complete()` so the two differ only in what they put
     * in the content array.
     *
     * @param array<int,array<string,mixed>> $parts
     */
    private function respond(array $parts): LlmResponse
    {
        $started = microtime(true);

        $response = $this->send($parts, self::MAX_TOKENS);
        $elapsed  = (int) round((microtime(true) - $started) * 1000);

        if (!$response->ok()) {
            throw $this->error($response);
        }

        $body = $response->json();

        if ($body === null) {
            throw new LlmException('OpenAI answered with something that is not JSON.', true);
        }

        $choice = $body['choices'][0] ?? null;
        $text   = (string) ($choice['message']['content'] ?? '');

        if (trim($text) === '') {
            $refusal = $choice['message']['refusal'] ?? null;

            if (is_string($refusal) && $refusal !== '') {
                throw new LlmException('OpenAI declined this request: ' . $refusal);
            }

            throw new LlmException('OpenAI returned an empty answer.', true);
        }

        // An answer cut off at the limit would be read downstream as a whole
        // one, and the totals would quietly not add up.
        if (($choice['finish_reason'] ?? '') === 'length') {
            throw new LlmException(
                'The answer was cut off at the token limit. The document is probably too long '
                . 'to handle in one call.'
            );
        }

        return new LlmResponse(
            text:             $text,
            provider:         $this->provider(),
            model:            (string) ($body['model'] ?? $this->model),
            promptTokens:     isset($body['usage']['prompt_tokens']) ? (int) $body['usage']['prompt_tokens'] : null,
            completionTokens: isset($body['usage']['completion_tokens']) ? (int) $body['usage']['completion_tokens'] : null,
            durationMs:       $elapsed,
            raw:              ['finish_reason' => $choice['finish_reason'] ?? null, 'usage' => $body['usage'] ?? []],
        );
    }

    public function complete(string $prompt): LlmResponse
    {
        return $this->respond([['type' => 'text', 'text' => $prompt]]);
    }

    public function ping(): array
    {
        try {
            $response = $this->send([['type' => 'text', 'text' => 'Reply with the single word: ok']], 16, 30);
        } catch (HttpTransportException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if ($response->ok()) {
            return ['ok' => true, 'message' => 'Connected, and ' . $this->model . ' answered.'];
        }

        return ['ok' => false, 'message' => $this->error($response)->getMessage()];
    }

    /**
     * Post the request, coping with the two names this API has for the output
     * limit.
     *
     * Older models take `max_tokens`; newer ones reject it and require
     * `max_completion_tokens`. Which applies depends on the model id typed into
     * Settings, so rather than keep a list of model names that will be out of
     * date within months, this sends the newer name and retries with the older
     * one when the API says that is the problem. One extra round trip, once,
     * only on a misconfigured pairing.
     *
     * @param array<int,array<string,mixed>> $parts
     */
    private function send(array $parts, int $maxTokens, int $timeout = self::TIMEOUT): HttpResponse
    {
        $payload = [
            'model'    => $this->model,
            'messages' => [['role' => 'user', 'content' => $parts]],
        ];

        try {
            /*
             * Two retries inside the call. A completion is safe to repeat:
             * doing so costs money and produces text, and nothing else. See
             * AnthropicClient for the fuller note on why this is not the same
             * as retrying a Clear Books POST.
             */
            $response = Http::postJson(
                $this->endpoint,
                $payload + ['max_completion_tokens' => $maxTokens],
                $this->headers(),
                $timeout,
                2
            );
        } catch (HttpTransportException $e) {
            throw new LlmException($e->getMessage(), true, null, [
                'provider' => 'openai',
                'model'    => $this->model,
                'endpoint' => $this->endpoint,
            ]);
        }

        if ($response->status === 400) {
            $message = strtolower((string) ($response->json()['error']['message'] ?? ''));

            if (str_contains($message, 'max_completion_tokens') || str_contains($message, 'max_tokens')) {
                try {
                    return Http::postJson(
                        $this->endpoint,
                        $payload + ['max_tokens' => $maxTokens],
                        $this->headers(),
                        $timeout,
                        2
                    );
                } catch (HttpTransportException $e) {
                    throw new LlmException($e->getMessage(), true, null, [
                        'provider' => 'openai',
                        'model'    => $this->model,
                        'endpoint' => $this->endpoint,
                    ]);
                }
            }
        }

        return $response;
    }

    private function error(HttpResponse $response): LlmException
    {
        $body    = $response->json();
        $message = (string) ($body['error']['message'] ?? $response->errorSummary());

        $retryable = $response->status === 429 || $response->status >= 500;

        $friendly = match ($response->status) {
            401 => 'OpenAI rejected the API key.',
            403 => 'That OpenAI key is not permitted to use ' . $this->model . '.',
            404 => 'OpenAI has no model called ' . $this->model . '. Check the model id in Settings.',
            413 => 'The request was too large for OpenAI. Lower the render DPI in Settings.',
            429 => 'OpenAI rate-limited the request. It will be retried.',
            default => 'OpenAI returned ' . $response->status . '. ' . $message,
        };

        return new LlmException($friendly, $retryable, $response->status, [
            'provider' => 'openai',
            'model'    => $this->model,

            // The provider's own words. The friendly message above is a
            // translation, and a translation is exactly what you do not want
            // when the translation is the thing that is wrong.
            'answered' => mb_substr(trim($message), 0, 400),

            'took ms'  => $response->durationMs,
        ], Http::retryAfter($response));
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }
}
