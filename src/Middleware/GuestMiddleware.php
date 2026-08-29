<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Response;

final class GuestMiddleware
{
    public static function handle(): void
    {
        if (Auth::check()) {
            Response::redirect('/');
        }
    }
}
