<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class GateDistanceRepository
{
    private StaticTableResolver $tableResolver;

    public function __construct(private Connection $connection)
    {
        $this->tableResolver = new StaticTableResolver($connection);
    }

    /** @return array<int, int> */
    public function distancesFrom(int $fromId, ?int $maxHops = null): array
    {
        $pdo = $this->connection->pdo();
        $table = $this->tableResolver->readTable(StaticTableResolver::GATE_DISTANCES);
        if ($maxHops === null) {
            $stmt = $pdo->prepare(sprintf('SELECT to_system_id, hops FROM `%s` WHERE from_system_id = :from', $table));
            $stmt->execute(['from' => $fromId]);
        } else {
            $stmt = $pdo->prepare(
                sprintf('SELECT to_system_id, hops FROM `%s` WHERE from_system_id = :from AND hops <= :max', $table)
            );
            $stmt->execute(['from' => $fromId, 'max' => $maxHops]);
        }

        $distances = [];
        while ($row = $stmt->fetch()) {
            $distances[(int) $row['to_system_id']] = (int) $row['hops'];
        }

        return $distances;
    }
}
