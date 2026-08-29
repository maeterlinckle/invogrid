<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * A Clear Books call that failed, carrying whether trying again could help.
 *
 * The same judgement `LlmException` makes, for the same reason: only the client
 * that made the call can tell a rate limit from a rejected token, and
 * `Pipeline::isPermanent()` needs that distinction to decide between backing
 * off and stopping the document.
 *
 *  - **Retryable**: 429, 5xx, a transport failure. Clear Books throttles above
 *    five requests a second and says so with a 429; that is a wait, not a bug.
 *  - **Not retryable**: 400, 401, 403, 404, 422. A malformed request, a revoked
 *    authorisation or a business rule the document breaks will fail identically
 *    four more times.
 */
class ClearBooksException extends RuntimeException implements Diagnosable
{
    /**
     * @param array<string,scalar|null> $context
     */
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly int $status = 0,
        ?Throwable $previous = null,
        private readonly array $context = [],
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string,scalar|null> */
    public function context(): array
    {
        return array_filter(
            ['service' => 'Clear Books'] + $this->context + [
                'http status' => $this->status > 0 ? $this->status : null,
                'retryable'   => $this->retryable ? 'yes' : 'no',
            ],
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
