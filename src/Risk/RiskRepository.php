<?php

declare(strict_types=1);

namespace Everoute\Risk;

use Everoute\DB\Connection;

final class RiskRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function getSystemRisk(int $systemId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM system_risk WHERE system_id = :id');
        $stmt->execute(['id' => $systemId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getHeatmap(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT system_id, kills_last_1h, kills_last_24h, pod_kills_last_1h, pod_kills_last_24h, last_updated_at FROM system_risk');
        return $stmt->fetchAll();
    }

    public function getLatestUpdate(): ?string
    {
        $stmt = $this->connection->pdo()->query('SELECT MAX(last_updated_at) as latest FROM system_risk');
        $row = $stmt->fetch();
        return $row['latest'] ?? null;
    }

    public function listChokepoints(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT system_id FROM chokepoints WHERE is_active = 1');
        return array_map(static fn ($row) => (int) $row['system_id'], $stmt->fetchAll());
    }
}
