<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;

final class CsrfMiddleware
{
    public static function handle(): void
    {
        Csrf::verify();
    }
}
