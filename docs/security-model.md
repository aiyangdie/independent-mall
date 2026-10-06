# Security model

## AuthN / AuthZ

- Passwords: Argon2id (`password_hash` / `password_verify`).
- Sessions: server-side files under `var/sessions`, HTTP-only, SameSite=Lax, configurable Secure.
- CSRF: per-session token on every state-changing form.
- Authorization: RBAC checked in controllers/services, never only in templates.
- Login: lockout after repeated failures per identity (hashed IP stored, not raw logs of passwords).

## Web attacks

- SQL: PDO prepared statements only.
- XSS: default HTML escaping in the view layer.
- CSRF: token + same-site cookie.
- SSRF: Community Core does not fetch admin-supplied URLs in v1.
- Uploads (stage 2): MIME sniff, allow-list extension, size cap, store outside URL-executable paths, random object names.
- Replay: payment idempotency keys; CSRF for browsers.

## Secrets

- `.env` is gitignored.
- Logs must not print passwords, `APP_KEY`, payment secrets, or OAuth app keys.
- Community Edition does not embed a license private key.

## Availability vs licensing

License refresh happens on an admin schedule, not on every storefront hit. If a future commercial module cannot verify a license, Community routes remain available.

## Rate limits

Login, password reset, payment callback, and (later) license activation endpoints are limited.

## Not in this product

Remote arbitrary PHP execution, remote wipe, silent device graphs, irrecoverable encryption of merchant data as a penalty.
