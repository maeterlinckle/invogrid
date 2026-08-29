<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;

/**
 * One HTTP response, with the small amount of interpretation every caller
 * would otherwise write for itself.
 */
final class HttpResponse
{
    /** @param array<string,string> $headers Header names lower-cased. */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly int $durationMs = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The body decoded as JSON, or null when it is not JSON at all.
     *
     * Null rather than an exception: an API that answers with an HTML error
     * page is a normal thing to have to handle, and the caller has the status
     * code to tell it what happened.
     *
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }

        try {
            $decoded = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A short description of a failure, for an error message or a log line.
     *
     * Bodies are truncated: an API that returns a page of HTML on error would
     * otherwise put all of it in the database, and the first line is the part
     * anyone reads.
     */
    public function errorSummary(): string
    {
        $body = trim($this->body);

        if ($body === '') {
            return 'HTTP ' . $this->status . ' with an empty body.';
        }

        // A structured error is far more useful than the raw body, and every
        // API here has its own idea of where to put it.
        $decoded = $this->json();

        if ($decoded !== null) {
            foreach (['detail', 'message', 'error', 'error_description'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    return 'HTTP ' . $this->status . ': ' . $decoded[$key];
                }
            }
        }

        return 'HTTP ' . $this->status . ': ' . str_limit(preg_replace('/\s+/', ' ', $body) ?? $body, 300);
    }
}
