<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Paperless does not have that document.
 *
 * Its own exception because it is the one Paperless failure that is not worth
 * retrying: a document deleted between the webhook firing and the queue running
 * will not come back, and the pipeline should stop rather than back off and try
 * again every minute forever.
 */
final class PaperlessNotFoundException extends RuntimeException
{
}
