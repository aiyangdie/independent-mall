<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use IndependentMall\Catalog\Money;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Catalog\ProhibitedGoodsPolicy;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Security\AuditLogger;
use IndependentMall\Support\Chinese;
use IndependentMall\View\Renderer;
use PHPUnit\Framework\TestCase;

final class LocalizationTest extends TestCase
{
    private function renderer(): Renderer
    {
        return new Renderer(dirname(__DIR__) . '/templates');
    }

    public function testChineseMoneyKeepsIntegerPrecisionAndCorrectNegativeSign(): void
    {
        $this->assertSame('¥0.00', Money::format(0));
        $this->assertSame('¥19.90', Money::format(1990));
        $this->assertSame('-¥0.01', Money::format(-1));
        $this->assertSame('-¥19.90', Money::format(-1990));
        $this->assertSame('USD 19.90', Money::format(1990, 'USD'));
        $this->assertSame('¥19.90', Money::format(1990, 'cny'));
        if (PHP_INT_SIZE === 8) {
            $this->assertSame('¥92233720368547758.07', Money::format(PHP_INT_MAX));
            $this->assertSame('-¥92233720368547758.08', Money::format(PHP_INT_MIN));
        }
    }

    public function testKnownStatusAndCartIssuesHaveChineseLabelsWithoutChangingIdentifiers(): void
    {
        foreach (['in_review', 'pending_payment', 'fulfilling', 'shipped', 'delivered', 'refunded', 'approved', 'unbound', 'fake', 'wechat', 'unavailable', 'insufficient_stock'] as $state) {
            $this->assertNotSame($state, Chinese::label($state));
        }
        $this->assertSame('已批准待渠道处理', Chinese::label('approved'));
        $this->assertSame('CUSTOM-IDENTIFIER', Chinese::label('CUSTOM-IDENTIFIER'));
    }

    public function testErrorsAreChineseAndUnknownInternalDetailsAreNotExposed(): void
    {
        $this->assertSame('请输入有效的邮箱地址。', Chinese::message('A valid email is required.'));
        $this->assertSame('密码至少需要 10 个字符。', Chinese::message('Password must be at least 10 characters.'));
        $this->assertSame('购买实物商品时，请先选择收货地址。', Chinese::message('A shipping address is required for physical goods.'));
        $this->assertStringNotContainsString('SQLSTATE', Chinese::message('SQLSTATE[23000] /secret/path.db'));
        $this->assertSame('请先登录。', Chinese::message('请先登录。'));
        $this->assertSame('Merchant summer notice', Chinese::message('Merchant summer notice', false));
        $page = $this->renderer()->render('account/login', [
            'error' => 'Password is too common.', 'csrf' => '', 'current_path' => '/login',
        ]);
        $this->assertStringContainsString('密码过于常见', $page);
        $this->assertStringNotContainsString('Password is too common.', $page);
        $page = $this->renderer()->render('store/home', [
            'products' => [], 'categories' => [], 'notice' => 'Merchant summer notice', 'current_path' => '/',
        ]);
        $this->assertStringContainsString('Merchant summer notice', $page);
    }

    public function testOfficialUpdateScreenUsesChineseAndHidesVerificationInternals(): void
    {
        $data = ['report' => ['unsigned' => 'refused_auto_install', 'signed' => 'verified_not_applied', 'version' => '0.1.1', 'sha256' => 'abc', 'applied' => false]];
        ob_start();
        include dirname(__DIR__) . '/apps/www/templates/updates.php';
        $page = (string) ob_get_clean();
        $this->assertStringContainsString('已拒绝自动安装', $page);
        $this->assertStringContainsString('验签通过，尚未安装', $page);
        $this->assertStringNotContainsString('verified_not_applied', $page);
        $data['report']['signed'] = 'failed: SSL provider private detail';
        ob_start();
        include dirname(__DIR__) . '/apps/www/templates/updates.php';
        $page = (string) ob_get_clean();
        $this->assertStringContainsString('验证失败，请联系管理员', $page);
        $this->assertStringNotContainsString('SSL provider private detail', $page);
    }

    public function testOrderAndPaymentViewsTranslateStatesAndEscapeUnknownValues(): void
    {
        $order = ['id' => 1, 'public_id' => 'ORDER-1', 'status' => 'refunded', 'payable_minor' => 1990, 'currency' => 'CNY'];
        $page = $this->renderer()->render('account/orders', ['orders' => [$order], 'current_path' => '/orders']);
        $this->assertStringContainsString('已退款', $page);
        $this->assertStringNotContainsString('refunded', $page);
        $this->assertStringContainsString('¥19.90', $page);
        $payments = [['provider' => 'wechat', 'status' => 'succeeded', 'amount_minor' => 1990, 'currency' => 'CNY', 'provider_ref' => '<script>']];
        $page = $this->renderer()->render('store/payment-result', [
            'order' => $order, 'payments' => $payments, 'payment_provider' => 'wechat', 'current_path' => '/orders/1/result',
        ]);
        $this->assertStringContainsString('微信支付', $page);
        $this->assertStringContainsString('成功', $page);
        $this->assertStringNotContainsString('演示支付通道，不会真实扣款', $page);
        $this->assertStringContainsString('&lt;script&gt;', $page);
        $this->assertStringNotContainsString('<script>', $page);
        $page = $this->renderer()->render('store/payment-result', ['order' => $order, 'payments' => [], 'current_path' => '/orders/1/result']);
        $this->assertStringContainsString('演示支付通道，不会真实扣款', $page);
    }

    public function testNavigationMarksOnlyTheCurrentSection(): void
    {
        $page = $this->renderer()->render('store/search', ['products' => [], 'q' => '', 'current_path' => '/search']);
        $this->assertStringContainsString('class="active" aria-current="true" href="/#categories"', $page);
        $this->assertStringNotContainsString('class="active" aria-current="page" href="/"', $page);
        $this->assertStringContainsString('<h1>全部商品</h1>', $page);
        $page = $this->renderer()->render('store/home', ['products' => [], 'categories' => [], 'current_path' => '/']);
        $this->assertStringContainsString('class="active" aria-current="page" href="/"', $page);
        $this->assertStringContainsString('href="/search">查看全部', $page);
    }

    public function testBlankSearchShowsOnlyPublishedActiveCategoryProductsWithRealPrices(): void
    {
        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($pdo, dirname(__DIR__) . '/database/migrations'))->migrate();
        $catalog = new ProductCatalog($pdo, new ProhibitedGoodsPolicy($pdo), new AuditLogger($pdo));
        $published = $catalog->createDraft(1, 'physical', '陶瓷杯', 'test-mug', '日常饮水用杯', '', 'TEST-MUG', '标准款', 1990, 3, null);
        $catalog->publish($published['product_id'], null);
        $catalog->createDraft(1, 'physical', '未发布草稿', 'draft-mug', '', '', 'DRAFT-MUG', '标准款', 100, 1, null);
        $products = $catalog->search('  ');
        $this->assertCount(1, $products);
        $this->assertSame(1990, (int) $products[0]['price_minor']);
        $this->assertSame('CNY', $products[0]['currency']);
        $page = $this->renderer()->render('store/search', ['products' => $products, 'q' => '', 'current_path' => '/search']);
        $this->assertStringContainsString('陶瓷杯', $page);
        $this->assertStringContainsString('实物商品', $page);
        $this->assertStringContainsString('¥19.90', $page);
        $this->assertStringContainsString('card product-card', $page);
        $this->assertStringNotContainsString('未发布草稿', $page);
        $this->assertSame($products, $catalog->productsInCategory(1));
        $this->assertSame($products, $catalog->search('陶瓷'));
        $pdo->exec("UPDATE categories SET status = 'hidden' WHERE id = 1");
        $this->assertSame([], $catalog->search(''));
    }

    public function testCheckoutKeepsInternalIdempotencyKeyHidden(): void
    {
        $page = $this->renderer()->render('store/checkout', ['lines' => [], 'addresses' => [], 'csrf' => '', 'current_path' => '/checkout']);
        $this->assertStringContainsString('type="hidden" name="idempotency_key"', $page);
        $this->assertStringNotContainsString('幂等键', $page);
    }

    public function testRefundViewDistinguishesApprovalFromConfirmedRealRefund(): void
    {
        $page = $this->renderer()->render('admin/refunds', [
            'refunds' => [['id' => 3, 'order_id' => 1, 'public_id' => 'ORDER-1', 'status' => 'approved', 'amount_minor' => 100, 'provider' => 'wechat']],
            'csrf' => '', 'current_path' => '/admin/refunds',
        ]);
        $this->assertStringContainsString('已批准待渠道处理', $page);
        $this->assertStringContainsString('/admin/refunds/3/wechat-submit', $page);
        $this->assertStringContainsString('/admin/refunds/3/wechat-sync', $page);
        $this->assertStringContainsString('微信支付', $page);
    }
}
