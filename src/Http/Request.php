<?php

declare(strict_types=1);

namespace IndependentMall\Http;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $body
     * @param array<string, string> $cookies
     * @param array<string, string> $server
     * @param array<string, mixed> $files
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $body,
        private array $cookies,
        private array $server,
        private array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $body = [];
        foreach ($_POST as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $body[$k] = $v;
            }
        }
        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $cookies[$k] = $v;
            }
        }
        $server = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $server[$k] = $v;
            }
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $query,
            $body,
            $cookies,
            $server,
            is_array($_FILES) ? $_FILES : [],
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function post(string $key, string $default = ''): string
    {
        return $this->body[$key] ?? $default;
    }

    public function query(string $key, string $default = ''): string
    {
        return $this->query[$key] ?? $default;
    }

    public function intPost(string $key, int $default = 0): int
    {
        $v = $this->post($key, '');
        return $v === '' ? $default : (int) $v;
    }

    /**
     * @return array<string, mixed>
     */
    public function file(string $key): array
    {
        $f = $this->files[$key] ?? [];
        return is_array($f) ? $f : [];
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
