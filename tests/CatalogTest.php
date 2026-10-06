<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use DomainException;
use IndependentMall\Auth\AdminProvisioner;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Catalog\ImageStore;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Catalog\ProhibitedGoodsPolicy;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Http\Request;
use IndependentMall\Kernel;
use IndependentMall\Security\AuditLogger;
use IndependentMall\View\Renderer;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\Router;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Config\Config;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    private string $root;
    private \PDO $pdo;
    private ProductCatalog $catalog;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($this->pdo, $this->root . '/database/migrations'))->migrate();
        $this->catalog = new ProductCatalog(
            $this->pdo,
            new ProhibitedGoodsPolicy($this->pdo),
            new AuditLogger($this->pdo),
        );
    }

    public function testCreatePublishAndIdempotentStock(): void
    {
        $ids = $this->catalog->createDraft(
            1,
            'physical',
            'Cotton tote',
            'cotton-tote',
            'Reusable bag',
            'Canvas tote for groceries.',
            'TOTE-1',
            'Standard',
            1990,
            5,
            1,
        );
        $this->catalog->submitForReview($ids['product_id'], 1);
        $this->catalog->publish($ids['product_id'], 1);
        $product = $this->catalog->productBySlug('cotton-tote');
        $this->assertSame('active', $product['status']);

        $this->catalog->applyMovement($ids['variant_id'], 3, 0, 'adjust', 'restock-a');
        $this->catalog->applyMovement($ids['variant_id'], 3, 0, 'adjust', 'restock-a');
        $variant = $this->catalog->variants($ids['product_id'])[0];
        $this->assertSame(8, (int) $variant['on_hand']);
    }

    public function testProhibitedListingIsBlocked(): void
    {
        $this->expectException(DomainException::class);
        $this->catalog->createDraft(
            1,
            'service',
            '代刷流量套餐',
            'boost-pack',
            'illegal boost',
            'n/a',
            'BAD-1',
            'Default',
            100,
            0,
            1,
        );
    }

    public function testUploadRejectsPhpAndAcceptsPng(): void
    {
        $ids = $this->catalog->createDraft(1, 'physical', 'Mug', 'mug', '', '', 'MUG-1', 'Std', 500, 0, 1);
        $dir = sys_get_temp_dir() . '/im-up-' . bin2hex(random_bytes(3));
        $store = new ImageStore($this->pdo, $dir, new AuditLogger($this->pdo));

        $php = tempnam(sys_get_temp_dir(), 'im');
        file_put_contents($php, '<?php echo "no";');
        try {
            $store->store($ids['product_id'], [
                'name' => 'shell.php',
                'tmp_name' => $php,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($php),
            ], 1);
            $this->fail('php upload should fail');
        } catch (DomainException $e) {
            $this->assertStringContainsString('JPEG', $e->getMessage());
        } finally {
            @unlink($php);
        }

        $png = tempnam(sys_get_temp_dir(), 'im');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $key = $store->store($ids['product_id'], [
            'name' => 'dot.png',
            'tmp_name' => $png,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($png),
        ], 1);
        $this->assertNotNull($store->pathFor($key));
        @unlink($png);
    }

    public function testStorefrontHidesDrafts(): void
    {
        $this->catalog->createDraft(1, 'physical', 'Hidden draft', 'hidden-draft', '', '', 'HD-1', 'Std', 100, 0, 1);
        $this->assertSame([], $this->catalog->storefrontProducts());
        $config = Config::load($this->root);
        $hasher = new PasswordHasher();
        $auth = new SessionAuth($this->pdo, $hasher, new RateLimiter($this->pdo), new AuditLogger($this->pdo), 'k');
        $kernel = new Kernel($config, $this->pdo, $auth, new Csrf(), new Renderer($this->root . '/templates'), new Router());
        $home = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertStringContainsString('暂无已上架商品', $home->body());
        $detail = $kernel->handle(new Request('GET', '/p/hidden-draft', [], [], [], []));
        $this->assertSame(404, $detail->status());
    }

    public function testSearchAndCategoryHideDrafts(): void
    {
        $this->catalog->createDraft(1, 'physical', 'Hidden draft bag', 'hidden-draft-bag', 'secret', '', 'HD-2', 'Std', 100, 0, 1);
        $ids = $this->catalog->createDraft(1, 'physical', 'Cotton tote', 'cotton-tote-2', 'Reusable bag', '', 'TOTE-2', 'Std', 1990, 1, 1);
        $this->catalog->publish($ids['product_id'], 1);
        $hits = $this->catalog->search('tote');
        $this->assertCount(1, $hits);
        $this->assertSame('Cotton tote', $hits[0]['name']);
        $this->assertSame([], $this->catalog->search('Hidden draft'));
        $cat = $this->catalog->categoryBySlug('physical-goods');
        $this->assertNotNull($cat);
        $inCat = $this->catalog->productsInCategory((int) $cat['id']);
        $this->assertCount(1, $inCat);
        $config = Config::load($this->root);
        $auth = new SessionAuth($this->pdo, new PasswordHasher(), new RateLimiter($this->pdo), new AuditLogger($this->pdo), 'k');
        $kernel = new Kernel($config, $this->pdo, $auth, new Csrf(), new Renderer($this->root . '/templates'), new Router());
        $search = $kernel->handle(new Request('GET', '/search', ['q' => 'tote'], [], [], []));
        $this->assertSame(200, $search->status());
        $this->assertStringContainsString('Cotton tote', $search->body());
        $this->assertStringNotContainsString('Hidden draft bag', $search->body());
        $page = $kernel->handle(new Request('GET', '/c/physical-goods', [], [], [], []));
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Cotton tote', $page->body());
    }
}
