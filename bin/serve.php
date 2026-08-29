<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in development server. Not used in production, where
 * Apache or nginx does this with a rewrite rule.
 *
 *   php -S 127.0.0.1:8484 -t public bin/serve.php
 *
 * Serve a real file if there is one; otherwise hand the request to the front
 * controller, which is what .htaccess and try_files do on a real server.
 *
 * Set FORCE_HTTPS=false in .env first, or every request is redirected to an
 * https:// URL nothing is listening on.
 */

$root = dirname(__DIR__) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path !== '/' && is_file($root . $path)) {
    return false;
}

require $root . '/index.php';
