<?php

declare(strict_types=1);

$root = __DIR__;
$candidates = [
    $root . '/vendor/autoload.php',
    dirname($root, 2) . '/vendor/autoload.php',
];
foreach ($candidates as $file) {
    if (is_file($file)) {
        require $file;
        return;
    }
}

fwrite(STDERR, "License server autoload missing. Run composer install in this directory or in the Community tree two levels above.\n");
exit(1);
