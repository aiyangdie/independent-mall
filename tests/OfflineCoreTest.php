<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use PHPUnit\Framework\TestCase;

final class OfflineCoreTest extends TestCase
{
    public function testCurlClientLivesOnlyOnAdminRefresh(): void
    {
        $root = dirname(__DIR__) . '/src';
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (!str_contains($code, 'curl_init')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $hits[] = $rel;
        }
        $this->assertSame(['License/CurlTransport.php'], $hits);
        $kernel = (string) file_get_contents(dirname(__DIR__) . '/src/Kernel.php');
        $this->assertStringContainsString("'/admin/license/refresh'", $kernel);
        $before = strpos($kernel, "add('GET', '/', function");
        $refresh = strpos($kernel, "add('POST', '/admin/license/refresh'");
        $this->assertNotFalse($before);
        $this->assertNotFalse($refresh);
        $homeChunk = substr($kernel, $before, 800);
        $this->assertStringNotContainsString('CurlTransport', $homeChunk);
        $this->assertStringNotContainsString('AdminRefresh', $homeChunk);
    }
}
