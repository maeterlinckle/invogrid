<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Clear Books is not usable until somebody signs in again.
 *
 * Its own class because the fix is a human action in the Clear Books admin
 * screen, not a retry: the refresh token has been used, revoked, or was never
 * there. Every pipeline stage that meets one should say so plainly rather than
 * report "Clear Books returned 401", which reads as an outage.
 *
 * Never retryable, by construction.
 */
final class ClearBooksAuthException extends ClearBooksException
{
    public function __construct(string $message, int $status = 401)
    {
        parent::__construct($message, false, $status);
    }
}
