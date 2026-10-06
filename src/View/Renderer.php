<?php

declare(strict_types=1);

namespace IndependentMall\View;

final class Renderer
{
    public function __construct(private string $templateRoot)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data = []): string
    {
        $file = $this->templateRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $view) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Missing template: ' . $view);
        }
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
