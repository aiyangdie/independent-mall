<?php

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndependentMall\\Commercial\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});
