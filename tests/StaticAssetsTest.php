<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use IndependentMall\Http\StaticAssets;
use PHPUnit\Framework\TestCase;

final class StaticAssetsTest extends TestCase
{
    public function testCssIsServedInsteadOfFallingIntoDynamicRouter(): void
    {
        $response = StaticAssets::response(dirname(__DIR__) . '/public', '/assets/app.css');
        $this->assertNotNull($response);
        $this->assertSame(200, $response->status());
        $this->assertSame('text/css; charset=UTF-8', $response->header('Content-Type'));
        $this->assertStringContainsString('.site-header', $response->body());
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertNotNull(StaticAssets::response(dirname(__DIR__) . '/apps/www/public', '/assets/site.css'));
    }

    public function testUnsafeOrUnknownFilesNeverBecomeStaticDownloads(): void
    {
        foreach (['/.env', '/index.php', '/assets/../index.php', '/assets/%2e%2e/.env', '/assets/%2e%2e%5c.env',
            '/assets/x.php', '/assets/app.css%00', '/assets/missing.css', '/assets/%252e%252e/x.css'] as $path) {
            $this->assertNull(StaticAssets::response(dirname(__DIR__) . '/public', $path), $path);
        }
    }
}
