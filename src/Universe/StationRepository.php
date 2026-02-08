<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class StationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function hasNpcStation(int $systemId): bool
    {
        $stmt = $this->connection->pdo()->prepare('SELECT COUNT(*) as total FROM stations WHERE system_id = :id AND is_npc = 1');
        $stmt->execute(['id' => $systemId]);
        $row = $stmt->fetch();
        return ((int) ($row['total'] ?? 0)) > 0;
    }

    public function listNpcStationsBySystems(array $systemIds): array
    {
        if (empty($systemIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($systemIds), '?'));
        $stmt = $this->connection->pdo()->prepare("SELECT system_id, station_id, name, type FROM stations WHERE is_npc = 1 AND system_id IN ($placeholders)");
        $stmt->execute($systemIds);
        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $results[(int) $row['system_id']][] = $row;
        }
        return $results;
    }
}
