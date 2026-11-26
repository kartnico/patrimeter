<?php

declare(strict_types=1);

/*
 * Router of PHP's built-in server (make serve), development only: an existing file of public/ is served as is, any
 * other address goes through Symfony (pages, and AssetMapper assets served on the fly, which do not exist on disk
 * in development).
 */

$publicDirectory = dirname(__DIR__) . '/public';
$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if (PHP_SAPI === 'cli-server' && is_string($path) && $path !== '/' && is_file($publicDirectory . $path)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicDirectory . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $publicDirectory . '/index.php';
