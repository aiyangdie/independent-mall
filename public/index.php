<?php

declare(strict_types=1);

use IndependentMall\Kernel;
use IndependentMall\Http\Request;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$kernel = Kernel::boot($root);
$kernel->handle(Request::fromGlobals())->send();
