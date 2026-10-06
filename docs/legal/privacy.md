# Privacy (draft)

We collect account identifiers, order records, and technical logs needed to run the shop. Passwords are stored as one-way hashes. Payment card numbers are not stored. OAuth tokens, if enabled by the deployer, stay on the server.

The separate license issuer (commercial modules only) stores hashed tokens, instance ids, entitlements, and short audit rows. It does not store shopper emails or card data. See [docs/license-server-data.md](../license-server-data.md) for fields, retention, and purge.

Retention, cross-border transfer, and processor lists must be completed by the operator and counsel. Users may request export and deletion of account data subject to legal holds (orders, invoices).
