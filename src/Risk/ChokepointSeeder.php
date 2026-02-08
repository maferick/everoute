<?php

declare(strict_types=1);

namespace Everoute\Risk;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class ChokepointSeeder
{
    public function seed(PDO $pdo, string $path): array
    {
        if (!file_exists($path)) {
            throw new RuntimeException(sprintf('Chokepoint seed file not found: %s', $path));
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $items = $payload['chokepoints'] ?? $payload;
        if (!is_array($items)) {
            throw new RuntimeException('Invalid chokepoint seed format.');
        }

        $lookupStmt = $pdo->prepare('SELECT id FROM systems WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $hasCategory = $this->columnExists($pdo, 'chokepoints', 'category');
        $hasCreatedAt = $this->columnExists($pdo, 'chokepoints', 'created_at');
        $hasUpdatedAt = $this->columnExists($pdo, 'chokepoints', 'updated_at');

        $columns = ['system_id', 'reason', 'is_active'];
        $values = [':system_id', ':reason', ':is_active'];
        $updates = ['reason = VALUES(reason)', 'is_active = VALUES(is_active)'];

        if ($hasCategory) {
            $columns[] = 'category';
            $values[] = ':category';
            $updates[] = 'category = VALUES(category)';
        }

        if ($hasCreatedAt) {
            $columns[] = 'created_at';
            $values[] = ':created_at';
        }

        if ($hasUpdatedAt) {
            $columns[] = 'updated_at';
            $values[] = ':updated_at';
            $updates[] = 'updated_at = VALUES(updated_at)';
        }

        $upsertStmt = $pdo->prepare(sprintf(
            'INSERT INTO chokepoints (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', $values),
            implode(', ', $updates)
        ));

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $seeded = 0;
        $missing = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $lookupStmt->execute([':name' => $name]);
            $systemId = $lookupStmt->fetchColumn();
            if ($systemId === false) {
                $missing[] = $name;
                continue;
            }

            $params = [
                ':system_id' => (int) $systemId,
                ':reason' => $item['reason'] ?? null,
                ':is_active' => isset($item['is_active']) ? (int) (bool) $item['is_active'] : 1,
            ];

            if ($hasCategory) {
                $params[':category'] = $item['category'] ?? null;
            }

            if ($hasCreatedAt) {
                $params[':created_at'] = $now;
            }

            if ($hasUpdatedAt) {
                $params[':updated_at'] = $now;
            }

            $upsertStmt->execute($params);
            $seeded++;
        }

        return [
            'seeded' => $seeded,
            'missing' => $missing,
        ];
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = :table
             AND COLUMN_NAME = :column'
        );
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
