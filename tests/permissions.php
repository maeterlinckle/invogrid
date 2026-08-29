<?php

declare(strict_types=1);

/*
 * Every route, every role, over real HTTP.
 *
 *   php permission-sweep.php http://127.0.0.1:8484
 *
 * The point is to test what the *server* does, not what the navigation offers.
 * A capability check that exists only in a template is not a capability check.
 *
 * State-changing routes are probed **without a CSRF token**, deliberately:
 *
 *   403  the role was refused by the capability gate, which runs first
 *   419  the role passed the gate and was stopped by CSRF
 *
 * So a 419 means "would have been allowed" without any handler ever running.
 * Nothing is created, submitted or deleted by this script.
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8484', '/');

$app = dirname(__DIR__);
require $app . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\User;

// --- The people ------------------------------------------------------------

const PASSWORD = 'Harbour lantern 42';

$accounts = [
    'viewer'   => '__sweep_viewer__',
    'reviewer' => '__sweep_reviewer__',
    'admin'    => '__sweep_admin__',
];

foreach ($accounts as $role => $username) {
    Database::run('DELETE FROM users WHERE username = ?', [$username]);
    User::create($username, PASSWORD, $role, 'Sweep ' . $role);
}

// --- The routes ------------------------------------------------------------

/** @var App\Core\Router $router */
$router = require $app . '/routes/web.php';

// Real ids to substitute into {id} patterns, so a 404 is not mistaken for a 403.
$documentId  = (int) (Database::scalar('SELECT id FROM documents ORDER BY id LIMIT 1') ?? 1);
$reviewId    = (int) (Database::scalar("SELECT id FROM documents WHERE status IN ('needs_review','ready_to_submit') ORDER BY id LIMIT 1") ?? $documentId);
$matchId     = (int) (Database::scalar('SELECT id FROM entity_matches ORDER BY id LIMIT 1') ?? 1);
$fieldId     = (int) (Database::scalar('SELECT id FROM custom_fields ORDER BY id LIMIT 1') ?? 1);
$userId      = (int) (Database::scalar('SELECT id FROM users ORDER BY id LIMIT 1') ?? 1);
$promptRowId = (int) (Database::scalar('SELECT id FROM prompt_templates ORDER BY id LIMIT 1') ?? 1);

$substitutions = [
    '{id:\d+}'              => (string) $documentId,
    '{matchId:\d+}'         => (string) $matchId,
    '{page:\d+}'            => '1',
    '{key:[a-z_]+}'         => 'ocr',
    '{variant:light|dark}'  => 'light',
];

/** Turn a route pattern into a URL that will actually resolve. */
$concrete = static function (string $pattern) use ($substitutions, $reviewId, $fieldId, $userId, $promptRowId): string {
    $path = $pattern;

    // Context-sensitive ids, so /review/{id} gets a document that is in review
    // and /admin/fields/{id} gets a field rather than a document.
    if (str_starts_with($path, '/review/')) {
        $path = str_replace('{id:\d+}', (string) $reviewId, $path);
    } elseif (str_starts_with($path, '/admin/fields/')) {
        $path = str_replace('{id:\d+}', (string) $fieldId, $path);
    } elseif (str_starts_with($path, '/admin/users/')) {
        $path = str_replace('{id:\d+}', (string) $userId, $path);
    } elseif (str_contains($path, '/activate/')) {
        $path = str_replace('{id:\d+}', (string) $promptRowId, $path);
    }

    foreach ($substitutions as $token => $value) {
        $path = str_replace($token, $value, $path);
    }

    return $path;
};

// --- Talking to it ---------------------------------------------------------

function http(string $method, string $url, string $jar, array $fields = []): array
{
    $handle = curl_init();

    curl_setopt_array($handle, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $body   = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($handle);

    curl_close($handle);

    if ($error !== '') {
        fwrite(STDERR, "curl: $error\n");
        exit(1);
    }

    return ['status' => $status, 'body' => $body];
}

function signIn(string $base, string $username, string $jar): void
{
    @unlink($jar);

    $page = http('GET', $base . '/login', $jar);

    if (!preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
        fwrite(STDERR, "No CSRF token on the sign-in page.\n");
        exit(1);
    }

    $in = http('POST', $base . '/login', $jar, [
        '_token'   => $m[1],
        'username' => $username,
        'password' => PASSWORD,
    ]);

    if ($in['status'] !== 302) {
        fwrite(STDERR, "Could not sign in as $username (HTTP {$in['status']}).\n");
        exit(1);
    }
}

// --- What each role should get ---------------------------------------------

/**
 * The capability a route requires, read off its own middleware — so this
 * compares the server against the route table rather than against a second
 * list somebody would have to keep in step.
 */
function requirement(array $middleware): ?string
{
    foreach ($middleware as $m) {
        if (str_starts_with($m, 'can:')) {
            return substr($m, 4);
        }

        if (str_starts_with($m, 'role:')) {
            return 'role:' . substr($m, 5);
        }
    }

    return null;
}

function shouldAllow(string $role, ?string $requires): bool
{
    if ($requires === null) {
        return true; // auth only
    }

    if (str_starts_with($requires, 'role:')) {
        $needed = substr($requires, 5);
        $order  = array_flip(Auth::ROLES);

        return ($order[$role] ?? -1) >= ($order[$needed] ?? 99);
    }

    $map        = Auth::capabilityMap();
    return in_array($requires, $map[$role] ?? [], true);
}

// --- Run -------------------------------------------------------------------

$open = [
    'GET /health', 'GET /branding/{variant:light|dark}', 'POST /webhook/paperless',
    'GET /login', 'POST /login',
];

$rows      = [];
$mismatch  = 0;
$checked   = 0;

foreach ($accounts as $role => $username) {
    $jar = sys_get_temp_dir() . '/invogrid-sweep-' . $role . '.txt';
    signIn($base, $username, $jar);

    foreach ($router->routes() as $route) {
        $signature = $route['method'] . ' ' . $route['pattern'];

        if (in_array($signature, $open, true)) {
            continue;
        }

        // Serving a PDF or an image streams a file; nothing is learned from it
        // that the HTML routes do not already say.
        if (str_contains($route['pattern'], '/pdf') || str_contains($route['pattern'], '/page/')) {
            continue;
        }

        $requires = requirement($route['middleware']);
        $expect   = shouldAllow($role, $requires);
        $url      = $base . $concrete($route['pattern']);

        $result = http($route['method'], $url, $jar);
        $status = $result['status'];
        $checked++;

        /*
         * 403 is the gate refusing. Anything else means it did not refuse:
         * for a POST that is 419 (CSRF stopped it, so the gate had let it
         * through); for a GET it is a 200, a redirect, or a 404 if the id does
         * not exist — none of which is a permission failure.
         */
        $allowed = $status !== 403;
        $ok      = $allowed === $expect;

        if (!$ok) {
            $mismatch++;
        }

        $rows[] = [
            'role'      => $role,
            'signature' => $signature,
            'requires'  => $requires ?? 'auth',
            'expect'    => $expect ? 'allow' : 'DENY',
            'status'    => $status,
            'verdict'   => $ok ? 'ok' : 'MISMATCH',
        ];
    }

    @unlink($jar);
}

// --- Report ----------------------------------------------------------------

printf("%-9s %-52s %-18s %-6s %-5s %s\n", 'ROLE', 'ROUTE', 'REQUIRES', 'EXPECT', 'GOT', '');

foreach ($rows as $r) {
    if ($r['verdict'] === 'ok' && ($argv[2] ?? '') !== '--all') {
        continue;
    }

    printf(
        "%-9s %-52s %-18s %-6s %-5d %s\n",
        $r['role'],
        substr($r['signature'], 0, 52),
        $r['requires'],
        $r['expect'],
        $r['status'],
        $r['verdict'] === 'ok' ? '' : '  <<<<'
    );
}

// --- Tidy up ---------------------------------------------------------------

foreach ($accounts as $username) {
    Database::run('DELETE FROM users WHERE username = ?', [$username]);

    // The sign-ins are recorded like any other, and leaving them behind would
    // slowly fill the throttle table on an install this is run against often.
    Database::run('DELETE FROM login_attempts WHERE username = ?', [$username]);
}

$denied = count(array_filter($rows, static fn (array $r): bool => $r['expect'] === 'DENY'));

printf(
    "\n%d checks across %d roles — %d expected denials, %d mismatches.\n",
    $checked,
    count($accounts),
    $denied,
    $mismatch
);

exit($mismatch === 0 ? 0 : 1);
