<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use IndependentMall\Config\Config;
use IndependentMall\Mail\DeliveryException;
use IndependentMall\Mail\MailerFactory;
use IndependentMall\Mail\Message;
use IndependentMall\Mail\NullMailer;
use IndependentMall\Mail\ResetLink;
use IndependentMall\Mail\SmtpMailer;
use IndependentMall\Mail\SmtpSettings;
use IndependentMall\Mail\UnavailableMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailSmtpTest extends TestCase
{
    private function config(array $changes = []): Config
    {
        return new Config(array_merge([
            'APP_ENV' => 'production', 'MAIL_ADAPTER' => 'smtp',
            'SMTP_HOST' => 'smtp.example.invalid', 'SMTP_PORT' => '587', 'SMTP_ENCRYPTION' => 'tls',
            'SMTP_AUTH' => '1', 'SMTP_USERNAME' => 'test-user', 'SMTP_PASSWORD' => 'test-secret-not-real',
            'SMTP_TIMEOUT' => '2', 'MAIL_FROM_ADDRESS' => 'no-reply@example.invalid', 'MAIL_FROM_NAME' => '测试商城',
        ], $changes));
    }

    public function testFactoryNeverUsesNullInProductionOrWithInvalidSmtp(): void
    {
        $this->assertInstanceOf(SmtpMailer::class, MailerFactory::fromConfig($this->config()));
        $this->assertInstanceOf(NullMailer::class, MailerFactory::fromConfig($this->config(['MAIL_ADAPTER' => 'null', 'APP_ENV' => 'test'])));
        foreach ([['MAIL_ADAPTER' => 'null'], ['MAIL_ADAPTER' => 'unknown'], ['SMTP_HOST' => ''], ['SMTP_PASSWORD' => ''], ['SMTP_AUTH' => '0']] as $changes) {
            $mail = MailerFactory::fromConfig($this->config($changes));
            $this->assertInstanceOf(UnavailableMailer::class, $mail);
            try {
                $mail->send(new Message('buyer@example.invalid', 'test', 'test'));
                $this->fail('Unavailable adapter must not report successful delivery.');
            } catch (DeliveryException $e) {
                $this->assertNull($e->getPrevious());
            }
        }
    }

    public function testTlsIsRequiredAndCertificateVerificationCannotBeDisabled(): void
    {
        $settings = SmtpSettings::fromConfig($this->config());
        $transport = SmtpMailer::transport($settings);
        $this->assertTrue($transport->isAutoTls());
        $this->assertTrue($transport->isTlsRequired());
        $options = $transport->getStream()->getStreamOptions()['ssl'];
        $this->assertTrue($options['verify_peer']);
        $this->assertTrue($options['verify_peer_name']);
        $this->assertFalse($options['allow_self_signed']);
        $this->assertSame('smtp.example.invalid', $options['peer_name']);
        $this->assertSame(2.0, $transport->getStream()->getTimeout());
        $this->assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, $options['crypto_method']);
        $ssl = SmtpMailer::transport(SmtpSettings::fromConfig($this->config(['SMTP_ENCRYPTION' => 'ssl', 'SMTP_PORT' => '465'])));
        $this->assertTrue($ssl->getStream()->isTLS());
    }

    public function testUnsafeConfigurationIsRejectedBeforeNetwork(): void
    {
        foreach ([
            ['SMTP_ENCRYPTION' => 'none'],
            ['APP_ENV' => 'test', 'SMTP_ENCRYPTION' => 'none'],
            ['SMTP_HOST' => 'ssl://smtp.example.invalid'],
            ['SMTP_HOST' => "smtp.example.invalid\r\nother-host"],
            ['MAIL_FROM_ADDRESS' => "a@example.invalid\r\nBcc:x@example.invalid"],
            ['MAIL_FROM_NAME' => "商城\r\nBcc:x@example.invalid"],
            ['SMTP_PORT' => '587junk'], ['SMTP_PORT' => '65536'], ['SMTP_TIMEOUT' => '0'], ['SMTP_TIMEOUT' => '31'],
            ['SMTP_CA_FILE' => '/nonexistent-ca-file.pem'],
        ] as $changes) {
            try {
                SmtpSettings::fromConfig($this->config($changes));
                $this->fail('Unsafe SMTP configuration accepted.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringNotContainsString('test-secret-not-real', $e->getMessage());
            }
        }
    }

    public function testChineseMimeAndReservedHeaderInjection(): void
    {
        $capture = new class implements TransportInterface {
            public ?Email $message = null;
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->message = $message;
                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }
            public function __toString(): string { return 'test://capture'; }
        };
        $mail = new SmtpMailer(SmtpSettings::fromConfig($this->config()), fn () => $capture);
        $mail->send(new Message('buyer@example.invalid', '重置密码', '请打开一次性链接。', ['X-Request' => 'safe-id']));
        $this->assertSame('重置密码', $capture->message->getSubject());
        $this->assertSame('请打开一次性链接。', $capture->message->getTextBody());
        $this->assertSame('测试商城', $capture->message->getFrom()[0]->getName());
        foreach ([
            new Message("buyer@example.invalid\r\nBcc:other@example.invalid", 'test', 'test'),
            new Message('buyer@example.invalid', "test\r\nBcc:other@example.invalid", 'test'),
            new Message('buyer@example.invalid', 'test', 'test', ['Bcc' => 'other@example.invalid']),
            new Message('buyer@example.invalid', 'test', 'test', ['X-Safe' => "test\r\nBcc:other@example.invalid"]),
        ] as $unsafe) {
            try {
                $mail->send($unsafe);
                $this->fail('Header injection accepted.');
            } catch (DeliveryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testProviderExceptionsAreSanitizedWithoutPreviousException(): void
    {
        $mail = new SmtpMailer(SmtpSettings::fromConfig($this->config()), function () {
            throw new \RuntimeException('secret-password smtp://test-user:test-secret-not-real@host');
        });
        try {
            $mail->send(new Message('buyer@example.invalid', 'test', 'test'));
            $this->fail('Should fail.');
        } catch (DeliveryException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('test-secret-not-real', $e->getMessage());
            $this->assertSame('邮件暂时无法发送，请稍后重试或联系管理员。', $e->getMessage());
        }
    }

    public function testResetLinkUsesConfiguredHttpsHostAndRejectsUnsafeUrls(): void
    {
        $token = str_repeat('a', 64);
        $link = ResetLink::fromConfig(new Config(['APP_ENV' => 'production', 'APP_URL' => 'https://shop.example.invalid/']));
        $this->assertSame('https://shop.example.invalid/reset?token=' . $token, $link->forToken($token));
        $this->assertSame('http://127.0.0.1:8090/reset?token=' . $token, (new ResetLink('http://127.0.0.1:8090', true))->forToken($token));
        foreach (['http://shop.example.invalid', 'https://user:pass@shop.example.invalid', 'https://shop.example.invalid/?next=x', 'javascript:evil', "https://shop.example.invalid/\r\n"] as $url) {
            try {
                new ResetLink($url);
                $this->fail('Unsafe reset URL accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testActualSmtpSocketDeliversChineseMessageToLocalSinkOnly(): void
    {
        [$process, $pipes, $port] = $this->startSink();
        try {
            $mail = MailerFactory::fromConfig($this->config([
                'APP_ENV' => 'test', 'SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string) $port,
                'SMTP_ENCRYPTION' => 'none', 'SMTP_AUTH' => '0',
            ]));
            $body = '请在一小时内打开 https://shop.example.invalid/reset?token=' . str_repeat('a', 64);
            $mail->send(new Message('buyer@example.invalid', '重置您的商城账户密码', $body));
            $capture = json_decode(base64_decode(trim(stream_get_contents($pipes[1]))), true, 512, JSON_THROW_ON_ERROR);
            $this->assertContains('MAIL FROM:<no-reply@example.invalid>', $capture['commands']);
            $this->assertContains('RCPT TO:<buyer@example.invalid>', $capture['commands']);
            $this->assertContains('DATA', $capture['commands']);
            $this->assertStringContainsString($body, quoted_printable_decode($capture['body']));
            $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $capture['body']);
        } finally {
            $this->closeSink($process, $pipes);
        }
    }

    public function testTlsDowngradeDoesNotSendCredentialsOrBody(): void
    {
        [$process, $pipes, $port] = $this->startSink();
        try {
            $mail = MailerFactory::fromConfig($this->config([
                'APP_ENV' => 'test', 'SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string) $port,
            ]));
            try {
                $mail->send(new Message('buyer@example.invalid', 'secret-subject', 'secret-body'));
                $this->fail('Server without STARTTLS must be rejected.');
            } catch (DeliveryException $e) {
                $this->assertNull($e->getPrevious());
            }
            $capture = json_decode(base64_decode(trim(stream_get_contents($pipes[1]))), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('', $capture['body']);
            $this->assertSame([], array_values(array_filter($capture['commands'], fn ($s) => str_starts_with($s, 'AUTH') || str_starts_with($s, 'MAIL FROM'))));
        } finally {
            $this->closeSink($process, $pipes);
        }
    }

    private function startSink(): array
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/MailSmtpFixture.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 10);
        $address = trim((string) fgets($pipes[1]));
        $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address);
        return [$process, $pipes, (int) substr($address, strrpos($address, ':') + 1)];
    }

    private function closeSink($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }
        proc_close($process);
    }
}
