<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class JumpNeighborRepository
{
    private ?string $rangeColumn = null;

    public function __construct(private Connection $connection)
    {
    }

    /** @return array<int, int[]>|null */
    public function loadRangeBucket(int $rangeBucket, int $expectedSystems): ?array
    {
        $pdo = $this->connection->pdo();
        $rangeColumn = $this->resolveRangeColumn($pdo);
        $stmt = $pdo->prepare(sprintf(
            'SELECT system_id, neighbor_ids_blob FROM jump_neighbors WHERE %s = :range',
            $rangeColumn
        ));
        $stmt->execute(['range' => $rangeBucket]);
        $neighbors = [];
        while ($row = $stmt->fetch()) {
            $payload = $row['neighbor_ids_blob'];
            $decoded = [];
            if (is_string($payload) && $payload !== '') {
                $decompressed = @gzuncompress($payload);
                $binary = $decompressed !== false ? $decompressed : $payload;
                $unpacked = @unpack('N*', $binary);
                if (is_array($unpacked)) {
                    $decoded = array_values(array_map('intval', $unpacked));
                }
            }
            $neighbors[(int) $row['system_id']] = $decoded;
        }

        if (count($neighbors) < $expectedSystems) {
            return null;
        }

        return $neighbors;
    }

    private function resolveRangeColumn(\PDO $pdo): string
    {
        if ($this->rangeColumn !== null) {
            return $this->rangeColumn;
        }

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info('jump_neighbors')");
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN, 1);
        } else {
            $stmt = $pdo->prepare(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table
                   AND (column_name = :range_ly OR column_name = :range)'
            );
            $stmt->execute([
                'table' => 'jump_neighbors',
                'range_ly' => 'range_ly',
                'range' => 'range',
            ]);
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }
        if (in_array('range_ly', $columns, true)) {
            $this->rangeColumn = 'range_ly';
            return $this->rangeColumn;
        }
        if (in_array('range', $columns, true)) {
            $this->rangeColumn = 'range';
            return $this->rangeColumn;
        }

        throw new \RuntimeException('Missing range column on jump_neighbors. Expected range_ly or range.');
    }
}
