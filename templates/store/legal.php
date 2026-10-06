<?php
ob_start();
$kind = (string) ($kind ?? '');
$copy = [
    'refunds' => 'Physical goods follow the cooling-off and quality rules that apply to the merchant. Digital goods may have narrower refunds only where the listing said so before pay and the law allows it. Fake payments in this Community demo create no real money to refund. This page does not override mandatory consumer law. Longer draft: docs/legal/refunds.md.',
    'prohibited' => 'Do not list illegal goods, infringing copies, cheats, unauthorized bots, malware, third-party account trading, traffic or ranking manipulation, or unofficial virtual currency the merchant is not licensed to sell. Keyword holds in the shop do not replace the merchant’s legal duty. Longer draft: docs/legal/prohibited-goods.md.',
    'disclaimer' => 'The merchant remains responsible for goods, qualifications, taxes, and consumer protection. This Community Edition software is offered without a warranty of uninterrupted operation. Nothing here excludes liability the law forbids excluding. Longer draft: docs/legal/disclaimer.md.',
][$kind] ?? 'Unknown legal page.';
?>
<section class="narrow">
    <h1><?= $e($title ?? 'Legal') ?></h1>
    <p class="notice" role="note"><strong>Draft only.</strong> Counsel has not reviewed this text. Copyright holder TBD.</p>
    <p><?= $e($copy) ?></p>
    <p><a href="/">Back to catalog</a> · <a href="/legal/refunds">Refunds</a> · <a href="/legal/prohibited">Prohibited goods</a> · <a href="/legal/disclaimer">Disclaimer</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
