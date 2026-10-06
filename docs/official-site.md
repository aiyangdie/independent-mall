# Official site (stage 8 slice)

Host: `apps/www` on `http://127.0.0.1:8092/` in local development.

The shop on `:8090` does not request this origin. `LICENSE_SERVER_URL` stays empty on Community installs.

Fake purchase in the customer center writes `fake_completed` via the Fake adapter only. Tokens appear once in the flash message and are stored as SHA-256 hashes.

Download links are HMAC + expiry (10 minutes). File ids are a whitelist; `realpath` must stay under `apps/www/data/files`.

`GET /updates.json` publishes SHA-256 and an empty signature for the development snapshot. Clients must refuse auto-install when `signed` is false.
