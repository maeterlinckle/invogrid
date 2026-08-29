<?php

declare(strict_types=1);

/*
 * Front controller.
 *
 * The web server's document root must point at /public, so the application
 * source, .env and the storage directory are never web-reachable.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Request;
use App\Core\Router;

/** @var Router $router */
$router = require dirname(__DIR__) . '/routes/web.php';

$router->dispatch(Request::method(), Request::path());
