<?php

declare(strict_types=1);

namespace IndependentMall\Account;

use DomainException;
use PDO;

final class AddressBook
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_addresses WHERE user_id = :u ORDER BY is_default DESC, id');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function owned(int $userId, int $addressId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_addresses WHERE id = :id AND user_id = :u');
        $stmt->execute(['id' => $addressId, 'u' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new DomainException('Address not found.');
        }
        return $row;
    }

    /**
     * @param array{label:string,recipient:string,phone:string,region:string,line1:string,line2?:string,postal_code?:string,is_default?:bool} $data
     */
    public function create(int $userId, array $data): int
    {
        $now = gmdate('c');
        if (trim($data['recipient']) === '' || trim($data['line1']) === '') {
            throw new DomainException('Recipient and address line are required.');
        }
        if (!empty($data['is_default'])) {
            $this->pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = :u')->execute(['u' => $userId]);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_addresses (user_id, label, recipient, phone, region, line1, line2, postal_code, is_default, created_at, updated_at)
             VALUES (:u, :l, :r, :p, :g, :a, :b, :z, :d, :c, :x)'
        );
        $stmt->execute([
            'u' => $userId,
            'l' => $data['label'] !== '' ? $data['label'] : 'Home',
            'r' => $data['recipient'],
            'p' => $data['phone'] ?? '',
            'g' => $data['region'] ?? '',
            'a' => $data['line1'],
            'b' => $data['line2'] ?? '',
            'z' => $data['postal_code'] ?? '',
            'd' => !empty($data['is_default']) ? 1 : 0,
            'c' => $now,
            'x' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $userId, int $addressId): void
    {
        $this->owned($userId, $addressId);
        $this->pdo->prepare('DELETE FROM user_addresses WHERE id = :id AND user_id = :u')
            ->execute(['id' => $addressId, 'u' => $userId]);
    }

    public function snapshotJson(array $row): string
    {
        return json_encode([
            'label' => $row['label'],
            'recipient' => $row['recipient'],
            'phone' => $row['phone'],
            'region' => $row['region'],
            'line1' => $row['line1'],
            'line2' => $row['line2'],
            'postal_code' => $row['postal_code'],
        ], JSON_UNESCAPED_UNICODE);
    }
}
