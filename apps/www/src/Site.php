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
                $method === 'GET' && $path === '/' => $this->page('home', ['title' => '独立商城']),
                $method === 'GET' && $path === '/editions' => $this->page('editions', ['title' => '版本对比']),
                $method === 'GET' && $path === '/pricing' => $this->page('pricing', ['title' => '产品价格']),
                $method === 'GET' && $path === '/changelog' => $this->page('changelog', ['title' => '更新日志']),
                $method === 'GET' && $path === '/docs' => $this->page('docs', ['title' => '产品文档']),
                $method === 'GET' && $path === '/contact' => $this->page('contact', ['title' => '联系我们']),
                $method === 'GET' && $path === '/legal/terms' => $this->page('legal', ['title' => '服务条款', 'kind' => 'terms']),
                $method === 'GET' && $path === '/legal/privacy' => $this->page('legal', ['title' => '隐私政策', 'kind' => 'privacy']),
                $method === 'GET' && $path === '/legal/aup' => $this->page('legal', ['title' => '可接受使用政策', 'kind' => 'aup']),
                $method === 'GET' && $path === '/legal/refunds' => $this->page('legal', ['title' => '退款政策', 'kind' => 'refunds']),
                $method === 'GET' && $path === '/legal/prohibited' => $this->page('legal', ['title' => '禁售商品', 'kind' => 'prohibited']),
                $method === 'GET' && $path === '/legal/disclaimer' => $this->page('legal', ['title' => '免责声明', 'kind' => 'disclaimer']),
                $method === 'GET' && $path === '/updates.json' => Response::json($this->hub->updates()),
                $method === 'GET' && $path === '/register' => $this->page('register', ['title' => '注册', 'error' => '']),
                $method === 'POST' && $path === '/register' => $this->postRegister($request),
                $method === 'GET' && $path === '/login' => $this->page('login', ['title' => '登录', 'error' => '']),
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
                $method === 'GET' && $path === '/account/license.json' => $this->downloadLicenseJson($request),
                $method === 'GET' && $path === '/account/license-public.pem' => $this->downloadLicensePublicPem(),
                default => $this->page('not-found', ['title' => '页面不存在'], 404),
            };
        } catch (\Throwable $e) {
            return $this->page('error', ['title' => '发生错误', 'message' => $e->getMessage()], 400);
        }
    }

    private function postRegister(Request $request): Response
    {
        if (!$this->csrf->assert($request)) {
            return $this->page('register', ['title' => '注册', 'error' => '表单安全校验失败，请刷新后重试。'], 400);
        }
        try {
            $id = $this->hub->register($request->post('email'), $request->post('password'));
        } catch (\Throwable $e) {
            return $this->page('register', ['title' => '注册', 'error' => $e->getMessage()], 400);
        }
        $_SESSION['www_user'] = $id;
        return Response::redirect('/account');
    }

    private function postLogin(Request $request): Response
    {
        if (!$this->csrf->assert($request)) {
            return $this->page('login', ['title' => '登录', 'error' => '表单安全校验失败，请刷新后重试。'], 400);
        }
        try {
            $id = $this->hub->login($request->post('email'), $request->post('password'));
        } catch (\Throwable $e) {
            return $this->page('login', ['title' => '登录', 'error' => $e->getMessage()], 400);
        }
        $_SESSION['www_user'] = $id;
        return Response::redirect('/account');
    }

    private function logout(Request $request): Response
    {
        if (!$this->csrf->assert($request)) {
            return Response::html('表单安全校验失败', 400);
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
            'title' => '客户中心',
            'orders' => $this->hub->orders($uid),
            'issuer_orders' => $this->hub->issuerOrdersForAccount($uid),
            'issuer_customer_id' => $this->hub->issuerCustomerIdForAccount($uid),
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
            'title' => '检查更新',
            'report' => $this->hub->inspectUpdates($uid),
        ]);
    }

    private function buy(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $bought = $this->hub->fakeBuyNotice($uid);
        $_SESSION['www_flash'] = '演示购买已记录。产品 '
            . $bought['product_code'] . '@' . $bought['product_version']
            . '，客户 ' . $bought['customer_id']
            . '，授权订单 ' . $bought['issuer_order_id']
            . '。授权编号 ' . $bought['license_id']
            . '，授权令牌（仅显示一次）：' . $bought['token']
            . '，激活随机码（仅显示一次）：' . $bought['nonce'];
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
        $_SESSION['www_flash'] = '实例绑定成功。';
        return Response::redirect('/account');
    }

    private function unbind(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $next = $this->hub->unbind($uid, $request->post('license_id'), $request->post('token'));
        $_SESSION['www_flash'] = '实例已解绑。下次激活随机码（仅显示一次）：' . $next;
        return Response::redirect('/account');
    }

    private function revoke(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->revoke($uid, $request->post('license_id'), $request->post('token'));
        $_SESSION['www_flash'] = '授权已吊销。商业模块将保持停用，社区版商城仍可正常销售。';
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
        $_SESSION['www_flash'] = '演示续费已记录。授权订单 '
            . $result['issuer_order_id']
            . '，客户 ' . $result['customer_id']
            . '。新到期时间：' . $result['expires_at'];
        return Response::redirect('/account');
    }

    private function ticket(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->openTicket($uid, $request->post('title'), $request->post('body'));
        $_SESSION['www_flash'] = '工单已提交。';
        return Response::redirect('/account');
    }

    private function ticketAnswer(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->answerTicket($uid, (int) $request->post('ticket_id'), $request->post('reply'));
        $_SESSION['www_flash'] = '演示客服回复已保存。';
        return Response::redirect('/account');
    }

    private function ticketClose(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->closeTicket($uid, (int) $request->post('ticket_id'));
        $_SESSION['www_flash'] = '工单已关闭。';
        return Response::redirect('/account');
    }

    private function download(Request $request): Response
    {
        $uid = $this->userId();
        if ($uid < 1) {
            return Response::html('请先登录。', 401);
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

    private function downloadLicenseJson(Request $request): Response
    {
        $uid = $this->userId();
        if ($uid < 1) {
            return Response::html('请先登录。', 401);
        }
        $licenseId = $request->query('id');
        $body = $this->hub->licenseDocumentJson($uid, $licenseId);
        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $licenseId) ?: 'license';
        return new Response($body, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="' . $safeName . '.json"',
        ]);
    }

    private function downloadLicensePublicPem(): Response
    {
        $uid = $this->userId();
        if ($uid < 1) {
            return Response::html('请先登录。', 401);
        }
        return new Response($this->hub->publicPem(), 200, [
            'Content-Type' => 'application/x-pem-file; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="license-public.pem"',
        ]);
    }

    private function revokeDownload(Request $request): Response
    {
        $uid = $this->requireUser($request);
        $this->hub->revokeDownloadToken($uid, $request->post('jti'));
        $_SESSION['www_flash'] = '下载链接已撤销。';
        return Response::redirect('/account');
    }

    private function requireUser(Request $request): int
    {
        if (!$this->csrf->assert($request)) {
            throw new \DomainException('表单安全校验失败，请刷新后重试。');
        }
        $uid = $this->userId();
        if ($uid < 1) {
            throw new \DomainException('请先登录。');
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
