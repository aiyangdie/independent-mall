<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use DomainException;
use IndependentMall\Account\AddressBook;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Catalog\ProhibitedGoodsPolicy;
use IndependentMall\Checkout\Cart;
use IndependentMall\Checkout\Checkout;
use IndependentMall\Checkout\Refunds;
use IndependentMall\Config\Config;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Fulfillment\DigitalFulfillment;
use IndependentMall\Fulfillment\Shipping;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\Request;
use IndependentMall\Http\Router;
use IndependentMall\Kernel;
use IndependentMall\Payment\FakePaymentGateway;
use IndependentMall\Security\AuditLogger;
use IndependentMall\View\Renderer;
use PDO;
use PHPUnit\Framework\TestCase;

final class ShippingRefundTest extends TestCase
{
    private PDO $pdo;
    private ProductCatalog $catalog;
    private Cart $cart;
    private Checkout $checkout;
    private AddressBook $addresses;
    private DigitalFulfillment $digital;
    private FakePaymentGateway $pay;
    private Refunds $refunds;
    private Shipping $shipping;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($this->pdo, dirname(__DIR__) . '/database/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (email,username,password_hash,status,created_at,updated_at) VALUES ('ops@example.invalid','ops','test-only','active','2026','2026'), ('buyer@example.invalid','buyer','test-only','active','2026','2026'), ('other@example.invalid','other','test-only','active','2026','2026')");
        $this->pdo->exec("INSERT INTO user_roles (user_id,role_id) SELECT 1,id FROM roles WHERE name = 'admin'");
        $audit = new AuditLogger($this->pdo);
        $this->catalog = new ProductCatalog($this->pdo, new ProhibitedGoodsPolicy($this->pdo), $audit);
        $this->cart = new Cart($this->pdo, $this->catalog);
        $this->addresses = new AddressBook($this->pdo);
        $this->checkout = new Checkout($this->pdo, $this->cart, $this->catalog, $this->addresses, $audit);
        $this->digital = new DigitalFulfillment($this->pdo, $this->checkout, $audit, 'test-key');
        $this->pay = new FakePaymentGateway($this->pdo, $this->checkout, $this->catalog, $this->digital, $audit, 'test-key');
        $this->refunds = new Refunds($this->pdo, $this->checkout, $audit);
        $this->shipping = new Shipping($this->pdo, $this->checkout, $audit);
    }

    /** @return array<string,mixed> */
    private function order(string $type = 'physical', bool $pay = true): array
    {
        $n = ++$this->sequence;
        $p = $this->catalog->createDraft($type === 'physical' ? 1 : 2, $type, '合法商品' . $n, 'safe-product-' . $n, '', '', 'SKU-' . $n, '标准', 1000, 5, 1);
        $this->catalog->publish($p['product_id'], 1);
        if ($type === 'digital') {
            $this->catalog->attachDigitalAsset($p['product_id'], '工作手册', 'owned-content');
        }
        $address = $type === 'physical' ? $this->addresses->create(2, ['label' => '家', 'recipient' => '顾客', 'phone' => '100', 'region' => '地区', 'line1' => '地址']) : null;
        $this->cart->add(2, $p['variant_id'], 1);
        $order = $this->checkout->place(2, $address, 'order-' . $n);
        if ($pay) {
            $this->notify($order);
        }
        return $this->checkout->byId((int) $order['id']);
    }

    /** @return list<string> */
    private function notify(array $order, string $outcome = 'succeeded'): array
    {
        $fields = ['merchant_order' => $order['public_id'], 'amount_minor' => (string) $order['payable_minor'], 'currency' => 'CNY', 'outcome' => $outcome, 'provider_ref' => 'pay-' . $order['public_id']];
        $fields['signature'] = $this->pay->sign($fields);
        return $this->pay->notify($fields);
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('unsafe operation was accepted');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPhysicalPaymentShipAndReceiptAreIdempotent(): void
    {
        $order = $this->order();
        $id = (int) $order['id'];
        $this->assertSame('paid', $order['status']);
        $this->denied(fn () => $this->shipping->confirmReceipt(2, $id));
        $this->denied(fn () => $this->shipping->ship($id, 2, '合法快递', 'SF12345', 'ship-1'));
        $shipId = $this->shipping->ship($id, 1, '合法快递', 'SF12345', 'ship-1');
        $this->assertSame($shipId, $this->shipping->ship($id, 1, '合法快递', 'SF12345', 'ship-1'));
        $this->assertSame('fulfilling', $this->checkout->byId($id)['status']);
        $this->denied(fn () => $this->shipping->ship($id, 1, '合法快递', 'SF67890', 'ship-2'));
        $this->denied(fn () => $this->shipping->confirmReceipt(3, $id));
        $this->shipping->confirmReceipt(2, $id);
        $this->shipping->confirmReceipt(2, $id);
        $this->assertSame('completed', $this->checkout->byId($id)['status']);
        $this->assertSame('delivered', $this->shipping->forOrder($id)['status']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='shipment.shipped'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='shipment.received'")->fetchColumn());
    }

    public function testUnpaidAndNonPhysicalOrdersCannotShip(): void
    {
        $unpaid = $this->order('physical', false);
        $this->denied(fn () => $this->shipping->ship((int) $unpaid['id'], 1, '合法快递', 'SF12345', 'ship-u'));
        $digital = $this->order('digital');
        $this->assertSame('completed', $digital['status']);
        $this->denied(fn () => $this->shipping->ship((int) $digital['id'], 1, '合法快递', 'SF12345', 'ship-d'));
    }

    public function testFailedAndDuplicateNotifyNeverUndoSuccessfulPayment(): void
    {
        $order = $this->order();
        $this->assertSame([], $this->notify($order));
        $this->notify($order, 'failed');
        $this->assertSame('succeeded', $this->pay->forOrder((int) $order['id'])[0]['status']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reason='sale'")->fetchColumn());
    }

    public function testRefundRejectRestoresPaidAndShippedState(): void
    {
        $order = $this->order();
        $id = (int) $order['id'];
        $r = $this->refunds->request(2, $id, 1000, '无需此商品', 'r1');
        $this->denied(fn () => $this->shipping->ship($id, 1, '合法快递', 'SF12345', 'ship-r'));
        $this->refunds->decide($r, 'rejected', 1);
        $this->assertSame('paid', $this->checkout->byId($id)['status']);
        $this->shipping->ship($id, 1, '合法快递', 'SF12345', 'ship-r');
        $r = $this->refunds->request(2, $id, 1000, '商品损坏', 'r2');
        $this->denied(fn () => $this->shipping->confirmReceipt(2, $id));
        $this->refunds->decide($r, 'rejected', 1);
        $this->assertSame('fulfilling', $this->checkout->byId($id)['status']);
    }

    public function testRefundLimitsCumulativePendingAndSucceededAndDoesNotRestock(): void
    {
        $order = $this->order();
        $id = (int) $order['id'];
        $item = $this->checkout->items($id)[0];
        $first = $this->refunds->request(2, $id, 600, '部分退款', 'r-part');
        $this->assertSame($first, $this->refunds->request(2, $id, 600, '部分退款', 'r-part'));
        $this->denied(fn () => $this->refunds->request(2, $id, 601, '变更金额', 'r-part'));
        $this->denied(fn () => $this->refunds->request(2, $id, 500, '再次退款', 'r-excess'));
        $this->refunds->decide($first, 'approved', 1);
        $this->assertSame('paid', $this->checkout->byId($id)['status'], 'a partial refund must not mark entire order refunded');
        $this->assertSame(400, $this->refunds->remaining($id));
        $second = $this->refunds->request(2, $id, 400, '剩余退款', 'r-rest');
        $this->refunds->decide($second, 'approved', 1);
        $this->refunds->decide($second, 'approved', 1);
        $this->assertSame('refunded', $this->checkout->byId($id)['status']);
        $this->assertSame(4, (int) $this->catalog->variant((int) $item['variant_id'])['on_hand']);
    }

    public function testCannotRefundUnpaidOrMissingPaymentOrBorrowIdempotencyKey(): void
    {
        $unpaid = $this->order('physical', false);
        $this->denied(fn () => $this->refunds->request(2, (int) $unpaid['id'], 1000, '退款', 'u-r'));
        $this->pdo->prepare("UPDATE orders SET status='paid' WHERE id=:id")->execute(['id' => $unpaid['id']]);
        $this->denied(fn () => $this->refunds->request(2, (int) $unpaid['id'], 1000, '退款', 'u-r'));
        $paid = $this->order();
        $this->refunds->request(2, (int) $paid['id'], 1000, '退款', 'same-key');
        $other = $this->order();
        $this->denied(fn () => $this->refunds->request(2, (int) $other['id'], 1000, '退款', 'same-key'));
        $this->denied(fn () => $this->refunds->request(3, (int) $paid['id'], 1000, '退款', 'same-key'));
    }

    public function testRealProviderApprovalStaysPendingUntilVerifiedCompletion(): void
    {
        $order = $this->order();
        $id = (int) $order['id'];
        $this->pdo->prepare("UPDATE payments SET provider='wechat' WHERE order_id=:id")->execute(['id' => $id]);
        $refund = $this->refunds->request(2, $id, 1000, '退款', 'wx-r');
        $this->denied(fn () => $this->refunds->complete($refund, 'wx-refund'));
        $this->refunds->decide($refund, 'approved', 1);
        $this->assertSame('approved', $this->refunds->byId($refund)['status']);
        $this->assertSame('refund_requested', $this->checkout->byId($id)['status']);
        $this->refunds->complete($refund, 'wx-refund');
        $this->refunds->complete($refund, 'wx-refund');
        $this->denied(fn () => $this->refunds->complete($refund, 'different'));
        $this->assertSame('refunded', $this->checkout->byId($id)['status']);
    }

    public function testRenewDigitalLinkKeepsAllowanceAndBlocksOldLink(): void
    {
        $order = $this->order('digital', false);
        $id = (int) $order['id'];
        $first = $this->notify($order)[0];
        $delivery = $this->digital->forOrder($id)[0];
        $this->digital->download($first);
        $renewed = $this->digital->renewLink(2, $id, (int) $delivery['id']);
        $this->denied(fn () => $this->digital->download($first));
        $this->denied(fn () => $this->digital->renewLink(3, $id, (int) $delivery['id']));
        $after = $this->digital->forOrder($id)[0];
        $this->assertSame($delivery['expires_at'], $after['expires_at']);
        $this->assertSame(1, (int) $after['download_count']);
        $this->digital->download($renewed);
        $this->digital->download($renewed);
        $this->denied(fn () => $this->digital->download($renewed));
        $this->denied(fn () => $this->digital->renewLink(2, $id, (int) $delivery['id']));
        $logs = implode(' ', $this->pdo->query('SELECT meta FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertStringNotContainsString($renewed, $logs);
    }

    public function testRefundRevokesDigitalDeliveryAndExpiredLinkCannotBeRenewed(): void
    {
        $order = $this->order('digital');
        $id = (int) $order['id'];
        $delivery = $this->digital->forOrder($id)[0];
        $this->pdo->prepare('UPDATE digital_deliveries SET expires_at=:t WHERE id=:id')->execute(['t' => '2000-01-01', 'id' => $delivery['id']]);
        $this->denied(fn () => $this->digital->renewLink(2, $id, (int) $delivery['id']));
        $r = $this->refunds->request(2, $id, 1000, '退款', 'd-r');
        $this->refunds->decide($r, 'approved', 1);
        $this->assertSame(1, (int) $this->digital->forOrder($id)[0]['revoked']);
        $this->denied(fn () => $this->digital->renewLink(2, $id, (int) $delivery['id']));
    }

    public function testShippingHttpRequiresAdministratorCsrfAndCustomerOwnership(): void
    {
        $order = $this->order();
        $id = (int) $order['id'];
        $root = dirname(__DIR__);
        $audit = new AuditLogger($this->pdo);
        $auth = new SessionAuth($this->pdo, new PasswordHasher(), new RateLimiter($this->pdo), $audit, 'test-key');
        $csrf = new Csrf();
        $kernel = new Kernel(new Config(['ROOT' => $root, 'APP_KEY' => 'test-key', 'APP_ENV' => 'test', 'APP_URL' => 'http://127.0.0.1:8090']), $this->pdo, $auth, $csrf, new Renderer($root . '/templates'), new Router());
        $post = ['carrier' => '合法快递', 'tracking_number' => 'SF12345', 'idempotency_key' => 'ship-http'];
        $res = $kernel->handle(new Request('POST', '/admin/orders/' . $id . '/ship', [], $post, [], []));
        $this->assertSame(302, $res->status());
        $_SESSION = ['uid' => 2, 'epoch' => 1];
        $res = $kernel->handle(new Request('POST', '/admin/orders/' . $id . '/ship', [], $post, [], []));
        $this->assertSame(302, $res->status());
        $_SESSION = ['uid' => 1, 'epoch' => 1];
        $this->assertSame(400, $kernel->handle(new Request('POST', '/admin/orders/' . $id . '/ship', [], $post, [], []))->status());
        $post['_csrf'] = $csrf->token();
        $this->assertSame(302, $kernel->handle(new Request('POST', '/admin/orders/' . $id . '/ship', [], $post, [], []))->status());
        $_SESSION = ['uid' => 3, 'epoch' => 1];
        $this->assertSame(422, $kernel->handle(new Request('POST', '/orders/' . $id . '/receive', [], ['_csrf' => $csrf->token()], [], []))->status());
        $_SESSION = ['uid' => 2, 'epoch' => 1];
        $this->assertSame(400, $kernel->handle(new Request('POST', '/orders/' . $id . '/receive', [], [], [], []))->status());
        $page = $kernel->handle(new Request('GET', '/orders/' . $id, [], [], [], []));
        $this->assertStringContainsString('SF12345', $page->body());
        $this->assertStringContainsString('确认已收到商品', $page->body());
        $this->assertStringNotContainsString('address_snapshot', $page->body());
        $this->assertSame(302, $kernel->handle(new Request('POST', '/orders/' . $id . '/receive', [], ['_csrf' => $csrf->token()], [], []))->status());
    }
}
