<?php

declare(strict_types=1);

namespace IndependentMall\Http;

use IndependentMall\Account\AddressBook;
use IndependentMall\Auth\CustomerAccounts;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Catalog\ProhibitedGoodsPolicy;
use IndependentMall\Checkout\Cart;
use IndependentMall\Checkout\Checkout;
use IndependentMall\Checkout\Refunds;
use IndependentMall\Fulfillment\DigitalFulfillment;
use IndependentMall\Kernel;
use IndependentMall\Mail\NullMailer;
use IndependentMall\Payment\FakePaymentGateway;
use IndependentMall\Security\AuditLogger;
use DomainException;

final class ShopFront
{
    public static function register(Kernel $app): void
    {
        $front = new self($app);
        $front->routes();
    }

    public function __construct(private Kernel $app)
    {
    }

    private function routes(): void
    {
        $r = $this->app->router;
        $r->add('GET', '/register', fn () => $this->form('account/register', 'Create account'));
        $r->add('POST', '/register', function (Request $req) {
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            if ($req->post('accept_terms') !== '1') {
                return $this->form(
                    'account/register',
                    'Create account',
                    'Accept the draft terms and disclaimer before creating an account.',
                    422,
                );
            }
            try {
                $this->accounts()->register($req->post('email'), $req->post('username'), $req->post('password'));
                $this->app->auth->login($req->post('email'), $req->post('password'), $req->ip());
            } catch (\Throwable $e) {
                return $this->form('account/register', 'Create account', $e->getMessage(), 422);
            }
            return Response::redirect('/account');
        });
        $r->add('GET', '/login', function () {
            if ($this->app->auth->user()) {
                return Response::redirect('/account');
            }
            return $this->form('account/login', 'Sign in');
        });
        $r->add('POST', '/login', function (Request $req) {
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            if (!$this->app->auth->login($req->post('identity'), $req->post('password'), $req->ip())) {
                return $this->form('account/login', 'Sign in', 'Sign-in failed.', 401);
            }
            return Response::redirect('/account');
        });
        $r->add('POST', '/logout', function (Request $req) {
            if ($this->app->csrf->assert($req)) {
                $this->app->auth->logout();
            }
            return Response::redirect('/');
        });
        $r->add('GET', '/forgot', fn () => $this->form('account/forgot', 'Reset password'));
        $r->add('POST', '/forgot', function (Request $req) {
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            $this->accounts()->requestReset($req->post('email'));
            return $this->form('account/forgot', 'Reset password', '', 200, 'If the account exists, a reset token was sent.');
        });
        $r->add('GET', '/reset', function (Request $req) {
            return $this->app->page('account/reset', [
                'title' => 'Choose a new password',
                'csrf' => $this->app->csrf->field(),
                'error' => '',
                'token' => $req->query('token'),
                'user' => $this->app->auth->user(),
            ]);
        });
        $r->add('POST', '/reset', function (Request $req) {
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $this->accounts()->completeReset($req->post('token'), $req->post('password'));
            } catch (\Throwable $e) {
                return $this->app->page('account/reset', [
                    'title' => 'Choose a new password',
                    'csrf' => $this->app->csrf->field(),
                    'error' => $e->getMessage(),
                    'token' => $req->post('token'),
                    'user' => $this->app->auth->user(),
                ], 422);
            }
            return Response::redirect('/login');
        });
        $r->add('GET', '/account', function () {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            return $this->app->page('account/home', [
                'title' => 'Account',
                'user' => $this->app->auth->user(),
                'orders' => $this->checkout()->forUser($this->app->auth->user()['id']),
                'csrf' => $this->app->csrf->field(),
            ]);
        });
        $r->add('POST', '/account/password', function (Request $req) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $this->accounts()->changePassword($this->app->auth->user()['id'], $req->post('current'), $req->post('next'));
                $this->app->auth->logout();
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/login');
        });
        $r->add('GET', '/account/addresses', function () {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            return $this->app->page('account/addresses', [
                'title' => 'Addresses',
                'addresses' => $this->addresses()->list($this->app->auth->user()['id']),
                'csrf' => $this->app->csrf->field(),
                'error' => '',
                'user' => $this->app->auth->user(),
            ]);
        });
        $r->add('POST', '/account/addresses', function (Request $req) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $this->addresses()->create($this->app->auth->user()['id'], [
                    'label' => $req->post('label'),
                    'recipient' => $req->post('recipient'),
                    'phone' => $req->post('phone'),
                    'region' => $req->post('region'),
                    'line1' => $req->post('line1'),
                    'line2' => $req->post('line2'),
                    'postal_code' => $req->post('postal_code'),
                    'is_default' => $req->post('is_default') === '1',
                ]);
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/account/addresses');
        });
        $r->add('POST', '#^/account/addresses/(\d+)/delete$#', function (Request $req, array $m) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            $this->addresses()->delete($this->app->auth->user()['id'], (int) $m[1]);
            return Response::redirect('/account/addresses');
        });
        $r->add('POST', '/cart/add', function (Request $req) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $this->cart()->add($this->app->auth->user()['id'], $req->intPost('variant_id'), max(1, $req->intPost('qty', 1)));
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/cart');
        });
        $r->add('GET', '/cart', function () {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            return $this->app->page('store/cart', [
                'title' => 'Cart',
                'lines' => $this->cart()->lines($this->app->auth->user()['id']),
                'csrf' => $this->app->csrf->field(),
                'user' => $this->app->auth->user(),
            ]);
        });
        $r->add('GET', '/checkout', function () {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            $uid = $this->app->auth->user()['id'];
            return $this->app->page('store/checkout', [
                'title' => 'Checkout',
                'lines' => $this->cart()->lines($uid),
                'addresses' => $this->addresses()->list($uid),
                'csrf' => $this->app->csrf->field(),
                'user' => $this->app->auth->user(),
                'error' => '',
            ]);
        });
        $r->add('POST', '/checkout', function (Request $req) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            $uid = $this->app->auth->user()['id'];
            $addr = $req->post('address_id');
            try {
                $order = $this->checkout()->place(
                    $uid,
                    $addr === '' ? null : (int) $addr,
                    $req->post('idempotency_key'),
                );
            } catch (\Throwable $e) {
                return $this->app->page('store/checkout', [
                    'title' => 'Checkout',
                    'lines' => $this->cart()->lines($uid),
                    'addresses' => $this->addresses()->list($uid),
                    'csrf' => $this->app->csrf->field(),
                    'user' => $this->app->auth->user(),
                    'error' => $e->getMessage(),
                ], 422);
            }
            return Response::redirect('/orders/' . $order['id']);
        });
        $r->add('GET', '/orders', function () {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            return $this->app->page('account/orders', [
                'title' => 'Orders',
                'orders' => $this->checkout()->forUser($this->app->auth->user()['id']),
                'user' => $this->app->auth->user(),
                'csrf' => $this->app->csrf->field(),
            ]);
        });
        $r->add('GET', '#^/orders/(\d+)/result$#', function (Request $req, array $m) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            try {
                $order = $this->checkout()->owned($this->app->auth->user()['id'], (int) $m[1]);
            } catch (DomainException) {
                return $this->app->page('store/not-found', ['title' => 'Not found'], 404);
            }
            $flash = $_SESSION['flash_delivery_tokens'] ?? [];
            unset($_SESSION['flash_delivery_tokens']);
            return $this->app->page('store/payment-result', [
                'title' => 'Payment result',
                'order' => $order,
                'payments' => $this->payments()->forOrder((int) $order['id']),
                'flash_tokens' => $flash,
                'user' => $this->app->auth->user(),
            ]);
        });
        $r->add('GET', '#^/orders/(\d+)$#', function (Request $req, array $m) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            try {
                $order = $this->checkout()->owned($this->app->auth->user()['id'], (int) $m[1]);
            } catch (DomainException) {
                return Response::html('Not found', 404);
            }
            $flash = $_SESSION['flash_delivery_tokens'] ?? [];
            unset($_SESSION['flash_delivery_tokens']);
            return $this->app->page('account/order', [
                'title' => 'Order ' . $order['public_id'],
                'order' => $order,
                'items' => $this->checkout()->items((int) $order['id']),
                'deliveries' => $this->digital()->forOrder((int) $order['id']),
                'csrf' => $this->app->csrf->field(),
                'user' => $this->app->auth->user(),
                'flash_tokens' => $flash,
            ]);
        });
        $r->add('POST', '#^/orders/(\d+)/cancel$#', function (Request $req, array $m) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $this->checkout()->cancel($this->app->auth->user()['id'], (int) $m[1]);
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/orders/' . $m[1]);
        });
        $r->add('POST', '#^/orders/(\d+)/pay-fake$#', function (Request $req, array $m) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $order = $this->checkout()->owned($this->app->auth->user()['id'], (int) $m[1]);
                $fields = [
                    'merchant_order' => (string) $order['public_id'],
                    'amount_minor' => (string) $order['payable_minor'],
                    'currency' => (string) $order['currency'],
                    'outcome' => $req->post('outcome', 'succeeded'),
                    'provider_ref' => 'fake-' . $order['public_id'],
                ];
                $fields['signature'] = $this->payments()->sign($fields);
                $tokens = $this->payments()->notify($fields);
                $_SESSION['flash_delivery_tokens'] = $tokens;
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/orders/' . $m[1] . '/result');
        });
        $r->add('POST', '#^/orders/(\d+)/refund$#', function (Request $req, array $m) {
            if ($deny = $this->needUser()) {
                return $deny;
            }
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            try {
                $order = $this->checkout()->owned($this->app->auth->user()['id'], (int) $m[1]);
                $this->refunds()->request(
                    $this->app->auth->user()['id'],
                    (int) $m[1],
                    $req->intPost('amount_minor', (int) $order['payable_minor']),
                    $req->post('reason', 'requested'),
                    $req->post('idempotency_key', 'refund-' . $order['public_id']),
                );
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 422);
            }
            return Response::redirect('/orders/' . $m[1]);
        });
        $r->add('GET', '#^/deliveries/([a-f0-9]{48})$#', function (Request $req, array $m) {
            try {
                $file = $this->digital()->download($m[1]);
            } catch (\Throwable $e) {
                return Response::html($e->getMessage(), 403);
            }
            return new Response($file['body'], 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . basename($file['label']) . '.txt"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
        $r->add('GET', '/admin/orders', function () {
            if ($deny = $this->needAdmin()) {
                return $deny;
            }
            return $this->app->page('admin/orders', [
                'title' => 'Orders',
                'orders' => $this->checkout()->all(),
                'user' => $this->app->auth->user(),
            ]);
        });
        $r->add('GET', '#^/admin/orders/(\d+)$#', function (Request $req, array $m) {
            if ($deny = $this->needAdmin()) {
                return $deny;
            }
            try {
                $order = $this->checkout()->byId((int) $m[1]);
            } catch (DomainException) {
                return $this->app->page('store/not-found', ['title' => 'Not found'], 404);
            }
            return $this->app->page('admin/order', [
                'title' => 'Order ' . $order['public_id'],
                'order' => $order,
                'items' => $this->checkout()->items((int) $order['id']),
                'payments' => $this->payments()->forOrder((int) $order['id']),
                'user' => $this->app->auth->user(),
            ]);
        });
        $r->add('GET', '/admin/payments', function () {
            if ($deny = $this->needAdmin()) {
                return $deny;
            }
            return $this->app->page('admin/payments', [
                'title' => 'Payments',
                'payments' => $this->payments()->all(),
                'user' => $this->app->auth->user(),
            ]);
        });
        $r->add('GET', '/admin/refunds', function () {
            if ($deny = $this->needAdmin()) {
                return $deny;
            }
            return $this->app->page('admin/refunds', [
                'title' => 'Refunds',
                'refunds' => $this->refunds()->all(),
                'csrf' => $this->app->csrf->field(),
                'user' => $this->app->auth->user(),
            ]);
        });
        $r->add('POST', '#^/admin/refunds/(\d+)/(approved|rejected)$#', function (Request $req, array $m) {
            if (!$this->app->auth->can('admin.access')) {
                return Response::redirect('/admin/login');
            }
            if (!$this->app->csrf->assert($req)) {
                return Response::html('Invalid form', 400);
            }
            $this->refunds()->decide((int) $m[1], $m[2], $this->app->auth->user()['id'] ?? null);
            return Response::redirect('/admin/refunds');
        });
    }

    private function form(string $view, string $title, string $error = '', int $status = 200, string $notice = ''): Response
    {
        return $this->app->page($view, [
            'title' => $title,
            'csrf' => $this->app->csrf->field(),
            'error' => $error,
            'notice' => $notice,
            'user' => $this->app->auth->user(),
        ], $status);
    }

    private function needAdmin(): ?Response
    {
        if (!$this->app->auth->can('admin.access') && !$this->app->auth->can('order.manage')) {
            return Response::redirect('/admin/login');
        }
        return null;
    }

    private function needUser(): ?Response
    {
        if ($this->app->auth->user() === null) {
            return Response::redirect('/login');
        }
        return null;
    }

    private function catalog(): ProductCatalog
    {
        return new ProductCatalog($this->app->pdo, new ProhibitedGoodsPolicy($this->app->pdo), new AuditLogger($this->app->pdo));
    }

    private function accounts(): CustomerAccounts
    {
        $hasher = new PasswordHasher();
        return new CustomerAccounts(
            $this->app->pdo,
            $hasher,
            new NullMailer(),
            new AuditLogger($this->app->pdo),
            $this->app->auth,
        );
    }

    private function addresses(): AddressBook
    {
        return new AddressBook($this->app->pdo);
    }

    private function cart(): Cart
    {
        return new Cart($this->app->pdo, $this->catalog());
    }

    private function checkout(): Checkout
    {
        return new Checkout($this->app->pdo, $this->cart(), $this->catalog(), $this->addresses(), new AuditLogger($this->app->pdo));
    }

    private function digital(): DigitalFulfillment
    {
        return new DigitalFulfillment($this->app->pdo, $this->checkout(), new AuditLogger($this->app->pdo), $this->app->config->get('APP_KEY', 'dev'));
    }

    private function payments(): FakePaymentGateway
    {
        return new FakePaymentGateway(
            $this->app->pdo,
            $this->checkout(),
            $this->catalog(),
            $this->digital(),
            new AuditLogger($this->app->pdo),
            $this->app->config->get('APP_KEY', 'dev'),
        );
    }

    private function refunds(): Refunds
    {
        return new Refunds($this->app->pdo, $this->checkout(), new AuditLogger($this->app->pdo));
    }
}
