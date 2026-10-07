<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use IndependentMall\Install\Installer;
use IndependentMall\License\LicenseDocuments;
use PHPUnit\Framework\TestCase;

/**
 * Real HTTP E2E: two PHP built-in servers (OfficialSite + Community).
 * Isolated temp trees; Fake buy only; tears down processes in tearDown.
 */
final class HttpCrossAppLicenseE2ETest extends TestCase
{
    private string $repo;
    private string $communityRoot = '';
    private string $wwwRoot = '';
    private int $communityPort = 0;
    private int $wwwPort = 0;
    private mixed $communityProc = null;
    private mixed $wwwProc = null;
    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            $this->markTestSkipped('curl and proc_open are required for HTTP E2E');
        }
        $this->repo = dirname(__DIR__);
        $this->communityRoot = sys_get_temp_dir() . '/im-http-community-' . bin2hex(random_bytes(4));
        $this->wwwRoot = sys_get_temp_dir() . '/im-http-www-' . bin2hex(random_bytes(4));
        $this->prepareCommunityTree();
        $this->prepareWwwTree();
        $this->communityPort = $this->freePort();
        $this->wwwPort = $this->freePort();
        $this->communityProc = $this->startServer($this->communityPort, $this->communityRoot . '/public');
        $this->wwwProc = $this->startServer($this->wwwPort, $this->wwwRoot . '/public');
        $this->waitFor($this->communityBase() . '/healthz', static fn (int $st, string $body): bool => $st === 200 && str_contains($body, '"edition":"community"'));
        $this->waitFor($this->wwwBase() . '/', static fn (int $st, string $body): bool => $st === 200 && str_contains($body, '独立商城'));
    }

    protected function tearDown(): void
    {
        $this->stopServer($this->communityProc);
        $this->stopServer($this->wwwProc);
        $this->communityProc = null;
        $this->wwwProc = null;
        $this->rmTree($this->communityRoot);
        $this->rmTree($this->wwwRoot);
        foreach ($this->logs as $log) {
            @unlink($log);
        }
    }

    public function testWwwDownloadThenCommunityTrustKeyImportEnableOverHttp(): void
    {
        $assetCookies = [];
        [$assetStatus, , $css] = $this->http('GET', $this->communityBase() . '/assets/app.css', $assetCookies);
        $this->assertSame(200, $assetStatus, 'the actual front controller must serve local CSS');
        $this->assertStringContainsString('.site-header', $css);
        $wwwCookies = [];
        $email = 'http-e2e-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $password = 'correct-horse-battery';

        [$st, , $reg] = $this->http('GET', $this->wwwBase() . '/register', $wwwCookies);
        $this->assertSame(200, $st);
        [$st] = $this->http('POST', $this->wwwBase() . '/register', $wwwCookies, [
            '_csrf' => $this->csrf($reg),
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(302, $st);

        [$st, , $account] = $this->http('GET', $this->wwwBase() . '/account', $wwwCookies);
        $this->assertSame(200, $st);
        [$st] = $this->http('POST', $this->wwwBase() . '/account/buy', $wwwCookies, [
            '_csrf' => $this->csrf($account),
        ]);
        $this->assertSame(302, $st);

        [$st, , $account] = $this->http('GET', $this->wwwBase() . '/account', $wwwCookies);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('演示订单完成', $account);
        $this->assertStringContainsString('/account/license.json?id=', $account);
        $this->assertMatchesRegularExpression('/授权编号 (lic_[a-f0-9]+)/u', $account);
        preg_match('/授权编号 (lic_[a-f0-9]+)/u', $account, $lm);
        $licenseId = $lm[1];

        [$st, $hdr, $licenseJson] = $this->http('GET', $this->wwwBase() . '/account/license.json?id=' . rawurlencode($licenseId), $wwwCookies);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('application/json', $hdr);
        $envelope = json_decode($licenseJson, true);
        $this->assertIsArray($envelope);
        $this->assertSame('sample-notice', $envelope['payload']['product_code'] ?? null);
        $this->assertStringContainsString('attachment', $hdr);

        [$st, , $pem] = $this->http('GET', $this->wwwBase() . '/account/license-public.pem', $wwwCookies);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $pem);
        $fingerprint = LicenseDocuments::fingerprint($pem);

        // Guest cannot download
        $guestCookies = [];
        [$st] = $this->http('GET', $this->wwwBase() . '/account/license.json?id=' . rawurlencode($licenseId), $guestCookies);
        $this->assertSame(401, $st);

        // --- Community over HTTP ---
        $adminCookies = [];
        [$st, , $login] = $this->http('GET', $this->communityBase() . '/admin/login', $adminCookies);
        $this->assertSame(200, $st);
        [$st] = $this->http('POST', $this->communityBase() . '/admin/login', $adminCookies, [
            '_csrf' => $this->csrf($login),
            'identity' => 'ops@http-e2e.invalid',
            'password' => 'correct-horse-battery',
        ]);
        $this->assertSame(302, $st);

        [$st, , $modules] = $this->http('GET', $this->communityBase() . '/admin/modules', $adminCookies);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('trust', strtolower($modules));

        // Import without trust key fails; no license.json yet
        [$st, , $body] = $this->http('POST', $this->communityBase() . '/admin/license', $adminCookies, [
            '_csrf' => $this->csrf($modules),
            'license_json' => $licenseJson,
        ]);
        $this->assertSame(422, $st);
        $this->assertFileDoesNotExist($this->communityRoot . '/var/license.json');

        [$st, , $modules] = $this->http('GET', $this->communityBase() . '/admin/modules', $adminCookies);
        [$st] = $this->http('POST', $this->communityBase() . '/admin/license/trust-key', $adminCookies, [
            '_csrf' => $this->csrf($modules),
            'public_pem' => $pem,
        ]);
        $this->assertSame(302, $st);
        $this->assertFileExists($this->communityRoot . '/var/license-public.pem');

        // Bundled public_pem on license import rejected
        [$st, , $modules] = $this->http('GET', $this->communityBase() . '/admin/modules', $adminCookies);
        $this->assertStringContainsString($fingerprint, $modules);
        [$st, , $reject] = $this->http('POST', $this->communityBase() . '/admin/license', $adminCookies, [
            '_csrf' => $this->csrf($modules),
            'license_json' => $licenseJson,
            'public_pem' => $pem,
        ]);
        $this->assertSame(422, $st);
        $this->assertStringContainsString('许可证导入不能更换公钥', $reject);

        [$st, , $modules] = $this->http('GET', $this->communityBase() . '/admin/modules', $adminCookies);
        [$st] = $this->http('POST', $this->communityBase() . '/admin/license', $adminCookies, [
            '_csrf' => $this->csrf($modules),
            'license_json' => $licenseJson,
        ]);
        $this->assertSame(302, $st);
        $this->assertFileExists($this->communityRoot . '/var/license.json');
        $goodDoc = (string) file_get_contents($this->communityRoot . '/var/license.json');
        $goodPem = (string) file_get_contents($this->communityRoot . '/var/license-public.pem');

        [$st, , $modules] = $this->http('GET', $this->communityBase() . '/admin/modules', $adminCookies);
        [$st] = $this->http('POST', $this->communityBase() . '/admin/modules/sample-notice/migrate', $adminCookies, [
            '_csrf' => $this->csrf($modules),
        ]);
        $this->assertSame(302, $st);
        [$st, , $modules] = $this->http('GET', $this->communityBase() . '/admin/modules', $adminCookies);
        [$st] = $this->http('POST', $this->communityBase() . '/admin/modules/sample-notice/enable', $adminCookies, [
            '_csrf' => $this->csrf($modules),
        ]);
        $this->assertSame(302, $st);

        [$st, , $home] = $this->http('GET', $this->communityBase() . '/', $adminCookies);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('示例商业公告', $home);
        $this->assertStringContainsString('正规商品', $home);

        [$st, , $healthBody] = $this->http('GET', $this->communityBase() . '/healthz', $adminCookies);
        $health = json_decode($healthBody, true);
        $this->assertSame(200, $st);
        $this->assertFalse($health['license_required']);
        $this->assertFalse($health['network_required']);
        $this->assertSame('offline_file', $health['license']['mode'] ?? null);
        $this->assertTrue($health['license']['valid'] ?? false);
        $this->assertSame($fingerprint, $health['license']['trust_fingerprint'] ?? null);

        // Bad import leaves previous license
        $tampered = json_decode($licenseJson, true);
        $tampered['payload']['entitlements'][] = 'forged';
        [$st, , $modules] = $this->http('GET', $this->communityBase() . '/admin/modules', $adminCookies);
        [$st, , $bad] = $this->http('POST', $this->communityBase() . '/admin/license', $adminCookies, [
            '_csrf' => $this->csrf($modules),
            'license_json' => (string) json_encode($tampered),
        ]);
        $this->assertSame(422, $st);
        $this->assertStringContainsString('原有授权未被修改', $bad);
        $this->assertSame($goodDoc, (string) file_get_contents($this->communityRoot . '/var/license.json'));
        $this->assertSame($goodPem, (string) file_get_contents($this->communityRoot . '/var/license-public.pem'));

        // Core commerce still works
        $shopCookies = [];
        [$st, , $regPage] = $this->http('GET', $this->communityBase() . '/register', $shopCookies);
        $this->assertSame(200, $st);
        [$st] = $this->http('POST', $this->communityBase() . '/register', $shopCookies, [
            '_csrf' => $this->csrf($regPage),
            'email' => 'shopper-' . bin2hex(random_bytes(2)) . '@example.invalid',
            'username' => 'shop' . bin2hex(random_bytes(2)),
            'password' => 'correct-horse-1',
            'accept_terms' => '1',
        ]);
        $this->assertSame(302, $st);
        [$st, , $product] = $this->http('GET', $this->communityBase() . '/p/ceramic-mug', $shopCookies);
        $this->assertSame(200, $st);
        $this->assertMatchesRegularExpression('/name="variant_id" value="(\d+)"/', $product);
        preg_match('/name="variant_id" value="(\d+)"/', $product, $vm);
        [$st] = $this->http('POST', $this->communityBase() . '/cart/add', $shopCookies, [
            '_csrf' => $this->csrf($product),
            'variant_id' => $vm[1],
            'qty' => '1',
        ]);
        $this->assertSame(302, $st);
        [$st, , $addrPage] = $this->http('GET', $this->communityBase() . '/account/addresses', $shopCookies);
        [$st] = $this->http('POST', $this->communityBase() . '/account/addresses', $shopCookies, [
            '_csrf' => $this->csrf($addrPage),
            'label' => 'Home',
            'recipient' => 'HTTP E2E',
            'phone' => '555-0100',
            'region' => 'ST',
            'line1' => '1 Http St',
            'line2' => '',
            'postal_code' => '10000',
            'is_default' => '1',
        ]);
        $this->assertSame(302, $st);
        [$st, , $checkout] = $this->http('GET', $this->communityBase() . '/checkout', $shopCookies);
        $this->assertSame(200, $st);
        preg_match('/name="address_id">[\s\S]*?<option value="(\d+)"/', $checkout, $am);
        $this->assertNotEmpty($am);
        [$st, $placeHdr] = $this->http('POST', $this->communityBase() . '/checkout', $shopCookies, [
            '_csrf' => $this->csrf($checkout),
            'address_id' => $am[1],
            'idempotency_key' => 'http-e2e-' . bin2hex(random_bytes(4)),
        ]);
        $this->assertSame(302, $st);
        $this->assertMatchesRegularExpression('#/orders/\d+#', $placeHdr);
        preg_match('#/orders/(\d+)#', $placeHdr, $om);
        [$st, , $orderPage] = $this->http('GET', $this->communityBase() . '/orders/' . $om[1], $shopCookies);
        [$st] = $this->http('POST', $this->communityBase() . '/orders/' . $om[1] . '/pay-fake', $shopCookies, [
            '_csrf' => $this->csrf($orderPage),
            'outcome' => 'succeeded',
        ]);
        $this->assertSame(302, $st);
        [$st, , $paid] = $this->http('GET', $this->communityBase() . '/orders/' . $om[1], $shopCookies);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('已付款', $paid);
        $this->assertStringContainsString('等待商家发货', $paid);
    }

    private function communityBase(): string
    {
        return 'http://127.0.0.1:' . $this->communityPort;
    }

    private function wwwBase(): string
    {
        return 'http://127.0.0.1:' . $this->wwwPort;
    }

    private function prepareCommunityTree(): void
    {
        foreach (['var/data', 'var/sessions', 'var/uploads', 'var/cache', 'public', 'database/migrations', 'modules'] as $rel) {
            mkdir($this->communityRoot . '/' . $rel, 0775, true);
        }
        copy($this->repo . '/.env.example', $this->communityRoot . '/.env.example');
        $this->copyTree($this->repo . '/database/migrations', $this->communityRoot . '/database/migrations');
        $this->copyTree($this->repo . '/templates', $this->communityRoot . '/templates');
        $this->copyTree($this->repo . '/modules/commercial', $this->communityRoot . '/modules/commercial');
        if (is_dir($this->repo . '/public/assets')) {
            $this->copyTree($this->repo . '/public/assets', $this->communityRoot . '/public/assets');
        }
        // Use the shipped entry point, not a test-only router that can hide production bugs.
        copy($this->repo . '/public/index.php', $this->communityRoot . '/public/index.php');
        mkdir($this->communityRoot . '/vendor');
        file_put_contents($this->communityRoot . '/vendor/autoload.php', '<?php require '
            . var_export($this->repo . '/vendor/autoload.php', true) . ';');
        $installer = new Installer($this->communityRoot, $this->communityRoot . '/database/migrations');
        $installer->install([
            'admin_email' => 'ops@http-e2e.invalid',
            'admin_username' => 'ops',
            'admin_password' => 'correct-horse-battery',
            'shop_name' => 'HTTP E2E Shop',
            'app_url' => 'http://127.0.0.1:' . max(1, $this->communityPort),
            'database_url' => 'sqlite:///%ROOT%/var/data/app.sqlite',
            'seed' => '1',
        ]);
        // Fix APP_URL after ports are known — install runs before ports; rewrite in start after ports assigned.
    }

    private function prepareWwwTree(): void
    {
        mkdir($this->wwwRoot . '/public', 0775, true);
        mkdir($this->wwwRoot . '/var', 0775, true);
        $this->copyTree($this->repo . '/apps/www/templates', $this->wwwRoot . '/templates');
        if (is_dir($this->repo . '/apps/www/data')) {
            $this->copyTree($this->repo . '/apps/www/data', $this->wwwRoot . '/data');
        }
        if (is_dir($this->repo . '/apps/www/public')) {
            foreach (glob($this->repo . '/apps/www/public/*') ?: [] as $file) {
                if (is_file($file) && basename($file) !== 'index.php') {
                    copy($file, $this->wwwRoot . '/public/' . basename($file));
                }
            }
            if (is_dir($this->repo . '/apps/www/public/assets')) {
                $this->copyTree($this->repo . '/apps/www/public/assets', $this->wwwRoot . '/public/assets');
            }
        }
        $repoExport = var_export($this->repo, true);
        file_put_contents($this->wwwRoot . '/public/index.php', <<<PHP
<?php
declare(strict_types=1);
\$uri = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
\$file = __DIR__ . \$uri;
if (\$uri !== '/' && is_file(\$file)) {
    return false;
}
\$repo = {$repoExport};
\$root = dirname(__DIR__);
require \$repo . '/vendor/autoload.php';
use IndependentMall\\OfficialSite\\Site;
use IndependentMall\\Http\\Request;
Site::boot(\$root)->handle(Request::fromGlobals())->send();
PHP);
    }

    /** @return resource */
    private function startServer(int $port, string $publicDir)
    {
        // Rewrite community APP_URL once ports are known
        if ($port === $this->communityPort && is_file($this->communityRoot . '/.env')) {
            $env = (string) file_get_contents($this->communityRoot . '/.env');
            $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=http://127.0.0.1:' . $port, $env) ?? $env;
            // Installer writes production; local HTTP reset links need a development APP_ENV.
            $env = preg_replace('/^APP_ENV=.*$/m', 'APP_ENV=testing', $env) ?? $env;
            if (!preg_match('/^PAYMENT_DRIVER=/m', $env)) {
                $env .= "PAYMENT_DRIVER=fake\n";
            }
            file_put_contents($this->communityRoot . '/.env', $env);
        }
        $log = sys_get_temp_dir() . '/im-http-srv-' . $port . '-' . bin2hex(random_bytes(2)) . '.log';
        $this->logs[] = $log;
        $cmd = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            $publicDir,
            $publicDir . '/index.php',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'w'],
            2 => ['file', $log, 'a'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $publicDir, null, ['bypass_shell' => true]);
        if ($proc === false) {
            $this->fail('Could not start PHP built-in server on port ' . $port);
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        return $proc;
    }

    /** @param resource|object|null $proc */
    private function stopServer(mixed $proc): void
    {
        if ($proc === null) {
            return;
        }
        $status = proc_get_status($proc);
        if (!empty($status['running']) && !empty($status['pid'])) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
            } else {
                proc_terminate($proc, 15);
            }
        }
        proc_close($proc);
    }

    /** @param callable(int,string):bool $ok */
    private function waitFor(string $url, callable $ok): void
    {
        $last = '';
        $cookies = [];
        for ($i = 0; $i < 60; $i++) {
            try {
                [$st, , $body] = $this->http('GET', $url, $cookies);
                $last = $st . ' ' . substr($body, 0, 200);
                if ($ok($st, $body)) {
                    return;
                }
            } catch (\Throwable $e) {
                $last = $e->getMessage();
            }
            usleep(100000);
        }
        $this->fail('Server not ready for ' . $url . ' last=' . $last);
    }

    private function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            $this->fail('Could not allocate a free TCP port: ' . $errstr);
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $m)) {
            $this->fail('Could not parse free port from ' . (string) $name);
        }
        return (int) $m[1];
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $fields
     * @return array{0:int,1:string,2:string}
     */
    private function http(string $method, string $url, array &$cookies, array $fields = []): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $headers = [];
        if ($cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', $cookies);
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException(curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerBlock = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        if (preg_match_all('/Set-Cookie:\s*([^;\r\n]+)/i', $headerBlock, $m)) {
            foreach ($m[1] as $pair) {
                $name = explode('=', $pair, 2)[0];
                $cookies[$name] = $pair;
            }
        }
        return [$status, $headerBlock, $body];
    }

    private function csrf(string $html): string
    {
        if (!preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) {
            $this->fail('missing csrf in response HTML');
        }
        return $m[1];
    }

    private function copyTree(string $src, string $dest): void
    {
        if (!is_dir($src)) {
            return;
        }
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $target = $dest . DIRECTORY_SEPARATOR . $it->getSubPathName();
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0775, true);
                }
                copy($file->getPathname(), $target);
            }
        }
    }

    private function rmTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
