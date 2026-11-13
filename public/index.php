<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Served from a sub-folder through the project-root .htaccess (e.g. http://localhost/camplo → camplo/public/): present
// the project folder, not public/, as the script's location, so Laravel routes /camplo/api/... as /api/...
$script = $_SERVER['SCRIPT_NAME'] ?? '';
if (str_ends_with($script, '/public/index.php')) {
    $dir = substr($script, 0, -strlen('/public/index.php'));
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (! str_starts_with($uri, $dir.'/public/') && ($uri === $dir || str_starts_with($uri, $dir.'/'))) {
        $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $dir.'/index.php';
    }
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
