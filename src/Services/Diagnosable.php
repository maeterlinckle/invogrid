<?php

declare(strict_types=1);

namespace App\Services;

/**
 * An exception that knows more about itself than its message says.
 *
 * `getMessage()` has to be one sentence, because it goes in a list and on a
 * badge. Everything an administrator actually needs in order to *act* on a
 * failure — which provider was called, with which model, what it answered, how
 * long it waited — does not fit there and gets lost if there is nowhere else to
 * put it.
 *
 * `Pipeline` stores whatever this returns on the failed `document_events` row,
 * and the document page renders it. That is the whole of the contract: an
 * exception that implements this is asking to be explained rather than merely
 * reported.
 */
interface Diagnosable
{
    /**
     * Facts about the failed call, for storage and display.
     *
     * Keys are free-form because a Clear Books failure and an LLM failure have
     * almost nothing in common, but keep them short and lower-case-with-spaces:
     * they are rendered straight into a definition list, not translated.
     *
     * **Never put a credential in here.** It is written to the database and
     * shown on a page.
     *
     * @return array<string,scalar|null>
     */
    public function context(): array;

    /**
     * Seconds the far end asked us to wait, if it said.
     *
     * Used in preference to the queue's own backoff curve. A provider that
     * knows its rate limit resets in four minutes is a better source than an
     * exponential guess, and retrying at sixty seconds against a four-minute
     * limit simply burns an attempt.
     */
    public function retryAfter(): ?int;
}
