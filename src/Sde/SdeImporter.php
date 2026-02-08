<?php

declare(strict_types=1);

namespace Everoute\Sde;

use Everoute\DB\Connection;
use PDO;
use RuntimeException;

final class SdeImporter
{
    private const DEFAULT_BATCH_SIZE = 1000;
    private const PROGRESS_EVERY = 5000;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array{systems: string, stargates: string, stations: string} $paths
     */
    public function import(array $paths, int $buildNumber, ?callable $progress = null): void
    {
        $pdo = $this->connection->pdo();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->createStageTables($pdo);

        $this->loadSystems($pdo, $paths['systems'], $now, $progress);
        $this->loadStargates($pdo, $paths['stargates'], $now, $progress);
        $this->loadStations($pdo, $paths['stations'], $now, $progress);

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM stargates');
            $pdo->exec('DELETE FROM stations');
            $pdo->exec('DELETE FROM systems');

            $this->insertFromStage(
                $pdo,
                'systems',
                'systems_stage',
                ['id', 'name', 'security', 'region_id', 'constellation_id', 'x', 'y', 'z', 'system_size_au', 'updated_at']
            );
            $this->insertFromStage(
                $pdo,
                'stargates',
                'stargates_stage',
                ['id', 'from_system_id', 'to_system_id', 'updated_at']
            );
            $this->insertFromStage(
                $pdo,
                'stations',
                'stations_stage',
                ['station_id', 'system_id', 'name', 'is_npc', 'updated_at']
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function createStageTables(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS systems_stage');
        $pdo->exec('DROP TABLE IF EXISTS stargates_stage');
        $pdo->exec('DROP TABLE IF EXISTS stations_stage');

        $pdo->exec('CREATE TABLE systems_stage (
            id BIGINT PRIMARY KEY,
            name VARCHAR(128) NOT NULL,
            security DECIMAL(4,2) NOT NULL,
            region_id BIGINT NULL,
            constellation_id BIGINT NULL,
            x DOUBLE NOT NULL DEFAULT 0,
            y DOUBLE NOT NULL DEFAULT 0,
            z DOUBLE NOT NULL DEFAULT 0,
            system_size_au DOUBLE NOT NULL DEFAULT 1.0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $pdo->exec('CREATE TABLE stargates_stage (
            id BIGINT PRIMARY KEY,
            from_system_id BIGINT NOT NULL,
            to_system_id BIGINT NOT NULL,
            INDEX idx_from_system (from_system_id),
            INDEX idx_to_system (to_system_id),
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $pdo->exec('CREATE TABLE stations_stage (
            station_id BIGINT PRIMARY KEY,
            system_id BIGINT NOT NULL,
            name VARCHAR(255) NOT NULL,
            is_npc TINYINT(1) NOT NULL DEFAULT 1,
            INDEX idx_station_system (system_id),
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function loadSystems(PDO $pdo, string $path, string $now, ?callable $progress): void
    {
        $this->report($progress, '<info>Importing systems...</info>');
        $batch = [];
        $count = 0;
        foreach (SdeJsonlReader::read($path) as $row) {
            $mappedRow = $this->mapSystemRow($row, $now);
            if ($mappedRow['id'] <= 0) {
                continue;
            }
            $batch[] = $mappedRow;
            $count++;
            if (count($batch) >= self::DEFAULT_BATCH_SIZE) {
                $this->insertBatch(
                    $pdo,
                    'systems_stage',
                    ['id', 'name', 'security', 'region_id', 'constellation_id', 'x', 'y', 'z', 'system_size_au', 'updated_at'],
                    $batch
                );
                $batch = [];
                $this->reportEvery($progress, 'Systems', $count);
            }
        }

        if ($batch !== []) {
            $this->insertBatch(
                $pdo,
                'systems_stage',
                ['id', 'name', 'security', 'region_id', 'constellation_id', 'x', 'y', 'z', 'system_size_au', 'updated_at'],
                $batch
            );
        }

        $this->report($progress, sprintf('<info>Systems imported: %d</info>', $count));
    }

    private function loadStargates(PDO $pdo, string $path, string $now, ?callable $progress): void
    {
        $this->report($progress, '<info>Importing stargates...</info>');
        $stargateSystems = [];
        $destinations = [];

        foreach (SdeJsonlReader::read($path) as $row) {
            $id = (int) ($row['stargateID'] ?? $row['id'] ?? 0);
            $fromSystem = (int) ($row['solarSystemID'] ?? $row['solarSystemId'] ?? $row['system_id'] ?? 0);
            if ($id <= 0 || $fromSystem <= 0) {
                continue;
            }
            $stargateSystems[$id] = $fromSystem;
            $destinations[$id] = $row['destination'] ?? null;
        }

        $rows = [];
        $count = 0;
        foreach ($destinations as $id => $destination) {
            $toSystem = null;
            if (is_array($destination)) {
                if (isset($destination['solarSystemID'])) {
                    $toSystem = (int) $destination['solarSystemID'];
                } elseif (isset($destination['solarSystemId'])) {
                    $toSystem = (int) $destination['solarSystemId'];
                } elseif (isset($destination['stargateID'])) {
                    $targetGate = (int) $destination['stargateID'];
                    $toSystem = $stargateSystems[$targetGate] ?? null;
                }
            }

            if ($toSystem === null) {
                continue;
            }

            $rows[] = [
                'id' => (int) $id,
                'from_system_id' => $stargateSystems[$id],
                'to_system_id' => $toSystem,
                'updated_at' => $now,
            ];
            $count++;
            if (count($rows) >= self::DEFAULT_BATCH_SIZE) {
                $this->insertBatch(
                    $pdo,
                    'stargates_stage',
                    ['id', 'from_system_id', 'to_system_id', 'updated_at'],
                    $rows
                );
                $rows = [];
                $this->reportEvery($progress, 'Stargates', $count);
            }
        }

        if ($rows !== []) {
            $this->insertBatch(
                $pdo,
                'stargates_stage',
                ['id', 'from_system_id', 'to_system_id', 'updated_at'],
                $rows
            );
        }

        $this->report($progress, sprintf('<info>Stargates imported: %d</info>', $count));
    }

    private function loadStations(PDO $pdo, string $path, string $now, ?callable $progress): void
    {
        $this->report($progress, '<info>Importing NPC stations...</info>');
        $rows = [];
        $count = 0;
        foreach (SdeJsonlReader::read($path) as $row) {
            $stationId = (int) ($row['stationID'] ?? $row['stationId'] ?? $row['id'] ?? 0);
            $systemId = (int) ($row['solarSystemID'] ?? $row['solarSystemId'] ?? $row['system_id'] ?? 0);
            if ($stationId <= 0 || $systemId <= 0) {
                continue;
            }

            if (array_key_exists('isNPCStation', $row) && !$row['isNPCStation']) {
                continue;
            }

            $rows[] = [
                'station_id' => $stationId,
                'system_id' => $systemId,
                'name' => $this->normalizeName($row['stationName'] ?? $row['name'] ?? null, 'Unknown'),
                'is_npc' => 1,
                'updated_at' => $now,
            ];
            $count++;
            if (count($rows) >= self::DEFAULT_BATCH_SIZE) {
                $this->insertBatch(
                    $pdo,
                    'stations_stage',
                    ['station_id', 'system_id', 'name', 'is_npc', 'updated_at'],
                    $rows
                );
                $rows = [];
                $this->reportEvery($progress, 'Stations', $count);
            }
        }

        if ($rows !== []) {
            $this->insertBatch(
                $pdo,
                'stations_stage',
                ['station_id', 'system_id', 'name', 'is_npc', 'updated_at'],
                $rows
            );
        }

        $this->report($progress, sprintf('<info>Stations imported: %d</info>', $count));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     */
    private function insertBatch(PDO $pdo, string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valuesClause = implode(', ', array_fill(0, count($rows), $placeholders));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $columns),
            $valuesClause
        );

        $values = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Failed inserting batch into %s.', $table), 0, $e);
        }
    }

    /**
     * @param array<int, string> $columns
     */
    private function insertFromStage(PDO $pdo, string $table, string $stageTable, array $columns): void
    {
        $filteredColumns = [];
        foreach ($columns as $column) {
            if ($this->tableHasColumn($pdo, $table, $column)) {
                $filteredColumns[] = $column;
            }
        }

        if ($filteredColumns === []) {
            throw new RuntimeException(sprintf('No matching columns found for %s.', $table));
        }

        $columnList = implode(', ', $filteredColumns);
        $pdo->exec(sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s',
            $table,
            $columnList,
            $columnList,
            $stageTable
        ));
    }

    private function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        return (bool) $stmt->fetchColumn();
    }

    private function report(?callable $progress, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
    }

    private function reportEvery(?callable $progress, string $label, int $count): void
    {
        if ($progress !== null && $count % self::PROGRESS_EVERY === 0) {
            $progress(sprintf('<comment>%s processed: %d</comment>', $label, $count));
        }
    }

    private function mapSystemRow(array $row, string $now): array
    {
        $id = (int) ($row['solarSystemID'] ?? $row['solarSystemId'] ?? $row['id'] ?? 0);
        $name = $this->normalizeName($row['solarSystemName'] ?? $row['name'] ?? null, 'Unknown');
        $security = (float) ($row['security'] ?? $row['securityStatus'] ?? 0.0);
        $regionId = $row['regionID'] ?? $row['regionId'] ?? $row['region_id'] ?? null;
        $constellationId = $row['constellationID'] ?? $row['constellationId'] ?? $row['constellation_id'] ?? null;
        $x = (float) ($row['x'] ?? 0);
        $y = (float) ($row['y'] ?? 0);
        $z = (float) ($row['z'] ?? 0);
        $systemSize = (float) ($row['systemSize'] ?? $row['system_size_au'] ?? 1.0);

        return [
            'id' => $id,
            'name' => $name,
            'security' => $security,
            'region_id' => $regionId !== null ? (int) $regionId : null,
            'constellation_id' => $constellationId !== null ? (int) $constellationId : null,
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'system_size_au' => $systemSize,
            'updated_at' => $now,
        ];
    }

    private function normalizeName(mixed $value, string $fallback): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $english = $value['en'] ?? null;
            if (is_string($english)) {
                return $english;
            }

            foreach ($value as $item) {
                if (is_string($item)) {
                    return $item;
                }
            }
        }

        return $fallback;
    }
}
