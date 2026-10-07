<?php

declare(strict_types=1);

namespace IndependentMall\Http;

/** Only serve known non-executable assets inside the public assets directory. */
final class StaticAssets
{
    public static function response(string $publicDir, string $path): ?Response
    {
        $path = rawurldecode($path);
        if (!str_starts_with($path, '/assets/') || str_contains($path, '..')
            || preg_match('#^/assets/[a-zA-Z0-9_./-]+$#D', $path) !== 1) {
            return null;
        }
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp', 'ico' => 'image/x-icon', default => null,
        };
        if ($mime === null) {
            return null;
        }
        $base = realpath($publicDir . '/assets');
        $file = realpath($publicDir . $path);
        if ($base === false || $file === false || !is_file($file)
            || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $base) . '/')) {
            return null;
        }
        return Response::file($file, $mime);
    }
}
