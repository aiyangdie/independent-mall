<?php

declare(strict_types=1);

namespace IndependentMall\OfficialSite;

use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\Request;
use IndependentMall\Http\Response;
use IndependentMall\LicenseServer\Signer;
use IndependentMall\LicenseServer\Store;
use PDO;

final class Site
{
    public function __construct(
        private Hub $hub,
        private Csrf $csrf,
        private string $templates,
    ) {
    }

    public static function boot(string $root): self
    {
        $var = $root . '/var';
        if (!is_dir($var)) {
            mkdir($var, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $var . '/site.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $keyFile = $var . '/hmac.key';
        if (!is_file($keyFile)) {
            file_put_contents($keyFile, bin2hex(random_bytes(32)));
        }
        $priv = $var . '/demo-private.pem';
        $pub = $var . '/demo-public.pem';
        if (!is_file($priv) || !is_file($pub)) {
            $pair = Signer::generateKeypair();
            file_put_contents($priv, $pair['private']);
            file_put_contents($pub, $pair['public']);
        }
        $hub = new Hub(
            $pdo,
            new PasswordHasher(),
            new Store($pdo),
            trim((string) file_get_contents($keyFile)),
            $root . '/data/files',
            (string) file_get_contents($priv),
            (string) file_get_contents($pub),
        );
        return new self($hub, new Csrf(), $root . '/templates');
    }

    public static function forTests(Hub $hub, Csrf $csrf, string $templates): self
    {
        return new self($hub, $csrf, $templates);
    }

    public function handle(Request $request): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $path = $request->path();
        $method = $request->method();
        try {
            return match (true) {
                $method === 'GET' && $path === '/' => $this->page('home', ['title' => 'Independent Mall']),
                $method === 'GET' && $path === '/editions' => $this->page('editions', ['title' => 'Editions']),
                $method === 'GET' && $path === '/pricing' => $this->page('pricing', ['title' => 'Pricing']),
                $method === 'GET' && $path === '/changelog' => $this->page('changelog', ['title' => 'Changelog']),
                $method === 'GET' && $path === '/docs' => $this->page('docs', ['title' => 'Documentation']),
                $method === 'GET' && $path === '/contact' => $this->page('contact', ['title' => 'Contact']),
                $method === 'GET' && $path === '/legal/terms' => $this->page('legal', ['title' => 'Terms', 'kind' => 'terms']),
                $method === 'GET' && $path === '/legal/privacy' => $this->page('legal', ['title' => 'Privacy', 'kind' => 'privacy']),
                $method === 'GET' && $path === '/legal/aup' => $this->page('legal', ['title' => 'Acceptable use', 'kind' => 'aup']),
                $method === 'GET' && $path === '/legal/refunds' => $this->page('legal', ['title' => 'Refunds', 'kind' => 'refunds']),
                $method === 'GET' && $path === '/legal/prohibited' => $this->page('legal', ['title' => 'Prohibited goods', 'kind' => 'prohibited']),
                $method === 'GET' && $path === '/legal/disclaimer' => $this->page('legal', ['title' => 'Disclaimer', 'kind' => 'disclaimer']),
                $method === 'GET' && $path === '/updates.json' => Response::json($this->hub->updates()),
                $method === 'GET' && $path === '/register' => $this->page('register', ['title' => 'Register', 'error' => '']),
                $method === 'POST' && $path === '/register' => $this->postRegister($request),
                $method === 'GET' && $path === '/login' => $this->page('login', ['title' => 'Sign in', 'error' => '']),
                $method === 'POST' && $path === '/login' => $this->postLogin($request),
                $method === 'POST' && $path === '/logout' => $this->logout($request),
                $method === 'GET' && $path === '/account' => $this->account(),
                $method === 'GET' && $path === '/account/updates' => $this->inspectUpdates(),
                $method === 'POST' && $path === '/account/buy' => $this->buy($request),
                $method === 'POST' && $path === '/account/activate' => $this->activate($request),
                $method === 'POST' && $path === '/account/unbind' => $this->unbind($request),
                $method === 'POST' && $path === '/account/revoke' => $this->revoke($request),
                $method === 'POST' && $path === '/account/renew' => $this->renew($request),
                $method === 'POST' && $path === '/account/ticket' => $this->ticket($request),
                $method === 'POST' && $path === '/account/ticket/answer' => $this->ticketAnswer($request),
                $method === 'POST' && $path === '/account/ticket/close' => $this->ticketClose($request),
                $method === 'GET' && $path === '/account/download' => $this->download($request),
                $method === 'POST' && $path === '/account/download/revoke' => $this->revokeDownload($request),
                default => $this->page('not-found', ['title' => 'Not found'], 404),
            };
        } catch (\Throwable $e) {
            return $this->page('error', ['title' => 'Error', 'message' => $e->getMessage()], 400);
        }
    }

    private function postRegister(Request $request): Response
    {
        if (!$this->csrf->assert($request)) {
            return $this->page('register', ['title' => 'Register', 'error' => 'CSRF check failed.'], 400);
        }
        try {
            $id = $this->hub->register($request->post('email'), $request->post('password'));
        } catch (\Throwable $e) {
            return $this->page('register', ['title' => 'Register', 'error' => $e->getMessage()], 400);
        }
        $_SESSION['www_user'] = $id;
        return Response::redirect('/account');
    }

    private function postLogin(Request $request): Response
    {
        if (!$this->csrf->assert($request)) {
            return $this->page('login', ['title' => 'Sign in', 'error' => 'CSRF check failed.'], 400);
        }
        try {
            $id = $this->hub->login($request->post('email'), $request->post('password'));
        } catch (\Throwable $e) {
            return $this->page('login', ['title' => 'Sign in', 'error' => $e->getMessage()], 400);
        }
        $_SESSION['www_user'] = $id;
        return Response::redirect('/account');
    }

    private function logout(Request $request): Response
    {
        if (!$this->csrf->assert($request)) {
            return Response::html('CSRF', 400);
        }
        unset($_SESSION['www_user']);
        return Response::redirect('/');
    }

    private function userId(): int
    {
        $id = $_SESSION['www_user'] ?? 0;
        return is_int($id) ? $id : 0;
    }

    private function account(): Response
    {
        $uid = $this->userId();
        if ($uid < 1) {
            return Response::redirect('/login');
        }
        $files = [];
        foreach (array_keys(Hub::FILES) as $id) {
            $files[$id] = $this->hub->downloadToken($uid, $id);
        }
        return $this->page('account', [
            'title' => 'Customer center',
            'orders' => $this->hub->orders($uid),
            'licenses' => $this->hub->licenses($uid),
            'tickets' => $this->hub->tickets($uid),
            'security' => $this->hub->securityLog($uid),
            'files' => $files,
            'active_tokens' => $this->hub->activeDownloadTokens($uid),
            'downloads' => $this->hub->downloadAudits($uid),
            'flash' => (string) ($_SESSION['www_flash'] ?? ''),
        ]);
    }

    private function inspectUpdates(): Response
    {
        $uid = $this->userId();
        if ($uid < 1) {
            return Response::redirect('/login');
        }
        return $this->page('updates', [
            'title' => 'Check updates',
            'report' => $this->hub->inspectUpdates($uid),
        ]);
    }

    private function buy(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $bought = $this->hub->fakeBuyNotice($uid);
        $_SESSION['www_flash'] = 'Fake payment recorded. Product '
            . $bought['product_code'] . '@' . $bought['product_version']
            . '. License ' . $bought['license_id']
            . ' token (shown once): ' . $bought['token']
            . ' nonce (shown once): ' . $bought['nonce'];
        return Response::redirect('/account');
    }

    private function activate(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->activate(
            $uid,
            $request->post('license_id'),
            $request->post('token'),
            $request->post('instance_id'),
            $request->post('nonce'),
        );
        $_SESSION['www_flash'] = 'Instance bound.';
        return Response::redirect('/account');
    }

    private function unbind(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $next = $this->hub->unbind($uid, $request->post('license_id'), $request->post('token'));
        $_SESSION['www_flash'] = 'Instance unbound. Next activation nonce (shown once): ' . $next;
        return Response::redirect('/account');
    }

    private function revoke(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->revoke($uid, $request->post('license_id'), $request->post('token'));
        $_SESSION['www_flash'] = 'License marked revoked. Commercial modules stay off; Community shops keep selling.';
        return Response::redirect('/account');
    }

    private function renew(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $days = (int) $request->post('days');
        if ($days < 1) {
            $days = 365;
        }
        $result = $this->hub->fakeRenew($uid, $request->post('license_id'), $request->post('token'), $days);
        $_SESSION['www_flash'] = 'Fake renewal recorded. New expiry: ' . $result['expires_at'];
        return Response::redirect('/account');
    }

    private function ticket(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->openTicket($uid, $request->post('title'), $request->post('body'));
        $_SESSION['www_flash'] = 'Ticket opened.';
        return Response::redirect('/account');
    }

    private function ticketAnswer(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->answerTicket($uid, (int) $request->post('ticket_id'), $request->post('reply'));
        $_SESSION['www_flash'] = 'Demo staff reply saved (Fake operator).';
        return Response::redirect('/account');
    }

    private function ticketClose(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->closeTicket($uid, (int) $request->post('ticket_id'));
        $_SESSION['www_flash'] = 'Ticket closed.';
        return Response::redirect('/account');
    }

    private function download(Request $request): Response
    {
        $uid = $this->userId();
        if ($uid < 1) {
            return Response::html('Sign in required.', 401);
        }
        $path = $this->hub->resolveDownload($uid, $request->query('token'));
        $body = (string) file_get_contents($path);
        return new Response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
        ]);
    }

    private function revokeDownload(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->revokeDownloadToken($uid, $request->post('jti'));
        $_SESSION['www_flash'] = 'Download link revoked.';
        return Response::redirect('/account');
    }

    private function requireUser(Request $request): int
    {
        if (!$this->csrf->assert($request)) {
            throw new \DomainException('CSRF check failed.');
        }
        $uid = $this->userId();
        if ($uid < 1) {
            throw new \DomainException('Sign in required.');
        }
        return $uid;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(string $view, array $data, int $status = 200): Response
    {
        $data['csrf'] = $this->csrf;
        $data['signedIn'] = $this->userId() > 0;
        if (isset($data['flash'])) {
            unset($_SESSION['www_flash']);
        }
        ob_start();
        $viewFile = $this->templates . '/' . $view . '.php';
        $layout = $this->templates . '/layout.php';
        include $layout;
        return Response::html((string) ob_get_clean(), $status);
    }
}
