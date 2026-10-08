<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
$applicationPath = dirname(__DIR__).'/__BACKEND__';
if (file_exists($maintenance = $applicationPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}
require $applicationPath.'/vendor/autoload.php';
/** @var Application $app */
$app = require_once $applicationPath.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
