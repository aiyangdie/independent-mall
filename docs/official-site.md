# Official site (stage 8 slice)

Host: `apps/www` on `http://127.0.0.1:8092/` in local development.

The shop on `:8090` does not request this origin. `LICENSE_SERVER_URL` stays empty on Community installs.

Fake purchase in the customer center writes `fake_completed` via the Fake adapter only. Tokens appear once in the flash message and are stored as SHA-256 hashes.

Download links are HMAC + expiry (10 minutes). File ids are a whitelist; `realpath` must stay under `apps/www/data/files`.

`GET /updates.json` includes an unsigned `latest` channel (auto-install forbidden) and a `signed_release` channel. Clients must verify SHA-256 and Ed25519, copy the install tree first, and restore that copy if the write fails. `php bin/console update:apply` is the local demonstration; it does not fetch URLs.

Customer center lists HMAC downloads, a download audit, and **Check updates** (`GET /account/updates`) which verifies signed metadata and refuses to apply files.

Legal drafts on `/legal/refunds`, `/legal/prohibited`, and `/legal/disclaimer` point at `docs/legal/` and state they are not counsel-reviewed.
