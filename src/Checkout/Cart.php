<?php

declare(strict_types=1);

namespace IndependentMall\Checkout;

use DomainException;
use IndependentMall\Catalog\ProductCatalog;
use PDO;

final class Cart
{
    public function __construct(
        private PDO $pdo,
        private ProductCatalog $catalog,
    ) {
    }

    public function forUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM carts WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            return (int) $row['id'];
        }
        $now = gmdate('c');
        $this->pdo->prepare('INSERT INTO carts (user_id, created_at, updated_at) VALUES (:u, :c, :x)')
            ->execute(['u' => $userId, 'c' => $now, 'x' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function add(int $userId, int $variantId, int $qty): void
    {
        if ($qty < 1 || $qty > 99) {
            throw new DomainException('Quantity must be between 1 and 99.');
        }
        $variant = $this->sellable($variantId);
        $available = (int) $variant['on_hand'] - (int) $variant['reserved'];
        if ($qty > $available) {
            throw new DomainException('Not enough stock.');
        }
        $cartId = $this->forUser($userId);
        $stmt = $this->pdo->prepare('SELECT qty FROM cart_items WHERE cart_id = :c AND variant_id = :v');
        $stmt->execute(['c' => $cartId, 'v' => $variantId]);
        $existing = $stmt->fetch();
        if (is_array($existing)) {
            $next = (int) $existing['qty'] + $qty;
            if ($next > $available) {
                throw new DomainException('Not enough stock.');
            }
            $this->pdo->prepare('UPDATE cart_items SET qty = :q WHERE cart_id = :c AND variant_id = :v')
                ->execute(['q' => $next, 'c' => $cartId, 'v' => $variantId]);
        } else {
            $this->pdo->prepare('INSERT INTO cart_items (cart_id, variant_id, qty) VALUES (:c, :v, :q)')
                ->execute(['c' => $cartId, 'v' => $variantId, 'q' => $qty]);
        }
        $this->pdo->prepare('UPDATE carts SET updated_at = :t WHERE id = :id')
            ->execute(['t' => gmdate('c'), 'id' => $cartId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(int $userId): array
    {
        $cartId = $this->forUser($userId);
        $stmt = $this->pdo->prepare(
            'SELECT ci.qty, v.*, p.name AS product_name, p.slug, p.status AS product_status, p.type AS product_type,
                    i.on_hand, i.reserved
             FROM cart_items ci
             JOIN product_variants v ON v.id = ci.variant_id
             JOIN products p ON p.id = v.product_id
             JOIN inventories i ON i.variant_id = v.id
             WHERE ci.cart_id = :c'
        );
        $stmt->execute(['c' => $cartId]);
        $out = [];
        foreach ($stmt as $row) {
            $available = (int) $row['on_hand'] - (int) $row['reserved'];
            $issue = '';
            if ($row['product_status'] !== 'active' || $row['status'] !== 'active') {
                $issue = 'unavailable';
            } elseif ((int) $row['qty'] > $available) {
                $issue = 'insufficient_stock';
            }
            $row['issue'] = $issue;
            $row['line_minor'] = (int) $row['price_minor'] * (int) $row['qty'];
            $out[] = $row;
        }
        return $out;
    }

    public function clear(int $userId): void
    {
        $cartId = $this->forUser($userId);
        $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :c')->execute(['c' => $cartId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sellable(int $variantId): array
    {
        $variant = $this->catalog->variant($variantId);
        if ($variant === null || $variant['status'] !== 'active' || $variant['product_status'] !== 'active') {
            throw new DomainException('This item is not available.');
        }
        return $variant;
    }
}
