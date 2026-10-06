<?php
/** @var array<string, mixed> $data */
$kind = (string) ($data['kind'] ?? 'terms');
$copy = [
    'terms' => 'Commercial terms, refunds, and license grants are drafts. A lawyer for the copyright holder must approve them before any paid sale. Operators should also read docs/legal/user-terms.md and docs/legal/commercial-agreement.md.',
    'privacy' => 'This demo site stores emails and password hashes locally. There is no third-party analytics pixel in these templates. The commercial license issuer stores hashed tokens and instance ids only; see docs/license-server-data.md. The longer draft is docs/legal/privacy.md.',
    'aup' => 'Operators must not host illegal activity. The acceptable-use draft is docs/legal/acceptable-use.md. Software limits are not a substitute for the operator’s duty.',
    'refunds' => 'Fake purchases on this site are not real charges and create no money to refund. For live shops, physical goods follow the consumer-law cooling-off and quality rules that apply to the merchant. Digital goods may have narrower refunds only where the listing said so before pay and the law allows it. This paragraph does not override mandatory consumer law. See docs/legal/refunds.md.',
    'prohibited' => 'Do not list illegal goods, infringing copies, cheats, unauthorized bots, malware, third-party account trading, traffic or ranking manipulation, or unofficial virtual currency the merchant is not licensed to sell. Keyword holds in the shop do not replace the merchant’s legal duty. See docs/legal/prohibited-goods.md.',
    'disclaimer' => 'Operator and merchant remain responsible for goods, qualifications, taxes, and consumer protection. This software is offered without a warranty of uninterrupted operation. Nothing here excludes liability the law forbids excluding. Illegal listings and fraud are forbidden. See docs/legal/disclaimer.md.',
][$kind] ?? '';
?>
<h1><?= htmlspecialchars((string) $data['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
<p><?= htmlspecialchars($copy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
<p>Not legal advice. Not reviewed by counsel. Copyright holder and license remain undesignated.</p>
