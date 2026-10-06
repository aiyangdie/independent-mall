Official marketing and customer-center app. It is not the shop. Community shops do not call this host on boot.

```bat
php -S 127.0.0.1:8092 -t public public/index.php
```

Demo keys for Fake purchases live in `var/` (gitignored). They are not the production license-server keys.

Unsigned `/updates.json` must never trigger automatic file replacement.
