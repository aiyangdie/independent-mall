<?php

declare(strict_types=1);

namespace IndependentMall;

use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Config\Config;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Catalog\ImageStore;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Catalog\ProhibitedGoodsPolicy;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\ShopFront;
use IndependentMall\Http\Request;
use IndependentMall\Http\Response;
use IndependentMall\Http\Router;
use IndependentMall\Security\AuditLogger;
use IndependentMall\View\Renderer;
use PDO;

final class Kernel
{
    public function __construct(
        public readonly Config $config,
        public readonly PDO $pdo,
        public readonly SessionAuth $auth,
        public readonly Csrf $csrf,
        public readonly Renderer $view,
        public readonly Router $router,
    ) {
        $this->registerRoutes();
    }

    public static function boot(string $root, bool $startSession = true): self
    {
        $config = Config::load($root);
        date_default_timezone_set($config->get('APP_TIMEZONE', 'UTC'));
        $pdo = ConnectionFactory::make($config->get('DATABASE_URL'));
        $hasher = new PasswordHasher();
        $limiter = new RateLimiter($pdo);
        $audit = new AuditLogger($pdo);
        $auth = new SessionAuth($pdo, $hasher, $limiter, $audit, $config->get('APP_KEY'));
        if ($startSession) {
            $auth->start(
                $root . '/var/sessions',
                $config->get('SESSION_NAME', 'im_session'),
                $config->int('SESSION_LIFETIME', 7200),
                $config->bool('SESSION_SECURE'),
            );
        }
        $csrf = new Csrf();
        $view = new Renderer($root . '/templates');
        $router = new Router();
        $kernel = new self($config, $pdo, $auth, $csrf, $view, $router);
        return $kernel;
    }

    private function registerRoutes(): void
    {
        $this->router->add('GET', '/healthz', fn () => Response::json($this->health()));
        $this->router->add('GET', '/admin/login', function () {
            if ($this->auth->can('admin.access')) {
                return Response::redirect('/admin');
            }
            return Response::html($this->view->render('admin/login', [
                'title' => 'Admin sign in',
                'csrf' => $this->csrf->field(),
                'error' => '',
            ]));
        });
        $this->router->add('POST', '/admin/login', function (Request $request) {
            if (!$this->csrf->assert($request)) {
                return Response::html($this->view->render('admin/login', [
                    'title' => 'Admin sign in',
                    'csrf' => $this->csrf->field(),
                    'error' => 'The form expired. Try again.',
                ]), 400);
            }
            $ok = $this->auth->login($request->post('identity'), $request->post('password'), $request->ip());
            if ($ok && !$this->auth->can('admin.access')) {
                $this->auth->logout();
                $this->resumeSession();
                $ok = false;
            }
            if (!$ok) {
                return Response::html($this->view->render('admin/login', [
                    'title' => 'Admin sign in',
                    'csrf' => $this->csrf->field(),
                    'error' => 'Sign-in failed or this account is not an administrator.',
                ]), 401);
            }
            return Response::redirect('/admin');
        });
        $this->router->add('POST', '/admin/logout', function (Request $request) {
            if (!$this->csrf->assert($request)) {
                return Response::redirect('/admin/login');
            }
            $this->auth->logout();
            return Response::redirect('/admin/login');
        });
        $this->router->add('GET', '/admin', function () {
            if (!$this->auth->can('admin.access')) {
                return Response::redirect('/admin/login');
            }
            $user = $this->auth->user();
            return Response::html($this->view->render('admin/dashboard', [
                'title' => 'Dashboard',
                'user' => $user,
                'csrf' => $this->csrf->field(),
                'health' => $this->health(),
            ]));
        });
        $this->registerCatalogRoutes();
        $this->registerCommerceRoutes();
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $db = 'ok';
        try {
            $this->pdo->query('SELECT 1');
        } catch (\Throwable) {
            $db = 'error';
        }
        return [
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'edition' => 'community',
            'license_required' => false,
            'php' => PHP_VERSION,
            'database' => $db,
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->pdo, $this->config->root() . '/database/migrations');
    }

    private function resumeSession(): void
    {
        $this->auth->start(
            $this->config->root() . '/var/sessions',
            $this->config->get('SESSION_NAME', 'im_session'),
            $this->config->int('SESSION_LIFETIME', 7200),
            $this->config->bool('SESSION_SECURE'),
        );
    }

    private function catalog(): ProductCatalog
    {
        return new ProductCatalog($this->pdo, new ProhibitedGoodsPolicy($this->pdo), new AuditLogger($this->pdo));
    }

    private function images(): ImageStore
    {
        return new ImageStore($this->pdo, $this->config->root() . '/var/uploads', new AuditLogger($this->pdo));
    }

    private function requireAdmin(): ?Response
    {
        if (!$this->auth->can('admin.access')) {
            return Response::redirect('/admin/login');
        }
        return null;
    }

    private function registerCatalogRoutes(): void
    {
        $this->router->add('GET', '/', function () {
            $items = $this->catalog()->storefrontProducts();
            return Response::html($this->view->render('store/home', [
                'title' => 'Independent Mall',
                'products' => $items,
            ]));
        });
        $this->router->add('GET', '#^/p/([a-z0-9-]+)$#', function (Request $request, array $m) {
            $catalog = $this->catalog();
            $product = $catalog->productBySlug($m[1]);
            if ($product === null || $product['status'] !== 'active') {
                return Response::html('Not found', 404);
            }
            return Response::html($this->view->render('store/product', [
                'title' => $product['name'],
                'product' => $product,
                'variants' => $catalog->variants((int) $product['id']),
                'images' => $catalog->images((int) $product['id']),
                'csrf' => $this->csrf->field(),
            ]));
        });
        $this->router->add('POST', '#^/p/([a-z0-9-]+)/report$#', function (Request $request, array $m) {
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            $catalog = $this->catalog();
            $product = $catalog->productBySlug($m[1]);
            if ($product === null) {
                return Response::html('Not found', 404);
            }
            $catalog->report((int) $product['id'], $request->post('reason', 'other'), $request->post('detail'));
            return Response::redirect('/p/' . $m[1]);
        });
        $this->router->add('GET', '#^/media/([a-f0-9]{32}\.(jpg|png|webp))$#', function (Request $request, array $m) {
            $path = $this->images()->pathFor($m[1]);
            if ($path === null) {
                return Response::html('Not found', 404);
            }
            $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
            return Response::file($path, $mime);
        });
        $this->router->add('GET', '/admin/products', function () {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            return Response::html($this->view->render('admin/products', [
                'title' => 'Products',
                'products' => $this->catalog()->adminProducts(),
                'categories' => $this->catalog()->categories(false),
                'csrf' => $this->csrf->field(),
                'error' => '',
                'user' => $this->auth->user(),
            ]));
        });
        $this->router->add('POST', '/admin/products', function (Request $request) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            $actor = $this->auth->user();
            try {
                $this->catalog()->createDraft(
                    $request->intPost('category_id'),
                    $request->post('type'),
                    trim($request->post('name')),
                    trim($request->post('slug')),
                    trim($request->post('summary')),
                    trim($request->post('description')),
                    trim($request->post('sku')),
                    trim($request->post('variant_title', 'Standard')),
                    $request->intPost('price_minor'),
                    $request->intPost('on_hand'),
                    $actor['id'] ?? null,
                );
            } catch (\Throwable $e) {
                return Response::html($this->view->render('admin/products', [
                    'title' => 'Products',
                    'products' => $this->catalog()->adminProducts(),
                    'categories' => $this->catalog()->categories(false),
                    'csrf' => $this->csrf->field(),
                    'error' => $e->getMessage(),
                    'user' => $actor,
                ]), 422);
            }
            return Response::redirect('/admin/products');
        });
        $this->router->add('POST', '#^/admin/products/(\d+)/(submit|publish|reject|archive)$#', function (Request $request, array $m) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            $id = (int) $m[1];
            $actor = $this->auth->user()['id'] ?? null;
            $catalog = $this->catalog();
            try {
                match ($m[2]) {
                    'submit' => $catalog->submitForReview($id, $actor),
                    'publish' => $catalog->publish($id, $actor),
                    'reject' => $catalog->reject($id, $request->post('note', 'Rejected'), $actor),
                    'archive' => $catalog->archive($id, $actor),
                };
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/admin/products');
        });
        $this->router->add('POST', '#^/admin/products/(\d+)/image$#', function (Request $request, array $m) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $this->images()->store((int) $m[1], $request->file('image'), $this->auth->user()['id'] ?? null);
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/admin/products');
        });
        $this->router->add('POST', '#^/admin/products/(\d+)/stock$#', function (Request $request, array $m) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            $variants = $this->catalog()->variants((int) $m[1]);
            if ($variants === []) {
                return Response::html('No variant', 422);
            }
            $delta = $request->intPost('delta');
            $ref = $request->post('reference', 'manual:' . bin2hex(random_bytes(4)));
            try {
                $this->catalog()->applyMovement((int) $variants[0]['id'], $delta, 0, 'adjust', $ref);
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/admin/products');
        });
    }

    private function registerCommerceRoutes(): void
    {
        ShopFront::register($this);
    }
}
