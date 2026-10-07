<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use DomainException;
use IndependentMall\Account\AddressBook;
use IndependentMall\Auth\CustomerAccounts;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Catalog\ProhibitedGoodsPolicy;
use IndependentMall\Checkout\Cart;
use IndependentMall\Checkout\Checkout;
use IndependentMall\Checkout\Refunds;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Fulfillment\DigitalFulfillment;
use IndependentMall\Mail\ArrayMailer;
use IndependentMall\Payment\FakePaymentGateway;
use IndependentMall\Security\AuditLogger;
use PHPUnit\Framework\TestCase;

final class CommerceTest extends TestCase
{
    private \PDO $pdo;
    private ProductCatalog $catalog;
    private CustomerAccounts $accounts;
    private SessionAuth $auth;
    private ArrayMailer $mail;
    private Checkout $checkout;
    private Cart $cart;
    private AddressBook $addresses;
    private FakePaymentGateway $pay;
    private DigitalFulfillment $digital;
    private Refunds $refunds;
    private string $sessDir;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        $this->pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($this->pdo, dirname(__DIR__) . '/database/migrations'))->migrate();
        $hasher = new PasswordHasher();
        $this->mail = new ArrayMailer();
        $audit = new AuditLogger($this->pdo);
        $this->auth = new SessionAuth($this->pdo, $hasher, new RateLimiter($this->pdo), $audit, 'test-key');
        $this->sessDir = sys_get_temp_dir() . '/im-c-' . bin2hex(random_bytes(3));
        $this->auth->start($this->sessDir, 'imc', 3600, false);
        $this->accounts = new CustomerAccounts($this->pdo, $hasher, $this->mail, $audit, $this->auth);
        $this->catalog = new ProductCatalog($this->pdo, new ProhibitedGoodsPolicy($this->pdo), $audit);
        $this->cart = new Cart($this->pdo, $this->catalog);
        $this->addresses = new AddressBook($this->pdo);
        $this->checkout = new Checkout($this->pdo, $this->cart, $this->catalog, $this->addresses, $audit);
        $this->digital = new DigitalFulfillment($this->pdo, $this->checkout, $audit, 'test-key');
        $this->pay = new FakePaymentGateway($this->pdo, $this->checkout, $this->catalog, $this->digital, $audit, 'test-key');
        $this->refunds = new Refunds($this->pdo, $this->checkout, $audit);
    }

    public function testRegisterLoginResetAndLock(): void
    {
        $id = $this->accounts->register('buyer@example.invalid', 'buyer1', 'correct-horse-1');
        $this->assertTrue($this->auth->login('buyer@example.invalid', 'correct-horse-1', '127.0.0.1'));
        $this->expectException(DomainException::class);
        $this->accounts->register('buyer@example.invalid', 'buyer2', 'correct-horse-1');
        unset($id);
    }

    public function testWeakPasswordRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->accounts->register('a@example.invalid', 'aaaa', 'password');
    }

    public function testResetTokenLifecycle(): void
    {
        $this->accounts->register('reset@example.invalid', 'reset1', 'correct-horse-1');
        $this->accounts->requestReset('reset@example.invalid');
        $this->assertNotEmpty($this->mail->sent);
        $this->assertMatchesRegularExpression('/[a-f0-9]{64}/', $this->mail->sent[0]->body, 'token is mailed, not shown on a web page');
        preg_match('/([a-f0-9]{64})/', $this->mail->sent[0]->body, $m);
        $token = $m[1];
        $this->accounts->completeReset($token, 'correct-horse-2');
        $this->assertTrue($this->auth->login('reset@example.invalid', 'correct-horse-2', '10.0.0.2'));
        $this->expectException(DomainException::class);
        $this->accounts->completeReset($token, 'correct-horse-3x');
    }

    public function testDisabledUserCannotLogin(): void
    {
        $id = $this->accounts->register('lock@example.invalid', 'lock1', 'correct-horse-1');
        $this->accounts->deactivate($id);
        $this->assertFalse($this->auth->login('lock@example.invalid', 'correct-horse-1', '127.0.0.1'));
    }

    public function testAddressOwnershipAndSnapshot(): void
    {
        $a = $this->accounts->register('aa@example.invalid', 'addra', 'correct-horse-1');
        $b = $this->accounts->register('bb@example.invalid', 'addrb', 'correct-horse-1');
        $aid = $this->addresses->create($a, [
            'label' => 'Home', 'recipient' => 'A', 'phone' => '1', 'region' => 'X', 'line1' => '1 Road',
        ]);
        $this->expectException(DomainException::class);
        $this->addresses->owned($b, $aid);
    }

    public function testCheckoutPayIdempotentDigitalAndRefund(): void
    {
        $user = $this->accounts->register('shop@example.invalid', 'shopper', 'correct-horse-1');
        $digital = $this->catalog->createDraft(2, 'digital', 'Sheet music', 'sheet-music', 'licensed score', '', 'DIG-1', 'PDF', 500, 10, 1);
        $this->catalog->publish($digital['product_id'], 1);
        $this->catalog->attachDigitalAsset($digital['product_id'], 'score', 'PDF-BYTES');
        $physical = $this->catalog->createDraft(1, 'physical', 'Mug', 'ceramic-mug', '', '', 'MUG-9', 'Std', 1200, 2, 1);
        $this->catalog->publish($physical['product_id'], 1);
        $addr = $this->addresses->create($user, [
            'label' => 'Home', 'recipient' => 'Shopper', 'phone' => '1', 'region' => 'N', 'line1' => '2 Lane',
        ]);
        $this->cart->add($user, $digital['variant_id'], 1);
        $this->cart->add($user, $physical['variant_id'], 1);
        $order = $this->checkout->place($user, $addr, 'key-1');
        $again = $this->checkout->place($user, $addr, 'key-1');
        $this->assertSame($order['id'], $again['id']);
        $this->assertSame('pending_payment', $order['status']);
        $this->assertSame(1700, (int) $order['payable_minor']);
        $snap = json_decode((string) $order['address_snapshot'], true);
        $this->addresses->delete($user, $addr);
        $this->assertSame('2 Lane', $snap['line1']);

        try {
            $this->digital->download(str_repeat('ab', 24));
            $this->fail('unpaid download');
        } catch (DomainException) {
        }

        $fields = [
            'merchant_order' => (string) $order['public_id'],
            'amount_minor' => '1700',
            'currency' => 'CNY',
            'outcome' => 'succeeded',
            'provider_ref' => 'ref-1',
        ];
        $bad = $fields;
        $bad['amount_minor'] = '1';
        $bad['signature'] = $this->pay->sign($bad);
        try {
            $this->pay->notify($bad);
            $this->fail('tampered amount');
        } catch (DomainException) {
        }
        $fields['signature'] = $this->pay->sign($fields);
        $tokens = $this->pay->notify($fields);
        $this->assertNotEmpty($tokens);
        $this->pay->notify($fields);
        $paid = $this->checkout->byId((int) $order['id']);
        $this->assertSame('paid', $paid['status'], 'physical orders must wait for shipment and customer receipt');
        $file = $this->digital->download($tokens[0]);
        $this->assertSame('PDF-BYTES', $file['body']);

        $rid = $this->refunds->request($user, (int) $order['id'], 1700, 'changed mind', 'rf-1');
        $this->assertSame($rid, $this->refunds->request($user, (int) $order['id'], 1700, 'changed mind', 'rf-1'));
        $this->refunds->decide($rid, 'approved', 1);
        $this->refunds->decide($rid, 'approved', 1);
        $this->assertSame('refunded', $this->checkout->byId((int) $order['id'])['status']);
    }

    public function testCancelReleasesStockAndDuplicateCancel(): void
    {
        $user = $this->accounts->register('cxl@example.invalid', 'cxler', 'correct-horse-1');
        $p = $this->catalog->createDraft(1, 'physical', 'Notebook', 'notebook', '', '', 'NB-1', 'Std', 300, 1, 1);
        $this->catalog->publish($p['product_id'], 1);
        $addr = $this->addresses->create($user, [
            'label' => 'H', 'recipient' => 'C', 'phone' => '1', 'region' => 'R', 'line1' => 'L',
        ]);
        $this->cart->add($user, $p['variant_id'], 1);
        $order = $this->checkout->place($user, $addr, 'cxl-1');
        $v = $this->catalog->variant($p['variant_id']);
        $this->assertSame(1, (int) $v['reserved']);
        $this->checkout->cancel($user, (int) $order['id']);
        $this->checkout->cancel($user, (int) $order['id']);
        $v = $this->catalog->variant($p['variant_id']);
        $this->assertSame(0, (int) $v['reserved']);
        $this->assertSame(1, (int) $v['on_hand']);
    }

    public function testInsufficientStockSecondCheckout(): void
    {
        $userA = $this->accounts->register('stka@example.invalid', 'stka', 'correct-horse-1');
        $userB = $this->accounts->register('stkb@example.invalid', 'stkb', 'correct-horse-1');
        $p = $this->catalog->createDraft(1, 'physical', 'Pin', 'enamel-pin', '', '', 'PIN-1', 'Std', 100, 1, 1);
        $this->catalog->publish($p['product_id'], 1);
        $addrA = $this->addresses->create($userA, [
            'label' => 'H', 'recipient' => 'S', 'phone' => '1', 'region' => 'R', 'line1' => 'L',
        ]);
        $addrB = $this->addresses->create($userB, [
            'label' => 'H', 'recipient' => 'S', 'phone' => '1', 'region' => 'R', 'line1' => 'L',
        ]);
        $this->cart->add($userA, $p['variant_id'], 1);
        $this->cart->add($userB, $p['variant_id'], 1);
        $this->checkout->place($userA, $addrA, 'stk-1');
        $this->expectException(DomainException::class);
        $this->checkout->place($userB, $addrB, 'stk-2');
    }

    public function testUserCannotMarkPaidByStatus(): void
    {
        $user = $this->accounts->register('hack@example.invalid', 'hacker', 'correct-horse-1');
        $p = $this->catalog->createDraft(3, 'service', 'Consult', 'consult-hour', '', '', 'SVC-1', 'Hour', 9000, 5, 1);
        $this->catalog->publish($p['product_id'], 1);
        $this->cart->add($user, $p['variant_id'], 1);
        $order = $this->checkout->place($user, null, 'svc-1');
        $this->assertSame('pending_payment', $order['status']);
        $unsigned = [
            'merchant_order' => (string) $order['public_id'],
            'amount_minor' => (string) $order['payable_minor'],
            'currency' => 'CNY',
            'outcome' => 'succeeded',
            'provider_ref' => 'x',
            'signature' => 'nope',
        ];
        $this->expectException(DomainException::class);
        $this->pay->notify($unsigned);
    }
}
