<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class JumpNeighborValidator
{
    private ?string $rangeColumn = null;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param int[] $ranges
     * @return array{systems_checked:int, violations_found:int, violations:array<int, array<int, array{range:int, prev:int, count:int}>>}
     */
    public function validateMonotonicity(array $ranges): array
    {
        $ranges = $this->normalizeRanges($ranges);
        if ($ranges === []) {
            return ['systems_checked' => 0, 'violations_found' => 0, 'violations' => []];
        }

        $pdo = $this->connection->pdo();
        $rangeColumn = $this->resolveRangeColumn($pdo);
        $placeholders = implode(', ', array_fill(0, count($ranges), '?'));
        $stmt = $pdo->prepare(sprintf(
            'SELECT system_id, %s AS range_ly, neighbor_count
             FROM jump_neighbors
             WHERE %s IN (%s)
             ORDER BY system_id, %s',
            $rangeColumn,
            $rangeColumn,
            $placeholders,
            $rangeColumn
        ));
        $stmt->execute($ranges);

        $violations = [];
        $currentSystem = null;
        $prevCount = null;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $systemId = (int) $row['system_id'];
            $rangeLy = (int) $row['range_ly'];
            $count = (int) $row['neighbor_count'];
            if ($currentSystem !== $systemId) {
                $currentSystem = $systemId;
                $prevCount = null;
            }
            if ($prevCount !== null && $count < $prevCount) {
                $violations[$systemId][] = [
                    'range' => $rangeLy,
                    'prev' => $prevCount,
                    'count' => $count,
                ];
            }
            $prevCount = $count;
        }

        $systemsChecked = $this->countSystems();

        return [
            'systems_checked' => $systemsChecked,
            'violations_found' => count($violations),
            'violations' => $violations,
        ];
    }

    /**
     * @param int[] $ranges
     * @param int[]|null $expectedSystemIds
     * @return array{systems_checked:int, missing_rows_found:int, missing:array<int, int>}
     */
    public function validateCompleteness(array $ranges, ?array $expectedSystemIds = null): array
    {
        $ranges = $this->normalizeRanges($ranges);
        $expectedCount = count($ranges);
        $systems = $expectedSystemIds === null
            ? $this->loadSystemIds()
            : $this->normalizeSystemIds($expectedSystemIds);
        if ($systems === [] || $expectedCount === 0) {
            return ['systems_checked' => 0, 'missing_rows_found' => 0, 'missing' => []];
        }

        $pdo = $this->connection->pdo();
        $rangeColumn = $this->resolveRangeColumn($pdo);
        $placeholders = implode(', ', array_fill(0, count($ranges), '?'));
        $stmt = $pdo->prepare(sprintf(
            'SELECT system_id, COUNT(*) AS row_count
             FROM jump_neighbors
             WHERE %s IN (%s)
             GROUP BY system_id',
            $rangeColumn,
            $placeholders
        ));
        $stmt->execute($ranges);

        $counts = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $counts[(int) $row['system_id']] = (int) $row['row_count'];
        }

        $missing = [];
        foreach ($systems as $systemId) {
            $rowCount = $counts[$systemId] ?? 0;
            if ($rowCount < $expectedCount) {
                $missing[$systemId] = $expectedCount - $rowCount;
            }
        }

        return [
            'systems_checked' => count($systems),
            'missing_rows_found' => count($missing),
            'missing' => $missing,
        ];
    }

    /** @param int[] $ranges */
    private function normalizeRanges(array $ranges): array
    {
        $ranges = array_values(array_unique(array_map('intval', $ranges)));
        sort($ranges);
        return $ranges;
    }

    /** @return int[] */
    private function loadSystemIds(): array
    {
        $pdo = $this->connection->pdo();
        $stmt = $pdo->query('SELECT id FROM systems');
        $ids = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) : [];
        return array_map('intval', $ids);
    }

    private function countSystems(): int
    {
        $pdo = $this->connection->pdo();
        $stmt = $pdo->query('SELECT COUNT(*) FROM systems');
        $count = $stmt ? $stmt->fetchColumn() : 0;
        return (int) $count;
    }

    /** @param int[] $systemIds
     *  @return int[]
     */
    private function normalizeSystemIds(array $systemIds): array
    {
        $systemIds = array_values(array_unique(array_map('intval', $systemIds)));
        sort($systemIds);
        return $systemIds;
    }

    private function resolveRangeColumn(\PDO $pdo): string
    {
        if ($this->rangeColumn !== null) {
            return $this->rangeColumn;
        }

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info('jump_neighbors')");
            $columns = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN, 1) : [];
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
