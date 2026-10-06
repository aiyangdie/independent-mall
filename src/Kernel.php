<?php

declare(strict_types=1);

namespace IndependentMall;

use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Auth\CustomerAccounts;
use IndependentMall\Config\Config;
use IndependentMall\Config\ShopSettings;
use IndependentMall\Mail\NullMailer;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Catalog\ImageStore;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Catalog\ProhibitedGoodsPolicy;
use IndependentMall\Install\Installer;
use IndependentMall\License\FileLicenseGate;
use IndependentMall\Modules\ModuleHost;
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

    /**
     * @param array<string, mixed> $data
     */
    public function page(string $view, array $data = [], int $status = 200): Response
    {
        try {
            $data['shop_name'] = (new ShopSettings($this->pdo))->get('shop_name', 'Independent Mall');
        } catch (\Throwable) {
            $data['shop_name'] = $data['shop_name'] ?? 'Independent Mall';
        }
        return Response::html($this->view->render($view, $data), $status);
    }

    public function handle(Request $request): Response
    {
        $installer = new Installer($this->config->root());
        $path = $request->path();
        $installTraffic = $path === '/install';
        if (!$installer->isInstalled() && !$this->schemaReady() && !$installTraffic && $path !== '/healthz') {
            return Response::redirect('/install');
        }
        return $this->router->dispatch($request);
    }

    private function schemaReady(): bool
    {
        try {
            $n = $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            return (int) $n > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function registerRoutes(): void
    {
        $this->router->add('GET', '/healthz', fn () => Response::json($this->health()));
        $this->router->add('GET', '/install', function () {
            $installer = new Installer($this->config->root());
            if ($installer->isInstalled()) {
                return $this->page('install/locked', [
                    'title' => 'Already installed',
                ], 409);
            }
            return $this->page('install/form', [
                'title' => 'Install Community Edition',
                'csrf' => $this->csrf->field(),
                'error' => '',
                'checks' => \IndependentMall\Install\Environment::checks($this->config->root()),
            ]);
        });
        $this->router->add('POST', '/install', function (Request $request) {
            $installer = new Installer($this->config->root());
            if ($installer->isInstalled()) {
                return $this->page('install/locked', ['title' => 'Already installed'], 409);
            }
            if (!$this->csrf->assert($request)) {
                return $this->page('install/form', [
                    'title' => 'Install Community Edition',
                    'csrf' => $this->csrf->field(),
                    'error' => 'The form expired. Try again.',
                    'checks' => \IndependentMall\Install\Environment::checks($this->config->root()),
                ], 400);
            }
            try {
                $installer->install([
                    'admin_email' => $request->post('admin_email'),
                    'admin_username' => $request->post('admin_username', 'admin'),
                    'admin_password' => $request->post('admin_password'),
                    'shop_name' => $request->post('shop_name', 'Independent Mall'),
                    'app_url' => $request->post('app_url', $this->config->get('APP_URL')),
                    'database_url' => $request->post('database_url', $this->config->get('DATABASE_URL')),
                    'seed' => $request->post('seed'),
                ]);
            } catch (\Throwable $e) {
                return $this->page('install/form', [
                    'title' => 'Install Community Edition',
                    'csrf' => $this->csrf->field(),
                    'error' => $e->getMessage(),
                    'checks' => \IndependentMall\Install\Environment::checks($this->config->root()),
                ], 422);
            }
            return Response::redirect('/admin/login');
        });
        $this->router->add('GET', '/admin/login', function () {
            if ($this->auth->can('admin.access')) {
                return Response::redirect('/admin');
            }
            return $this->page('admin/login', [
                'title' => 'Admin sign in',
                'csrf' => $this->csrf->field(),
                'error' => '',
            ]);
        });
        $this->router->add('POST', '/admin/login', function (Request $request) {
            if (!$this->csrf->assert($request)) {
                return $this->page('admin/login', [
                    'title' => 'Admin sign in',
                    'csrf' => $this->csrf->field(),
                    'error' => 'The form expired. Try again.',
                ], 400);
            }
            $ok = $this->auth->login($request->post('identity'), $request->post('password'), $request->ip());
            if ($ok && !$this->auth->can('admin.access')) {
                $this->auth->logout();
                $this->resumeSession();
                $ok = false;
            }
            if (!$ok) {
                return $this->page('admin/login', [
                    'title' => 'Admin sign in',
                    'csrf' => $this->csrf->field(),
                    'error' => 'Sign-in failed or this account is not an administrator.',
                ], 401);
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
            return $this->page('admin/dashboard', [
                'title' => 'Dashboard',
                'user' => $user,
                'csrf' => $this->csrf->field(),
                'health' => $this->health(),
            ]);
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
            'installed' => (new Installer($this->config->root()))->isInstalled(),
            'modules' => count($this->modules()->modules()),
            'module_faults' => count($this->modules()->faults()),
        ];
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

    private function customers(): CustomerAccounts
    {
        return new CustomerAccounts(
            $this->pdo,
            new PasswordHasher(),
            new NullMailer(),
            new AuditLogger($this->pdo),
            $this->auth,
        );
    }

    private function modules(): ModuleHost
    {
        return new ModuleHost($this->pdo, $this->config->root(), FileLicenseGate::fromRoot($this->config->root()), new AuditLogger($this->pdo));
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
            $catalog = $this->catalog();
            return $this->page('store/home', [
                'title' => 'Independent Mall',
                'products' => $catalog->storefrontProducts(),
                'categories' => $catalog->categories(),
                'notice' => $this->modules()->storefrontNotice(),
            ]);
        });
        $this->router->add('GET', '/search', function (Request $request) {
            $q = $request->query('q');
            return $this->page('store/search', [
                'title' => 'Search',
                'q' => $q,
                'products' => $this->catalog()->search($q),
                'categories' => $this->catalog()->categories(),
            ]);
        });
        $this->router->add('GET', '#^/c/([a-z0-9-]+)$#', function (Request $request, array $m) {
            $catalog = $this->catalog();
            $category = $catalog->categoryBySlug($m[1]);
            if ($category === null) {
                return $this->page('store/not-found', ['title' => 'Not found'], 404);
            }
            return $this->page('store/category', [
                'title' => $category['name'],
                'category' => $category,
                'products' => $catalog->productsInCategory((int) $category['id']),
                'categories' => $catalog->categories(),
            ]);
        });
        $this->router->add('GET', '#^/p/([a-z0-9-]+)$#', function (Request $request, array $m) {
            $catalog = $this->catalog();
            $product = $catalog->productBySlug($m[1]);
            if ($product === null || $product['status'] !== 'active') {
                return $this->page('store/not-found', ['title' => 'Not found'], 404);
            }
            return $this->page('store/product', [
                'title' => $product['name'],
                'product' => $product,
                'variants' => $catalog->variants((int) $product['id']),
                'images' => $catalog->images((int) $product['id']),
                'csrf' => $this->csrf->field(),
            ]);
        });
        $this->router->add('POST', '#^/p/([a-z0-9-]+)/report$#', function (Request $request, array $m) {
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            $catalog = $this->catalog();
            $product = $catalog->productBySlug($m[1]);
            if ($product === null) {
                return $this->page('store/not-found', ['title' => 'Not found'], 404);
            }
            $catalog->report((int) $product['id'], $request->post('reason', 'other'), $request->post('detail'));
            return Response::redirect('/p/' . $m[1]);
        });
        $this->router->add('GET', '#^/media/([a-f0-9]{32}\.(jpg|png|webp))$#', function (Request $request, array $m) {
            $path = $this->images()->pathFor($m[1]);
            if ($path === null) {
                return $this->page('store/not-found', ['title' => 'Not found'], 404);
            }
            $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
            return Response::file($path, $mime);
        });
        $this->router->add('GET', '/admin/products', function () {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            return $this->page('admin/products', [
                'title' => 'Products',
                'products' => $this->catalog()->adminProducts(),
                'categories' => $this->catalog()->categories(false),
                'csrf' => $this->csrf->field(),
                'error' => '',
                'user' => $this->auth->user(),
            ]);
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
                return $this->page('admin/products', [
                    'title' => 'Products',
                    'products' => $this->catalog()->adminProducts(),
                    'categories' => $this->catalog()->categories(false),
                    'csrf' => $this->csrf->field(),
                    'error' => $e->getMessage(),
                    'user' => $actor,
                ], 422);
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
        $this->router->add('GET', '/admin/categories', function () {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            return $this->page('admin/categories', [
                'title' => 'Categories',
                'categories' => $this->catalog()->categories(false),
                'csrf' => $this->csrf->field(),
                'error' => '',
                'user' => $this->auth->user(),
            ]);
        });
        $this->router->add('POST', '/admin/categories', function (Request $request) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $this->catalog()->createCategory($request->post('name'), $request->post('slug'));
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/admin/categories');
        });
        $this->router->add('GET', '/admin/rules', function () {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            return $this->page('admin/rules', [
                'title' => 'Prohibited listing rules',
                'rules' => $this->catalog()->prohibitedRules(),
                'user' => $this->auth->user(),
            ]);
        });
        $this->router->add('GET', '/admin/audit', function () {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            $rows = $this->pdo->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 100')->fetchAll() ?: [];
            return $this->page('admin/audit', [
                'title' => 'Audit log',
                'rows' => $rows,
                'user' => $this->auth->user(),
            ]);
        });
        $this->router->add('GET', '/admin/customers', function () {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            return $this->page('admin/customers', [
                'title' => 'Customers',
                'accounts' => $this->customers()->directory(),
                'csrf' => $this->csrf->field(),
                'error' => '',
                'user' => $this->auth->user(),
            ]);
        });
        $this->router->add('POST', '#^/admin/customers/(\d+)/(active|locked)$#', function (Request $request, array $m) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $this->customers()->setStatus((int) $m[1], $m[2], (int) ($this->auth->user()['id'] ?? 0));
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/admin/customers');
        });
        $this->router->add('GET', '/admin/skus', function () {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            return $this->page('admin/skus', [
                'title' => 'SKUs and inventory',
                'skus' => $this->catalog()->skuDirectory(),
                'csrf' => $this->csrf->field(),
                'user' => $this->auth->user(),
            ]);
        });
        $this->router->add('POST', '#^/admin/skus/(\d+)/stock$#', function (Request $request, array $m) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            $ref = $request->post('reference', 'sku:' . $m[1] . ':' . bin2hex(random_bytes(4)));
            try {
                $this->catalog()->applyMovement((int) $m[1], $request->intPost('delta'), 0, 'adjust', $ref);
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/admin/skus');
        });
        $this->router->add('GET', '/admin/settings', function () {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            return $this->page('admin/settings', [
                'title' => 'Shop settings',
                'settings' => (new ShopSettings($this->pdo))->all(),
                'csrf' => $this->csrf->field(),
                'error' => '',
                'user' => $this->auth->user(),
            ]);
        });
        $this->router->add('POST', '/admin/settings', function (Request $request) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            $settings = new ShopSettings($this->pdo, new AuditLogger($this->pdo));
            try {
                $settings->put('shop_name', $request->post('shop_name'), $this->auth->user()['id'] ?? null);
                $settings->put('support_email', $request->post('support_email'), $this->auth->user()['id'] ?? null);
            } catch (\Throwable $e) {
                return $this->page('admin/settings', [
                    'title' => 'Shop settings',
                    'settings' => $settings->all(),
                    'csrf' => $this->csrf->field(),
                    'error' => $e->getMessage(),
                    'user' => $this->auth->user(),
                ], 422);
            }
            return Response::redirect('/admin/settings');
        });
        $this->router->add('GET', '/admin/modules', function () {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            $host = $this->modules();
            $rows = [];
            foreach ($host->modules() as $module) {
                $rows[] = $host->snapshot($module);
            }
            return $this->page('admin/modules', [
                'title' => 'Modules',
                'modules' => $rows,
                'faults' => $host->faults(),
                'csrf' => $this->csrf->field(),
                'error' => '',
                'user' => $this->auth->user(),
                'license_server' => $this->config->get('LICENSE_SERVER_URL'),
            ]);
        });
        $this->router->add('POST', '/admin/license', function (Request $request) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            $root = $this->config->root();
            $json = trim($request->post('license_json'));
            $pem = trim($request->post('public_pem'));
            if ($pem !== '') {
                if (!str_contains($pem, 'BEGIN PUBLIC KEY') && !str_contains($pem, 'BEGIN OPENSSH')) {
                    return Response::html('Public key PEM is invalid.', 422);
                }
                file_put_contents($root . '/var/license-public.pem', $pem);
            }
            file_put_contents($root . '/var/license.json', $json);
            $gate = FileLicenseGate::fromRoot($root);
            if (!$gate instanceof FileLicenseGate || $gate->payload() === null) {
                @unlink($root . '/var/license.json');
                return Response::html('License document failed verification. Community shop is unchanged.', 422);
            }
            return Response::redirect('/admin/modules');
        });
        $this->router->add('POST', '#^/admin/modules/([a-z0-9-]+)/(migrate|enable|disable|uninstall)$#', function (Request $request, array $m) {
            if ($deny = $this->requireAdmin()) {
                return $deny;
            }
            if (!$this->csrf->assert($request)) {
                return Response::html('Invalid form', 400);
            }
            $actor = $this->auth->user()['id'] ?? null;
            try {
                match ($m[2]) {
                    'migrate' => $this->modules()->migrate($m[1], $actor),
                    'enable' => $this->modules()->enable($m[1], $actor),
                    'disable' => $this->modules()->disable($m[1], $actor),
                    'uninstall' => $this->modules()->uninstall($m[1], $actor),
                };
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/admin/modules');
        });
    }

    private function registerCommerceRoutes(): void
    {
        ShopFront::register($this);
    }
}
