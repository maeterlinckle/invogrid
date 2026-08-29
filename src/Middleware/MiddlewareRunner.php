<?php

declare(strict_types=1);

namespace App\Middleware;

use RuntimeException;

/**
 * Resolves the middleware names used in the route table.
 *
 *   'auth'                  must be signed in
 *   'guest'                 must NOT be signed in
 *   'can:review.resolve'    must hold the capability (implies auth)
 *   'canany:a.b,c.d'        must hold at least one of them
 *   'role:admin'            must hold that role or a more senior one
 *   'csrf'                  verify the CSRF token on state-changing requests
 */
final class MiddlewareRunner
{
    /** @param array<int,string> $middleware */
    public static function run(array $middleware): void
    {
        foreach ($middleware as $definition) {
            $name      = $definition;
            $parameter = null;

            if (str_contains($definition, ':')) {
                [$name, $parameter] = explode(':', $definition, 2);
            }

            match ($name) {
                'auth'   => AuthMiddleware::handle(),
                'guest'  => GuestMiddleware::handle(),
                'can'    => CapabilityMiddleware::handle((string) $parameter),
                'canany' => CapabilityMiddleware::handleAny(explode(',', (string) $parameter)),
                'role'   => CapabilityMiddleware::handleRole((string) $parameter),
                'csrf'   => CsrfMiddleware::handle(),
                default  => throw new RuntimeException('Unknown middleware: ' . $name),
            };
        }
    }
}
