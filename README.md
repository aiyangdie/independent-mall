# Independent Mall (temporary name)

Community Edition of a general-purpose goods and services shop. It is not a continuation of any previous branded package, and it does not require a license server to start.

PHP **8.2+**. Verified on this machine with PHP 8.5.10.

## Not in this product

Traffic boosting, unofficial virtual currency, account trading, cheats, or bundled third-party AppIDs.

## Quick start (local)

```bat
copy .env.example .env
REM set APP_KEY and ADMIN_PASSWORD in .env
composer install
php bin/console migrate
php bin/console admin:create
php bin/console demo:seed
php -S 127.0.0.1:8090 -t public public/index.php
```

- Storefront: http://127.0.0.1:8090/
- Register / sign in: http://127.0.0.1:8090/register
- Health: http://127.0.0.1:8090/healthz
- Admin: http://127.0.0.1:8090/admin/login

Checkout uses the **Fake** payment adapter only. Real card networks are not connected.

Tests:

```bat
vendor\bin\phpunit
```

## License

Copyright holder and public license are not designated. See `LICENSE.md` and `docs/licensing-design.md`.

Legal drafts in `docs/legal/` are not reviewed by counsel.
