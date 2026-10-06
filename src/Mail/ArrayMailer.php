<?php

declare(strict_types=1);

namespace IndependentMall\Mail;

final class ArrayMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];

    public function send(Message $message): void
    {
        $this->sent[] = $message;
    }
}
