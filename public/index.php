<?php

declare(strict_types=1);

use IndependentMall\Kernel;
use IndependentMall\Http\Request;
use IndependentMall\Http\Response;
use IndependentMall\Http\StaticAssets;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$request = Request::fromGlobals();
$asset = $request->method() === 'GET' ? StaticAssets::response(__DIR__, $request->path()) : null;
if ($asset !== null) {
    $asset->send();
    return;
}
try {
    $kernel = Kernel::boot($root);
    $kernel->handle($request)->send();
} catch (Throwable $e) {
    error_log('Community request failed: ' . get_class($e));
    Response::html('服务暂时不可用，请稍后重试或联系管理员。', 503)->send();
}
