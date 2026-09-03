<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use RuntimeException;

/**
 * A candidate that was refused, or that could not be stored.
 *
 * The message is written to be shown to whoever offered the file — it says what
 * was wrong with *this* document, not what went wrong inside InvoGrid. A
 * watched-directory route will log the same sentence beside the filename it
 * could not accept, which is the only thing anybody reading that log wants.
 */
final class IngestException extends RuntimeException
{
}
