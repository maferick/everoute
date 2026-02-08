<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class SystemRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findByNameOrId(string $value): ?array
    {
        $pdo = $this->connection->pdo();
        if (ctype_digit($value)) {
            $stmt = $pdo->prepare('SELECT * FROM systems WHERE id = :id');
            $stmt->execute(['id' => (int) $value]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM systems WHERE name = :name');
            $stmt->execute(['name' => $value]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listAll(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT id, name, security, system_size_au FROM systems ORDER BY name');
        return $stmt->fetchAll();
    }

    public function neighbors(int $systemId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT to_system_id FROM stargates WHERE from_system_id = :id');
        $stmt->execute(['id' => $systemId]);
        return array_map(static fn ($row) => (int) $row['to_system_id'], $stmt->fetchAll());
    }
}
