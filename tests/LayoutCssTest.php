<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use PHPUnit\Framework\TestCase;

final class LayoutCssTest extends TestCase
{
    public function testShopCssHasNarrowAndFocusRules(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.css');
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('.skip:focus', $css);
        $this->assertStringContainsString('@media (max-width: 640px)', $css);
        $this->assertStringContainsString('minmax(min(220px, 100%)', $css);
        $this->assertStringContainsString('font-size: 1rem', $css);
        $this->assertStringContainsString('max-width: 100%', $css);
        $this->assertStringContainsString('.table-wrap { overflow-x: auto', $css);
    }

    public function testTemplatesWithTablesUseOverflowWrap(): void
    {
        $root = dirname(__DIR__) . '/templates';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $checked = 0;
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (!str_contains($code, '<table')) {
                continue;
            }
            $checked++;
            $this->assertStringContainsString('table-wrap', $code, $file->getPathname());
        }
        $this->assertGreaterThan(5, $checked);
        $layout = (string) file_get_contents($root . '/layout.php');
        $this->assertStringContainsString('Skip to content', $layout);
        $this->assertStringContainsString('aria-label="Operations"', $layout);
        $this->assertStringContainsString('id="main"', $layout);
    }
}
