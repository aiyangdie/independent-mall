-- Rename demo category labels for Chinese storefront (slugs unchanged).
UPDATE categories SET name = '实体商品' WHERE slug = 'physical-goods';
UPDATE categories SET name = '正版数字商品' WHERE slug = 'digital-goods';
UPDATE categories SET name = '专业服务' WHERE slug = 'services';

-- Best-effort rename of demo product copy if English seed is still present.
UPDATE products SET name = '陶瓷杯', summary = '炻器马克杯', description = '日常饮用的素色马克杯。'
WHERE slug = 'ceramic-mug' AND name = 'Ceramic mug';
UPDATE products SET name = '练习讲义', summary = '授权数字讲义', description = '付款后可下载。'
WHERE slug = 'practice-worksheet' AND name = 'Practice worksheet';
UPDATE product_variants SET title = '标准款' WHERE sku = 'MUG-DEMO' AND title = 'Standard';
UPDATE product_variants SET title = 'PDF' WHERE sku = 'DIG-DEMO' AND title = 'PDF';
