<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Services\Diagnosable;
use RuntimeException;

/**
 * A provider call that did not produce a usable answer.
 *
 * `$retryable` is the useful part: a rate limit or a 502 will very likely work
 * in a minute, while a rejected API key or an image the provider will not accept
 * will not. The pipeline uses it to decide between backing off and stopping.
 *
 * `$context` is what makes the failure answerable afterwards. The message says
 * "OpenAI rate-limited the request"; the context says which model, which of the
 * four extraction calls, what the body actually said and how long it took —
 * which is the difference between reading the document page and reading the
 * server log.
 */
final class LlmException extends RuntimeException implements Diagnosable
{
    /**
     * @param array<string,scalar|null> $context
     */
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
        private readonly array $context = [],
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /** @return array<string,scalar|null> */
    public function context(): array
    {
        return array_filter(
            $this->context + [
                'http status' => $this->status,
                'retryable'   => $this->retryable ? 'yes' : 'no',
            ],
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * The same exception, told which call it happened on.
     *
     * The client knows the provider, the model and the status; only the stage
     * knows that this was the supplier call rather than the header one. Rather
     * than thread a label down through every client method, the stage adds it
     * on the way back up.
     */
    public function during(string $call): self
    {
        return new self(
            $this->getMessage(),
            $this->retryable,
            $this->status,
            ['call' => $call] + $this->context,
            $this->retryAfter
        );
    }
}
