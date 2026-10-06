# Licensing design

## Decision pending

No legal entity name is written into the code as copyright owner. No OSI license is declared as final. `LICENSE.md` states UNLICENSED / TBD.

## If Community Edition is called open source

It must allow use, modification, and sharing, including commercial use, without a paid license and without phoning home. Candidates after the copyright holder is known:

| License | Share-alike | SaaS copyleft | Effect on selling Commercial modules |
|---------|-------------|---------------|--------------------------------------|
| Apache-2.0 | No | No | Easy forks, including closed SaaS. Trademarks still protect the name. |
| AGPL-3.0 | Yes | Yes (network) | Stronger against unaudited hosted clones; Commercial modules should stay separate files with a commercial EULA. |
| Proprietary Community | N/A | N/A | Do not call it open source. |

Do not invent “open source but no commercial use”.

## Commercial Edition

Distributed under a commercial agreement. Activation:

1. Merchant pastes license id + token.
2. Client sends instance id (hostname hash + install id UUID, not a consumer fingerprint).
3. License server returns signed JSON.
4. Client verifies with the **embedded public key**.
5. Refresh in admin cron; 14-day grace on network failure.
6. Expiry disables commercial packages only.

Signature plan: OpenSSL Ed25519. This machine currently lacks `ext-sodium`; implementation must fail closed if Ed25519 is unavailable rather than XOR a homemade MAC and call it a license.

Update packages: version, engine constraints, SQL migrations, file list, SHA-256, signature, notes, rollback. Verify hash and signature before replace. Never `eval` a payload from the license API.

## Trademarks and support

Brand, official support, and hosted SaaS can remain proprietary even if Community source is open.
