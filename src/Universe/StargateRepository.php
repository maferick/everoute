<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class StargateRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function allEdges(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT from_system_id, to_system_id FROM stargates');
        return $stmt->fetchAll();
    }
}
