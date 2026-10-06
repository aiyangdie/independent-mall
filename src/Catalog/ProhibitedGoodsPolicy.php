<?php

declare(strict_types=1);

namespace IndependentMall\Catalog;

use DomainException;
use PDO;

final class ProhibitedGoodsPolicy
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array{pattern:string,action:string}>
     */
    public function matches(string $name, string $summary, string $description): array
    {
        $stmt = $this->pdo->query(
            'SELECT pattern, match_field, action FROM prohibited_product_rules WHERE enabled = 1'
        );
        $hits = [];
        foreach ($stmt ?: [] as $row) {
            $hay = match ($row['match_field']) {
                'name' => $name,
                'summary' => $summary,
                'description' => $description,
                default => $name . "\n" . $summary . "\n" . $description,
            };
            if ($row['pattern'] !== '' && mb_stripos($hay, (string) $row['pattern']) !== false) {
                $hits[] = ['pattern' => (string) $row['pattern'], 'action' => (string) $row['action']];
            }
        }
        return $hits;
    }

    /**
     * @param list<array{pattern:string,action:string}> $hits
     */
    public function decideStatus(array $hits, string $intended): string
    {
        foreach ($hits as $hit) {
            if ($hit['action'] === 'block_publish') {
                throw new DomainException('Listing blocked by prohibited-goods rule: ' . $hit['pattern']);
            }
        }
        if ($hits !== []) {
            return 'in_review';
        }
        return $intended;
    }
}
