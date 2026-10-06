<?php

declare(strict_types=1);

namespace IndependentMall\Mail;

final class Message
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }
}
