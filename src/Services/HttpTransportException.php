<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * The request never got as far as a response: DNS, connect, TLS or timeout.
 *
 * Distinct from a non-2xx, which is a response and is handled by looking at it.
 * A pipeline stage catching this one knows the failure is very likely transient
 * and worth retrying; a 422 is not.
 */
final class HttpTransportException extends RuntimeException
{
}
