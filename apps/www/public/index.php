<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicFile = __DIR__ . $uri;
if ($uri !== '/' && is_file($publicFile)) {
    return false;
}

$root = dirname(__DIR__);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use IndependentMall\Http\Request;
use IndependentMall\OfficialSite\Site;

Site::boot($root)->handle(Request::fromGlobals())->send();
