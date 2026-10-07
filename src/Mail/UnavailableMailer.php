<?php

declare(strict_types=1);

namespace IndependentMall\Mail;

/** Production never silently reports success with a Null mail adapter. */
final class UnavailableMailer implements Mailer
{
    public function send(Message $message): void
    {
        throw new DeliveryException();
    }
}
