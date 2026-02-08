<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class GateDistanceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<int, int> */
    public function distancesFrom(int $fromId, ?int $maxHops = null): array
    {
        $pdo = $this->connection->pdo();
        if ($maxHops === null) {
            $stmt = $pdo->prepare('SELECT to_system_id, hops FROM gate_distances WHERE from_system_id = :from');
            $stmt->execute(['from' => $fromId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT to_system_id, hops FROM gate_distances WHERE from_system_id = :from AND hops <= :max'
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
