<?php

declare(strict_types=1);

namespace IndependentMall\Tests\Support;

use IndependentMall\License\JsonTransport;

final class RecordingTransport implements JsonTransport
{
    /** @var list<array{url:string,payload:array<string,string>}> */
    public array $calls = [];

    /**
     * @param array<string, mixed>|null $response
     */
    public function __construct(private ?array $response = null, private ?\Throwable $error = null)
    {
    }

    /**
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $payload): array
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload];
        if ($this->error !== null) {
            throw $this->error;
        }
        if ($this->response === null) {
            throw new \RuntimeException('License server unreachable. Existing local document kept for grace.');
        }
        return $this->response;
    }
}
