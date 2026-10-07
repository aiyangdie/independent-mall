<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use IndependentMall\Auth\CustomerAccounts;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Mail\ArrayMailer;
use IndependentMall\Mail\DeliveryException;
use IndependentMall\Mail\Mailer;
use IndependentMall\Mail\Message;
use IndependentMall\Mail\ResetLink;
use IndependentMall\Mail\UnavailableMailer;
use IndependentMall\Security\AuditLogger;
use PHPUnit\Framework\TestCase;

final class MailResetTest extends TestCase
{
    private \PDO $pdo;
    private PasswordHasher $hasher;
    private SessionAuth $auth;
    private AuditLogger $audit;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($this->pdo, dirname(__DIR__) . '/database/migrations'))->migrate();
        $this->hasher = new PasswordHasher();
        $this->audit = new AuditLogger($this->pdo);
        $this->auth = new SessionAuth($this->pdo, $this->hasher, new RateLimiter($this->pdo), $this->audit, 'test-key');
    }

    private function accounts(Mailer $mailer): CustomerAccounts
    {
        return new CustomerAccounts($this->pdo, $this->hasher, $mailer, $this->audit, $this->auth, new ResetLink('https://shop.example.invalid'));
    }

    public function testResetEmailContainsConfiguredChineseOneTimeUrlAndOnlyHashAtRest(): void
    {
        $mail = new ArrayMailer();
        $accounts = $this->accounts($mail);
        $id = $accounts->register('buyer@example.invalid', 'buyer1', 'correct-horse-1');
        $accounts->requestReset('buyer@example.invalid');
        $this->assertCount(1, $mail->sent);
        $message = $mail->sent[0];
        $this->assertSame('重置您的商城账户密码', $message->subject);
        $this->assertMatchesRegularExpression('~https://shop\.example\.invalid/reset\?token=[a-f0-9]{64}~', $message->body);
        preg_match('/token=([a-f0-9]{64})/', $message->body, $match);
        $record = $this->pdo->query('SELECT * FROM password_reset_tokens')->fetch();
        $this->assertSame(hash('sha256', $match[1]), $record['token_hash']);
        $audit = json_encode($this->audit->recent());
        $this->assertStringNotContainsString($match[1], $audit);
        $accounts->completeReset($match[1], 'correct-horse-2');
        $hash = $this->pdo->query('SELECT password_hash FROM users WHERE id = ' . $id)->fetchColumn();
        $this->assertTrue($this->hasher->verify('correct-horse-2', $hash));
        $this->expectException(\DomainException::class);
        $accounts->completeReset($match[1], 'correct-horse-3');
    }

    public function testFailedDeliveryDeletesWorkingTokenAndRedactsAudit(): void
    {
        $failing = new class implements Mailer {
            public function send(Message $message): void { throw new \RuntimeException('provider-secret-not-real'); }
        };
        $accounts = $this->accounts($failing);
        $accounts->register('buyer@example.invalid', 'buyer1', 'correct-horse-1');
        try {
            $accounts->requestReset('buyer@example.invalid');
            $this->fail('Failed delivery must not report success.');
        } catch (DeliveryException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('provider-secret-not-real', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
        $audit = json_encode($this->audit->recent());
        $this->assertStringContainsString('user.reset_delivery_failed', $audit);
        $this->assertStringNotContainsString('provider-secret-not-real', $audit);
        $this->assertStringNotContainsString('buyer@example.invalid', $audit);
    }

    public function testResetRequestsAreRateLimitedWithoutAccountEnumeration(): void
    {
        $mail = new ArrayMailer();
        $accounts = $this->accounts($mail);
        $accounts->register('buyer@example.invalid', 'buyer1', 'correct-horse-1');
        for ($i = 0; $i < 5; ++$i) {
            $accounts->requestReset('buyer@example.invalid');
            $accounts->requestReset('unknown@example.invalid');
        }
        $this->assertCount(3, $mail->sent);
        $this->assertSame(6, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
        $records = json_encode($this->pdo->query('SELECT * FROM login_attempts')->fetchAll());
        $this->assertStringNotContainsString('buyer@example.invalid', $records);
        $this->assertStringNotContainsString('unknown@example.invalid', $records);
    }

    public function testPasswordChangeInvalidatesSiblingLinksAndLockedAccountCannotReset(): void
    {
        $mail = new ArrayMailer();
        $accounts = $this->accounts($mail);
        $id = $accounts->register('buyer@example.invalid', 'buyer1', 'correct-horse-1');
        $accounts->requestReset('buyer@example.invalid');
        $accounts->requestReset('buyer@example.invalid');
        preg_match('/token=([a-f0-9]{64})/', $mail->sent[0]->body, $a);
        preg_match('/token=([a-f0-9]{64})/', $mail->sent[1]->body, $b);
        $accounts->completeReset($a[1], 'correct-horse-2');
        try {
            $accounts->completeReset($b[1], 'correct-horse-3');
            $this->fail('Sibling token must be invalidated.');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }
        $accounts->requestReset('buyer@example.invalid');
        preg_match('/token=([a-f0-9]{64})/', $mail->sent[2]->body, $c);
        $accounts->deactivate($id);
        $this->expectException(\DomainException::class);
        $accounts->completeReset($c[1], 'correct-horse-3');
    }

    public function testRealMailerCannotAccidentallyUseAnUnconfiguredLoopbackResetUrl(): void
    {
        $accounts = new CustomerAccounts($this->pdo, $this->hasher, new UnavailableMailer(), $this->audit, $this->auth);
        $accounts->register('buyer@example.invalid', 'buyer1', 'correct-horse-1');
        $this->expectException(DeliveryException::class);
        $accounts->requestReset('buyer@example.invalid');
    }
}
