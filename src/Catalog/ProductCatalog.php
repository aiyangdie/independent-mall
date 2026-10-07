<?php

declare(strict_types=1);

namespace IndependentMall\Catalog;

use DomainException;
use IndependentMall\Security\AuditLogger;
use PDO;

final class ProductCatalog
{
    public function __construct(
        private PDO $pdo,
        private ProhibitedGoodsPolicy $policy,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM categories' . ($activeOnly ? " WHERE status = 'active'" : '') . ' ORDER BY sort, id';
        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function storefrontProducts(): array
    {
        $sql = $this->storefrontSelect() . " ORDER BY p.id DESC";
        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return $this->storefrontProducts();
        }
        $like = '%' . $query . '%';
        $stmt = $this->pdo->prepare(
            $this->storefrontSelect() . " AND (p.name LIKE :q OR p.summary LIKE :q2)
             ORDER BY p.id DESC"
        );
        $stmt->execute(['q' => $like, 'q2' => $like]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function categoryBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM categories WHERE slug = :s AND status = 'active'");
        $stmt->execute(['s' => $slug]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function productsInCategory(int $categoryId): array
    {
        $stmt = $this->pdo->prepare(
            $this->storefrontSelect() . " AND p.category_id = :c ORDER BY p.id DESC"
        );
        $stmt->execute(['c' => $categoryId]);
        return $stmt->fetchAll() ?: [];
    }

    private function storefrontSelect(): string
    {
        return "SELECT p.*, c.name AS category_name,
                       (SELECT MIN(v.price_minor) FROM product_variants v WHERE v.product_id = p.id AND v.status = 'active') AS price_minor,
                       (SELECT v.currency FROM product_variants v WHERE v.product_id = p.id AND v.status = 'active' ORDER BY v.price_minor, v.id LIMIT 1) AS currency,
                       (SELECT i.storage_key FROM product_images i WHERE i.product_id = p.id ORDER BY i.id LIMIT 1) AS image_key
                FROM products p JOIN categories c ON c.id = p.category_id
                WHERE p.status = 'active' AND c.status = 'active'";
    }

    public function createCategory(string $name, string $slug): int
    {
        $slug = strtolower(trim($slug));
        if ($name === '' || !preg_match('/^[a-z0-9-]{2,64}$/', $slug)) {
            throw new DomainException('Category name and slug are required.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (parent_id, slug, name, sort, status) VALUES (NULL, :s, :n, 99, :st)'
        );
        $stmt->execute(['s' => $slug, 'n' => $name, 'st' => 'active']);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function prohibitedRules(): array
    {
        return $this->pdo->query('SELECT * FROM prohibited_product_rules ORDER BY id')->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function productBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, c.name AS category_name FROM products p
             JOIN categories c ON c.id = p.category_id WHERE p.slug = :s"
        );
        $stmt->execute(['s' => $slug]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function variant(int $variantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.*, i.on_hand, i.reserved, p.status AS product_status, p.type AS product_type, p.name AS product_name
             FROM product_variants v
             JOIN inventories i ON i.variant_id = v.id
             JOIN products p ON p.id = v.product_id
             WHERE v.id = :id'
        );
        $stmt->execute(['id' => $variantId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function attachDigitalAsset(int $productId, string $label, string $body): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO digital_assets (product_id, label, body) VALUES (:p, :l, :b)');
        $stmt->execute(['p' => $productId, 'l' => $label, 'b' => $body]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function product(int $id): array|null
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function variants(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.*, i.on_hand, i.reserved FROM product_variants v
             JOIN inventories i ON i.variant_id = v.id
             WHERE v.product_id = :p ORDER BY v.id'
        );
        $stmt->execute(['p' => $productId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function skuDirectory(): array
    {
        return $this->pdo->query(
            'SELECT v.id, v.sku, v.title, v.price_minor, v.currency, v.status,
                    p.name AS product_name, p.slug, p.status AS product_status,
                    i.on_hand, i.reserved
             FROM product_variants v
             JOIN products p ON p.id = v.product_id
             JOIN inventories i ON i.variant_id = v.id
             ORDER BY v.id DESC'
        )->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function images(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_images WHERE product_id = :p ORDER BY id');
        $stmt->execute(['p' => $productId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminProducts(): array
    {
        return $this->pdo->query(
            'SELECT p.*, c.name AS category_name FROM products p
             JOIN categories c ON c.id = p.category_id ORDER BY p.id DESC'
        )->fetchAll() ?: [];
    }

    /**
     * @return array{product_id:int,variant_id:int}
     */
    public function createDraft(
        int $categoryId,
        string $type,
        string $name,
        string $slug,
        string $summary,
        string $description,
        string $sku,
        string $variantTitle,
        int $priceMinor,
        int $onHand,
        ?int $actorId,
    ): array {
        if (!in_array($type, ['physical', 'digital', 'service'], true)) {
            throw new DomainException('Unknown product type.');
        }
        if ($priceMinor < 0 || $onHand < 0) {
            throw new DomainException('Price and stock must be non-negative integers.');
        }
        $hits = $this->policy->matches($name, $summary, $description);
        $status = $this->policy->decideStatus($hits, 'draft');
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO products (category_id, type, name, slug, summary, description, status, review_note, created_at, updated_at)
                 VALUES (:c, :t, :n, :s, :u, :d, :st, :r, :a, :b)'
            );
            $stmt->execute([
                'c' => $categoryId,
                't' => $type,
                'n' => $name,
                's' => $slug,
                'u' => $summary,
                'd' => $description,
                'st' => $status,
                'r' => $hits === [] ? '' : 'Held by policy: ' . $hits[0]['pattern'],
                'a' => $now,
                'b' => $now,
            ]);
            $productId = (int) $this->pdo->lastInsertId();
            $v = $this->pdo->prepare(
                'INSERT INTO product_variants (product_id, sku, title, price_minor, currency, status)
                 VALUES (:p, :sku, :t, :pr, :cur, :st)'
            );
            $v->execute([
                'p' => $productId,
                'sku' => $sku,
                't' => $variantTitle,
                'pr' => $priceMinor,
                'cur' => 'CNY',
                'st' => 'active',
            ]);
            $variantId = (int) $this->pdo->lastInsertId();
            $inv = $this->pdo->prepare('INSERT INTO inventories (variant_id, on_hand, reserved) VALUES (:v, 0, 0)');
            $inv->execute(['v' => $variantId]);
            if ($onHand > 0) {
                $this->applyMovement($variantId, $onHand, 0, 'receive', 'create:' . $productId);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit->record($actorId, 'product.create', 'product', (string) $productId, ['status' => $status]);
        return ['product_id' => $productId, 'variant_id' => $variantId];
    }

    public function submitForReview(int $productId, ?int $actorId): void
    {
        $product = $this->requireProduct($productId);
        $hits = $this->policy->matches(
            (string) $product['name'],
            (string) $product['summary'],
            (string) $product['description'],
        );
        $this->policy->decideStatus($hits, 'in_review');
        $this->setStatus($productId, 'in_review', 'Submitted for review', $actorId);
    }

    public function publish(int $productId, ?int $actorId): void
    {
        $product = $this->requireProduct($productId);
        $hits = $this->policy->matches(
            (string) $product['name'],
            (string) $product['summary'],
            (string) $product['description'],
        );
        foreach ($hits as $hit) {
            throw new DomainException('Cannot publish: ' . $hit['pattern']);
        }
        if ($this->variants($productId) === []) {
            throw new DomainException('Cannot publish without a variant.');
        }
        $this->setStatus($productId, 'active', 'Published', $actorId);
    }

    public function reject(int $productId, string $note, ?int $actorId): void
    {
        $this->setStatus($productId, 'rejected', $note, $actorId);
    }

    public function archive(int $productId, ?int $actorId): void
    {
        $this->setStatus($productId, 'archived', 'Archived', $actorId);
    }

    public function report(int $productId, string $reason, string $detail): void
    {
        $this->requireProduct($productId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_reports (product_id, reason, detail, status, created_at)
             VALUES (:p, :r, :d, :s, :t)'
        );
        $stmt->execute([
            'p' => $productId,
            'r' => $reason,
            'd' => $detail,
            's' => 'open',
            't' => gmdate('c'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireProduct(int $id): array
    {
        $row = $this->product($id);
        if ($row === null) {
            throw new DomainException('Product not found.');
        }
        return $row;
    }

    private function setStatus(int $productId, string $status, string $note, ?int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET status = :s, review_note = :n, updated_at = :u WHERE id = :id'
        );
        $stmt->execute(['s' => $status, 'n' => $note, 'u' => gmdate('c'), 'id' => $productId]);
        $this->audit->record($actorId, 'product.status', 'product', (string) $productId, ['status' => $status]);
    }

    public function applyMovement(int $variantId, int $deltaOnHand, int $deltaReserved, string $reason, string $reference): void
    {
        $stmt = $this->pdo->prepare('SELECT on_hand, reserved FROM inventories WHERE variant_id = :v');
        $stmt->execute(['v' => $variantId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new DomainException('Unknown variant.');
        }
        $on = (int) $row['on_hand'] + $deltaOnHand;
        $res = (int) $row['reserved'] + $deltaReserved;
        if ($on < 0 || $res < 0 || $res > $on) {
            throw new DomainException('Inventory would become invalid.');
        }
        $exists = $this->pdo->prepare('SELECT id FROM inventory_movements WHERE reason = :reason AND reference = :ref');
        $exists->execute(['reason' => $reason, 'ref' => $reference]);
        if ($exists->fetch()) {
            return;
        }
        $ins = $this->pdo->prepare(
            'INSERT INTO inventory_movements (variant_id, delta_on_hand, delta_reserved, reason, reference, created_at)
             VALUES (:v, :o, :r, :reason, :ref, :t)'
        );
        $ins->execute([
            'v' => $variantId,
            'o' => $deltaOnHand,
            'r' => $deltaReserved,
            'reason' => $reason,
            'ref' => $reference,
            't' => gmdate('c'),
        ]);
        $upd = $this->pdo->prepare('UPDATE inventories SET on_hand = :o, reserved = :r WHERE variant_id = :v');
        $upd->execute(['o' => $on, 'r' => $res, 'v' => $variantId]);
    }
}
