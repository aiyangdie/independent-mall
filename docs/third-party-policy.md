# Third-party policy

## Allow

- SPDX-identifiable licenses: MIT, BSD-2/3, Apache-2.0, ISC, MPL-2.0, PHP License, Unicode, SIL OFL (fonts) with attribution.
- Pinned versions in `composer.lock` (after first `composer update`).
- Local copies of CSS/JS we author.

## Deny

- Unlicensed snippets from unknown shops
- Obfuscated payloads
- CDNs required for first paint
- Fonts/icons without a recorded license file
- Abandoned packages with no release in years unless vendored and reviewed

## Stage 1 inventory

| Component | License | Use |
|-----------|---------|-----|
| PHP 8.2+ | PHP License | Runtime |
| PHPUnit 11.5.57 | BSD-3-Clause | Tests (see composer.lock) |
| Symfony Mailer / Mime / EventDispatcher 7.4 | MIT | Optional SMTP password reset |
| wechatpay/wechatpay 1.5 | Apache-2.0 | Official WeChat Pay Native / refunds |
| bacon/bacon-qr-code 3.1 | BSD-2-Clause | WeChat Native payment QR SVG |
| SQLite / MySQL | Public domain / GPL (engine, not shipped) | Data |
| First-party CSS/JS | Same as this tree (TBD) | UI |

Composer will record exact versions in `composer.lock`. Additional libraries require an update to this file.

## Fonts and icons

Stage 1 UI uses the system font stack and inline SVG. No icon font is bundled yet.
