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
            return '店面已有商品，跳过演示数据。';
        }
        $mug = $catalog->createDraft(1, 'physical', '陶瓷杯', 'ceramic-mug', '炻器马克杯', '日常饮用的素色马克杯。', 'MUG-DEMO', '标准款', 1800, 20, null);
        $catalog->publish($mug['product_id'], null);
        $notes = $catalog->createDraft(2, 'digital', '练习讲义', 'practice-worksheet', '授权数字讲义', '付款后可下载。', 'DIG-DEMO', 'PDF', 600, 99, null);
        $catalog->publish($notes['product_id'], null);
        $catalog->attachDigitalAsset($notes['product_id'], 'worksheet', "Independent Mall 示例讲义\n");
        return '已写入 ceramic-mug 与 practice-worksheet 演示商品。';
    }
}
