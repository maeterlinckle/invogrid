<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\MiddlewareRunner;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:mixed,middleware:array<int,string>,name:?string}> */
    private array $routes = [];

    /** @var array<string,string> */
    private static array $named = [];

    /** @var array<int,string> */
    private array $groupMiddleware = [];

    /** @param array<int,string> $middleware */
    public function get(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $path, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function post(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $path, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function put(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('PUT', $path, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function delete(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('DELETE', $path, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function patch(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('PATCH', $path, $handler, $middleware, $name);
    }

    /**
     * Apply a set of middleware to every route registered inside the callback.
     *
     * @param array<int,string> $middleware
     */
    public function group(array $middleware, callable $callback): void
    {
        $previous              = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($previous, $middleware);

        $callback($this);

        $this->groupMiddleware = $previous;
    }

    /** @param array<int,string> $middleware */
    private function add(string $method, string $path, mixed $handler, array $middleware, ?string $name): void
    {
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $path,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'name'       => $name,
        ];

        if ($name !== null) {
            self::$named[$name] = $path;
        }
    }

    public static function path(string $name): string
    {
        return self::$named[$name] ?? '/';
    }

    /**
     * The whole table, for anything that needs to reason about it rather than
     * dispatch it.
     *
     * The smoke test walks this and asserts that every route outside a short,
     * named list of deliberately open ones carries a gate. "Enforce the role
     * check server-side on every protected route" is only true if something
     * checks; a route added without one is otherwise found by whoever finds it.
     *
     * @return array<int,array{method:string,pattern:string,handler:mixed,middleware:array<int,string>,name:?string}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(string $method, string $path): void
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $path);

            if ($params === null) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            Request::setRouteParams($params);

            MiddlewareRunner::run($route['middleware']);

            $this->call($route['handler'], $params);

            return;
        }

        // Under /api, a miss has to answer in JSON. A client that asked for data
        // and got an HTML error page has to guess what happened, and the guess
        // is usually "the server is broken" rather than "that path is wrong".
        $isApi = str_starts_with($path, '/api/') || $path === '/api';

        if ($allowedMethods !== []) {
            $allow = implode(', ', array_unique($allowedMethods));
            header('Allow: ' . $allow);
            http_response_code(405);

            if ($isApi) {
                self::apiError(405, 'method_not_allowed', 'That method is not available on this endpoint. Allowed: ' . $allow . '.');

                return;
            }

            View::renderError(405, 'Method not allowed', 'That action is not available on this page.');

            return;
        }

        http_response_code(404);

        if ($isApi) {
            self::apiError(404, 'not_found', 'No such endpoint. GET /api/v1 for the index, or open /api/docs.');

            return;
        }

        View::renderError(404, 'Page not found', 'The page you were looking for does not exist.');
    }

    /**
     * The API's error shape, for the two cases the router answers itself.
     *
     * Kept identical to App\Api\Problem's body by hand — a four-line duplicate
     * rather than pulling the API layer into the router, which would make the
     * core depend on a feature that can be switched off.
     */
    private static function apiError(int $status, string $code, string $message): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode(
            ['error' => ['status' => $status, 'code' => $code, 'message' => $message]],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    /**
     * @return array<string,string>|null Route parameters, or null when no match.
     */
    private function match(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }

        if (!str_contains($pattern, '{')) {
            return null;
        }

        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static function (array $m): string {
                $name    = $m[1];
                $subject = $m[2] ?? '[^/]+';

                return '(?P<' . $name . '>' . $subject . ')';
            },
            $pattern
        );

        if (!is_string($regex)) {
            return null;
        }

        if (preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /** @param array<string,string> $params */
    private function call(mixed $handler, array $params): void
    {
        if (is_callable($handler)) {
            $handler(...array_values($params));

            return;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = new $class();
            $controller->{$method}(...array_values($params));

            return;
        }

        throw new \RuntimeException('Invalid route handler.');
    }
}
