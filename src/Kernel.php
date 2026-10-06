<?php

declare(strict_types=1);

namespace IndependentMall;

use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Config\Config;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Http\Csrf;
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
        $this->router->add('GET', '/', function () {
            return Response::html($this->view->render('store/home', [
                'title' => 'Independent Mall',
            ]));
        });
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
}
