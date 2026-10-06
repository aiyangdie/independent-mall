<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use PHPUnit\Framework\TestCase;

final class LayoutCssTest extends TestCase
{
    public function testShopCssHasNarrowFocusAndContrastAnchors(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.css');
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('.skip:focus', $css);
        $this->assertStringContainsString('@media (max-width: 640px)', $css);
        $this->assertStringContainsString('minmax(min(220px, 100%)', $css);
        $this->assertStringContainsString('font-size: 1rem', $css);
        $this->assertStringContainsString('max-width: 100%', $css);
        $this->assertStringContainsString('.table-wrap { overflow-x: auto', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('--muted: #4a5a68', $css);
        $this->assertStringContainsString('--accent: #0a4a3d', $css);
        $this->assertStringContainsString('outline: 3px solid', $css);
        $this->assertStringContainsString('button {', $css);
        $this->assertStringContainsString('color: #ffffff', $css);
        $this->assertStringContainsString('min-height: 2.5rem', $css);
    }

    public function testOfficialSiteCssHasFocusReducedMotionAndCallouts(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/apps/www/public/assets/site.css');
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('.skip:focus', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('.callout', $css);
        $this->assertStringContainsString('--focus: #0a4a3d', $css);
        $this->assertStringContainsString('min-height: 2.5rem', $css);
        $this->assertStringContainsString('@media (max-width: 40rem)', $css);
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
        $this->assertStringContainsString('跳到主要内容', $layout);
        $this->assertStringContainsString('aria-label="运营入口"', $layout);
        $this->assertStringContainsString('aria-label="法律信息"', $layout);
        $this->assertStringContainsString('/legal/prohibited', $layout);
        $this->assertStringContainsString('id="main"', $layout);
    }
}
