<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class StargateRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function allEdges(bool $includeWormholes = false): array
    {
        if ($includeWormholes) {
            $stmt = $this->connection->pdo()->query('SELECT from_system_id, to_system_id, is_regional_gate FROM stargates');
            return $stmt->fetchAll();
        }

        $stmt = $this->connection->pdo()->query(
            'SELECT s.from_system_id, s.to_system_id, s.is_regional_gate
             FROM stargates s
             JOIN systems a ON a.id = s.from_system_id
             JOIN systems b ON b.id = s.to_system_id
             WHERE a.is_normal_universe = 1
               AND b.is_normal_universe = 1
               AND a.is_wormhole = 0
               AND b.is_wormhole = 0'
        );
        return $stmt->fetchAll();
    }
}
