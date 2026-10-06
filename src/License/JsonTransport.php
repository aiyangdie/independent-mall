<?php

declare(strict_types=1);

namespace IndependentMall\License;

interface JsonTransport
{
    /**
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $payload): array;
}
