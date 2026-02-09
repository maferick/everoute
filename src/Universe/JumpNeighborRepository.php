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
            'SELECT system_id, neighbor_count, neighbor_ids_blob FROM jump_neighbors WHERE %s = :range',
            $rangeColumn
        ));
        $stmt->execute(['range' => $rangeBucket]);
        $neighbors = [];
        while ($row = $stmt->fetch()) {
            $neighborCount = (int) ($row['neighbor_count'] ?? 0);
            $payload = $row['neighbor_ids_blob'] ?? null;
            $decoded = $neighborCount > 0 ? self::decodeNeighborIds(is_string($payload) ? $payload : null) : [];
            $neighbors[(int) $row['system_id']] = $decoded;
        }

        if (count($neighbors) < $expectedSystems) {
            return null;
        }

        return $neighbors;
    }

    /** @return array{neighbor_count:int, neighbor_ids:int[]}|null */
    public function loadSystemNeighbors(int $systemId, int $rangeBucket): ?array
    {
        $pdo = $this->connection->pdo();
        $rangeColumn = $this->resolveRangeColumn($pdo);
        $stmt = $pdo->prepare(sprintf(
            'SELECT neighbor_count, neighbor_ids_blob FROM jump_neighbors WHERE system_id = :system_id AND %s = :range',
            $rangeColumn
        ));
        $stmt->execute(['system_id' => $systemId, 'range' => $rangeBucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $neighborCount = (int) ($row['neighbor_count'] ?? 0);
        $payload = $row['neighbor_ids_blob'] ?? null;
        $decoded = $neighborCount > 0 ? self::decodeNeighborIds(is_string($payload) ? $payload : null) : [];
        return [
            'neighbor_count' => $neighborCount,
            'neighbor_ids' => $decoded,
        ];
    }

    /** @param int[] $neighborIds */
    public static function encodeNeighborIds(array $neighborIds, bool $compress = true): string
    {
        if ($neighborIds === []) {
            return '';
        }
        $packed = pack('N*', ...array_map('intval', $neighborIds));
        if (!$compress) {
            return $packed;
        }
        $compressed = gzcompress($packed);
        return is_string($compressed) ? $compressed : $packed;
    }

    /** @return int[] */
    public static function decodeNeighborIds(?string $payload): array
    {
        if (!is_string($payload) || $payload === '') {
            return [];
        }
        $decompressed = @gzuncompress($payload);
        $binary = $decompressed !== false ? $decompressed : $payload;
        $unpacked = @unpack('N*', $binary);
        if (!is_array($unpacked)) {
            return [];
        }
        return array_values(array_map('intval', $unpacked));
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
