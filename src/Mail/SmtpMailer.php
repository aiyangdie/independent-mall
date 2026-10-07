<?php

declare(strict_types=1);

namespace IndependentMall\Mail;

use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class SmtpMailer implements Mailer
{
    /** @param null|\Closure(): TransportInterface $clientFactory Test injection; production uses Symfony SMTP. */
    public function __construct(private SmtpSettings $settings, private ?\Closure $clientFactory = null)
    {
    }

    public function send(Message $message): void
    {
        try {
            if (filter_var($message->to, FILTER_VALIDATE_EMAIL) === false
                || preg_match('/[\r\n\x00]/', $message->to . $message->subject) === 1) {
                throw new DeliveryException();
            }
            $transport = $this->clientFactory === null ? self::transport($this->settings) : ($this->clientFactory)();
            $mail = (new Email())
                ->from(new Address($this->settings->fromAddress, $this->settings->fromName))
                ->to($message->to)
                ->subject($message->subject)
                ->text($message->body, 'utf-8');
            foreach ($message->headers as $header => $value) {
                // Never let a domain Message change routing, envelope or MIME headers.
                if (preg_match('/^X-[a-z0-9-]+$/iD', $header) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
                    throw new DeliveryException();
                }
                $mail->getHeaders()->addTextHeader($header, $value);
            }
            $transport->send($mail);
        } catch (\Throwable) {
            // SMTP errors can include endpoints, usernames or complete provider transcripts.
            // No previous exception is attached, so debug/error pages cannot expose them.
            throw new DeliveryException();
        }
    }

    public static function transport(SmtpSettings $settings): EsmtpTransport
    {
        $host = filter_var($settings->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $settings->host . ']' : $settings->host;
        $transport = new EsmtpTransport($host, $settings->port, $settings->encryption === 'ssl');
        $transport->setAutoTls($settings->encryption !== 'none');
        $transport->setRequireTls($settings->encryption !== 'none');
        if ($settings->authenticate) {
            $transport->setUsername($settings->username);
            $transport->setPassword($settings->password);
        }
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $settings->host,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if ($settings->caFile !== null) {
            $ssl['cafile'] = $settings->caFile;
        }
        /** @var SocketStream $stream */
        $stream = $transport->getStream();
        $stream->setTimeout($settings->timeout);
        $stream->setStreamOptions(['ssl' => $ssl]);
        return $transport;
    }
}
