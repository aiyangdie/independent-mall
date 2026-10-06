<?php

declare(strict_types=1);

namespace IndependentMall\Mail;

interface Mailer
{
    public function send(Message $message): void;
}
