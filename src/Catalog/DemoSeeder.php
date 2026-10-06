<?php

declare(strict_types=1);

namespace IndependentMall\Catalog;

use IndependentMall\Security\AuditLogger;
use PDO;

final class DemoSeeder
{
    public function __construct(private PDO $pdo)
    {
    }

    public function seedIfEmpty(): string
    {
        $catalog = new ProductCatalog($this->pdo, new ProhibitedGoodsPolicy($this->pdo), new AuditLogger($this->pdo));
        if ($catalog->storefrontProducts() !== []) {
            return 'Storefront already has listings.';
        }
        $mug = $catalog->createDraft(1, 'physical', 'Ceramic mug', 'ceramic-mug', 'Stoneware mug', 'A plain mug for beverages.', 'MUG-DEMO', 'Standard', 1800, 20, null);
        $catalog->publish($mug['product_id'], null);
        $notes = $catalog->createDraft(2, 'digital', 'Practice worksheet', 'practice-worksheet', 'Licensed worksheet', 'Download after payment.', 'DIG-DEMO', 'PDF', 600, 99, null);
        $catalog->publish($notes['product_id'], null);
        $catalog->attachDigitalAsset($notes['product_id'], 'worksheet', "Independent Mall sample worksheet\n");
        return 'Seeded ceramic-mug and practice-worksheet.';
    }
}
