Commercial add-ons are a separate tree. Community Core discovers `modules/*/module.php` and never autoloads this folder from `composer.json`.

The sample plugin (`sample-notice`) only stores a storefront sentence. It needs a locally verified entitlement `storefront_notice`. There is no license private key here and no call to a license server. If this directory is deleted, Community Edition still boots, sells, and passes `vendor/bin/phpunit`.

Lifecycle: install the vendor trust public key once → paste a signed license at `/admin/license` (JSON only; failed imports leave the previous file unchanged) → migrate → enable (denied without a local `storefront_notice` entitlement) → disable → uninstall (drops `commercial_sample_notices`). The Kernel never HTTP-fetches this document on `/`.
