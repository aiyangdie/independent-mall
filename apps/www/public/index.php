<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use IndependentMall\Http\Request;
use IndependentMall\Http\Response;
use IndependentMall\Http\StaticAssets;
use IndependentMall\OfficialSite\Site;

$request = Request::fromGlobals();
$asset = $request->method() === 'GET' ? StaticAssets::response(__DIR__, $request->path()) : null;
if ($asset !== null) {
    $asset->send();
    return;
}
try {
    Site::boot($root)->handle($request)->send();
} catch (Throwable $e) {
    error_log('Official site request failed: ' . get_class($e));
    Response::html('服务暂时不可用，请稍后重试或联系管理员。', 503)->send();
}
