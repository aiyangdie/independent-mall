<?php
declare(strict_types=1);
namespace IndependentMall\Payment;

interface WechatTransport
{
    /** Returns authenticated API JSON; implementations MUST verify response signatures. */
    public function request(string $method, string $path, array $options): array;
}
