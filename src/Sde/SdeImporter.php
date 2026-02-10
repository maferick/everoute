<?php

declare(strict_types=1);

namespace Everoute\Sde;

use Everoute\DB\Connection;
use Everoute\Universe\SecurityStatus;
use PDO;
use RuntimeException;

final class SdeImporter
{
    private const DEFAULT_BATCH_SIZE = 1000;
    private const PROGRESS_EVERY = 5000;
    private const METERS_PER_AU = 149_597_870_700.0;

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

        $this->ensureSchema($pdo);
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
                ['id', 'name', 'security', 'security_raw', 'security_nav', 'region_id', 'constellation_id', 'is_wormhole', 'is_normal_universe', 'has_npc_station', 'npc_station_count', 'x', 'y', 'z', 'system_size_au', 'updated_at']
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

        $this->updateRegionalGates($pdo);
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
            security_raw DECIMAL(4,2) NOT NULL,
            security_nav DECIMAL(4,2) NOT NULL,
            region_id BIGINT NULL,
            constellation_id BIGINT NULL,
            is_wormhole TINYINT(1) NOT NULL DEFAULT 0,
            is_normal_universe TINYINT(1) NOT NULL DEFAULT 0,
            has_npc_station TINYINT(1) NOT NULL DEFAULT 0,
            npc_station_count INT NOT NULL DEFAULT 0,
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
            is_regional_gate TINYINT(1) NOT NULL DEFAULT 0,
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

    private function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS systems (
            id BIGINT PRIMARY KEY,
            name VARCHAR(128) NOT NULL UNIQUE,
            security DECIMAL(4,2) NOT NULL,
            security_raw DECIMAL(4,2) NOT NULL,
            security_nav DECIMAL(4,2) NOT NULL,
            region_id BIGINT NULL,
            constellation_id BIGINT NULL,
            is_wormhole TINYINT(1) NOT NULL DEFAULT 0,
            is_normal_universe TINYINT(1) NOT NULL DEFAULT 0,
            has_npc_station TINYINT(1) NOT NULL DEFAULT 0,
            npc_station_count INT NOT NULL DEFAULT 0,
            x DOUBLE NOT NULL DEFAULT 0,
            y DOUBLE NOT NULL DEFAULT 0,
            z DOUBLE NOT NULL DEFAULT 0,
            system_size_au DOUBLE NOT NULL DEFAULT 1.0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $pdo->exec('CREATE TABLE IF NOT EXISTS stargates (
            id BIGINT PRIMARY KEY,
            from_system_id BIGINT NOT NULL,
            to_system_id BIGINT NOT NULL,
            is_regional_gate TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            INDEX idx_from_system (from_system_id),
            INDEX idx_to_system (to_system_id),
            INDEX idx_regional_gate (is_regional_gate),
            CONSTRAINT fk_stargate_from FOREIGN KEY (from_system_id) REFERENCES systems (id) ON DELETE CASCADE,
            CONSTRAINT fk_stargate_to FOREIGN KEY (to_system_id) REFERENCES systems (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $pdo->exec('CREATE TABLE IF NOT EXISTS stations (
            station_id BIGINT PRIMARY KEY,
            system_id BIGINT NOT NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(128) NOT NULL DEFAULT "npc",
            is_npc TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL,
            INDEX idx_station_system (system_id),
            CONSTRAINT fk_station_system FOREIGN KEY (system_id) REFERENCES systems (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $pdo->exec('CREATE TABLE IF NOT EXISTS sde_meta (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            build_number BIGINT NOT NULL,
            variant VARCHAR(64) NOT NULL,
            installed_at DATETIME NOT NULL,
            source_url VARCHAR(255) NOT NULL,
            notes TEXT NULL,
            INDEX idx_sde_meta_build (build_number),
            INDEX idx_sde_meta_installed (installed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->ensureStationTypeColumn($pdo);
        $this->ensureStationColumn($pdo, 'is_npc', 'TINYINT(1) NOT NULL DEFAULT 1');
        $this->ensureStationColumn($pdo, 'updated_at', 'DATETIME NOT NULL');
        $this->ensureStargateColumn($pdo, 'is_regional_gate', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->ensureStargateIndex($pdo, 'idx_regional_gate', 'is_regional_gate');
        $this->ensureSystemColumn($pdo, 'has_npc_station', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->ensureSystemColumn($pdo, 'npc_station_count', 'INT NOT NULL DEFAULT 0');
        $this->ensureSystemColumn($pdo, 'security_raw', 'DECIMAL(4,2) NOT NULL DEFAULT 0');
        $this->ensureSystemColumn($pdo, 'security_nav', 'DECIMAL(4,2) NOT NULL DEFAULT 0');
        $this->ensureSystemColumn($pdo, 'is_wormhole', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->ensureSystemColumn($pdo, 'is_normal_universe', 'TINYINT(1) NOT NULL DEFAULT 0');
    }

    private function ensureStationTypeColumn(PDO $pdo): void
    {
        if (!$this->tableHasColumn($pdo, 'stations', 'type')) {
            $pdo->exec("ALTER TABLE stations ADD COLUMN type VARCHAR(128) NOT NULL DEFAULT 'npc' AFTER name");
            return;
        }

        $stmt = $pdo->prepare(
            'SELECT is_nullable, column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $stmt->execute(['table' => 'stations', 'column' => 'type']);
        $info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $isNullable = ($info['is_nullable'] ?? 'YES') === 'YES';
        $default = $info['column_default'] ?? null;

        if ($isNullable || $default === null || $default === '') {
            $pdo->exec("ALTER TABLE stations MODIFY COLUMN type VARCHAR(128) NOT NULL DEFAULT 'npc'");
        }
    }

    private function ensureStationColumn(PDO $pdo, string $column, string $definition): void
    {
        if (!$this->tableHasColumn($pdo, 'stations', $column)) {
            $pdo->exec(sprintf('ALTER TABLE stations ADD COLUMN %s %s', $column, $definition));
        }
    }

    private function ensureStargateColumn(PDO $pdo, string $column, string $definition): void
    {
        if (!$this->tableHasColumn($pdo, 'stargates', $column)) {
            $pdo->exec(sprintf('ALTER TABLE stargates ADD COLUMN %s %s', $column, $definition));
        }
    }

    private function ensureStargateIndex(PDO $pdo, string $index, string $column): void
    {
        if ($this->tableHasIndex($pdo, 'stargates', $index)) {
            return;
        }

        $pdo->exec(sprintf('CREATE INDEX %s ON stargates (%s)', $index, $column));
    }

    private function ensureSystemColumn(PDO $pdo, string $column, string $definition): void
    {
        if (!$this->tableHasColumn($pdo, 'systems', $column)) {
            $pdo->exec(sprintf('ALTER TABLE systems ADD COLUMN %s %s', $column, $definition));
        }
    }

    private function loadSystems(PDO $pdo, string $path, string $now, ?callable $progress): void
    {
        $this->report($progress, '<info>Importing systems...</info>');
        $batch = [];
        $count = 0;
        foreach (SdeJsonlReader::read($path) as $row) {
            $row = $this->normalizeRecord($row);
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
                    ['id', 'name', 'security', 'security_raw', 'security_nav', 'region_id', 'constellation_id', 'is_wormhole', 'is_normal_universe', 'has_npc_station', 'npc_station_count', 'x', 'y', 'z', 'system_size_au', 'updated_at'],
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
                ['id', 'name', 'security', 'security_raw', 'security_nav', 'region_id', 'constellation_id', 'is_wormhole', 'is_normal_universe', 'has_npc_station', 'npc_station_count', 'x', 'y', 'z', 'system_size_au', 'updated_at'],
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
            $row = $this->normalizeRecord($row);
            $id = (int) ($row['stargateID'] ?? $row['id'] ?? $row['_key'] ?? $row['key'] ?? 0);
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
            $row = $this->normalizeRecord($row);
            $stationId = (int) ($row['stationID'] ?? $row['stationId'] ?? $row['id'] ?? $row['_key'] ?? $row['key'] ?? 0);
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

    private function tableHasIndex(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $stmt->execute(['table' => $table, 'index' => $index]);

        return (bool) $stmt->fetchColumn();
    }

    private function updateRegionalGates(PDO $pdo): void
    {
        if (!$this->tableHasColumn($pdo, 'stargates', 'is_regional_gate')) {
            return;
        }

        $pdo->exec(
            'UPDATE stargates s
            JOIN systems a ON s.from_system_id = a.id
            JOIN systems b ON s.to_system_id = b.id
            SET s.is_regional_gate = CASE
                WHEN a.region_id IS NOT NULL AND b.region_id IS NOT NULL AND a.region_id <> b.region_id THEN 1
                ELSE 0
            END'
        );
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

    private function normalizeRecord(array $row): array
    {
        $payload = $row;
        foreach (['value', 'data'] as $key) {
            if (isset($row[$key]) && is_array($row[$key])) {
                $payload = $row[$key];
                break;
            }
        }

        foreach (['id', '_key', 'key'] as $idKey) {
            if (!array_key_exists($idKey, $payload) && array_key_exists($idKey, $row)) {
                $payload[$idKey] = $row[$idKey];
            }
        }

        return $payload;
    }

    private function mapSystemRow(array $row, string $now): array
    {
        $id = (int) ($row['solarSystemID'] ?? $row['solarSystemId'] ?? $row['id'] ?? $row['_key'] ?? $row['key'] ?? 0);
        $name = $this->normalizeName($row['solarSystemName'] ?? $row['name'] ?? null, 'Unknown');
        $securityRaw = (float) ($row['security'] ?? $row['securityStatus'] ?? 0.0);
        $securityNav = SecurityStatus::navFromRaw($securityRaw);
        $regionId = $row['regionID'] ?? $row['regionId'] ?? $row['region_id'] ?? null;
        $constellationId = $row['constellationID'] ?? $row['constellationId'] ?? $row['constellation_id'] ?? null;
        $position = is_array($row['position'] ?? null) ? $row['position'] : [];
        $x = (float) ($row['x'] ?? $position['x'] ?? 0);
        $y = (float) ($row['y'] ?? $position['y'] ?? 0);
        $z = (float) ($row['z'] ?? $position['z'] ?? 0);
        $radius = (float) ($row['radius'] ?? 0);
        $systemSize = (float) ($row['systemSize'] ?? $row['system_size_au'] ?? 0);
        if ($systemSize <= 0 && $radius > 0) {
            $systemSize = $radius / self::METERS_PER_AU;
        }
        if ($systemSize <= 0) {
            $systemSize = 1.0;
        }

        $wormholeClassId = $row['wormholeClassID'] ?? $row['wormholeClassId'] ?? $row['wormhole_class_id'] ?? null;
        $isWormholeRegion = $regionId !== null && (int) $regionId >= 11000000 && (int) $regionId < 12000000;
        $isNormalUniverse = $regionId !== null && (int) $regionId >= 10000001 && (int) $regionId <= 10001000;
        $isWormhole = $isWormholeRegion || (is_numeric($wormholeClassId) && (int) $wormholeClassId > 0);

        return [
            'id' => $id,
            'name' => $name,
            'security' => $securityNav,
            'security_raw' => $securityRaw,
            'security_nav' => $securityNav,
            'region_id' => $regionId !== null ? (int) $regionId : null,
            'constellation_id' => $constellationId !== null ? (int) $constellationId : null,
            'is_wormhole' => $isWormhole ? 1 : 0,
            'is_normal_universe' => $isNormalUniverse ? 1 : 0,
            'has_npc_station' => 0,
            'npc_station_count' => 0,
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
