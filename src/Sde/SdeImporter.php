<?php

declare(strict_types=1);

namespace Everoute\Sde;

use Everoute\DB\Connection;
use PDO;
use RuntimeException;

final class SdeImporter
{
    public function __construct(private Connection $connection, private SdeConfig $config)
    {
    }

    /**
     * @param array{systems: string, stargates: string, stations: string} $paths
     */
    public function import(array $paths, int $buildNumber): void
    {
        $pdo = $this->connection->pdo();
        $pdo->setAttribute(PDO::MYSQL_ATTR_LOCAL_INFILE, true);

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $localInfile = $this->localInfileEnabled($pdo);

        $this->createStageTables($pdo);

        $this->loadSystems($pdo, $paths['systems'], $now, $localInfile);
        $this->loadStargates($pdo, $paths['stargates'], $now, $localInfile);
        $this->loadStations($pdo, $paths['stations'], $now, $localInfile);

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM stargates');
            $pdo->exec('DELETE FROM stations');
            $pdo->exec('DELETE FROM systems');

            $pdo->exec('INSERT INTO systems (id, name, security, region_id, constellation_id, x, y, z, system_size_au, updated_at) SELECT id, name, security, region_id, constellation_id, x, y, z, system_size_au, updated_at FROM systems_stage');
            $pdo->exec('INSERT INTO stargates (id, from_system_id, to_system_id, updated_at) SELECT id, from_system_id, to_system_id, updated_at FROM stargates_stage');
            $pdo->exec('INSERT INTO stations (station_id, system_id, name, is_npc, updated_at) SELECT station_id, system_id, name, is_npc, updated_at FROM stations_stage');

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function localInfileEnabled(PDO $pdo): bool
    {
        $stmt = $pdo->query("SHOW VARIABLES LIKE 'local_infile'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $value = strtolower((string) ($row['Value'] ?? ''));
        return in_array($value, ['1', 'on', 'true'], true);
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

    private function loadSystems(PDO $pdo, string $path, string $now, bool $useLocalInfile): void
    {
        if ($useLocalInfile) {
            $csvPath = $this->writeSystemsCsv($path, $now);
            $this->loadCsv($pdo, $csvPath, 'systems_stage', 'id, name, security, region_id, constellation_id, x, y, z, system_size_au, updated_at');
            @unlink($csvPath);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO systems_stage (id, name, security, region_id, constellation_id, x, y, z, system_size_au, updated_at) VALUES (:id, :name, :security, :region_id, :constellation_id, :x, :y, :z, :system_size_au, :updated_at)');
        foreach (SdeJsonlReader::read($path) as $row) {
            $stmt->execute($this->mapSystemRow($row, $now));
        }
    }

    private function loadStargates(PDO $pdo, string $path, string $now, bool $useLocalInfile): void
    {
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
        }

        if ($useLocalInfile) {
            $csvPath = $this->writeCsvRows($rows, ['id', 'from_system_id', 'to_system_id', 'updated_at']);
            $this->loadCsv($pdo, $csvPath, 'stargates_stage', 'id, from_system_id, to_system_id, updated_at');
            @unlink($csvPath);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO stargates_stage (id, from_system_id, to_system_id, updated_at) VALUES (:id, :from_system_id, :to_system_id, :updated_at)');
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    private function loadStations(PDO $pdo, string $path, string $now, bool $useLocalInfile): void
    {
        $rows = [];
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
                'name' => (string) ($row['stationName'] ?? $row['name'] ?? 'Unknown'),
                'is_npc' => 1,
                'updated_at' => $now,
            ];
        }

        if ($useLocalInfile) {
            $csvPath = $this->writeCsvRows($rows, ['station_id', 'system_id', 'name', 'is_npc', 'updated_at']);
            $this->loadCsv($pdo, $csvPath, 'stations_stage', 'station_id, system_id, name, is_npc, updated_at');
            @unlink($csvPath);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO stations_stage (station_id, system_id, name, is_npc, updated_at) VALUES (:station_id, :system_id, :name, :is_npc, :updated_at)');
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    private function loadCsv(PDO $pdo, string $csvPath, string $table, string $columns): void
    {
        $escaped = addslashes($csvPath);
        $sql = sprintf(
            "LOAD DATA LOCAL INFILE '%s' INTO TABLE %s FIELDS TERMINATED BY ',' ENCLOSED BY '\"' ESCAPED BY '\\\\' LINES TERMINATED BY '\n' (%s)",
            $escaped,
            $table,
            $columns
        );
        $pdo->exec($sql);
    }

    private function writeSystemsCsv(string $path, string $now): string
    {
        $csvPath = $this->config->storagePath . '/systems.csv';
        $handle = fopen($csvPath, 'w');
        if ($handle === false) {
            throw new RuntimeException('Unable to write systems csv');
        }

        foreach (SdeJsonlReader::read($path) as $row) {
            $mapped = $this->mapSystemRow($row, $now);
            $this->writeCsvRow($handle, [
                $mapped['id'],
                $mapped['name'],
                $mapped['security'],
                $mapped['region_id'],
                $mapped['constellation_id'],
                $mapped['x'],
                $mapped['y'],
                $mapped['z'],
                $mapped['system_size_au'],
                $mapped['updated_at'],
            ]);
        }

        fclose($handle);
        return $csvPath;
    }

    private function writeCsvRows(array $rows, array $columns): string
    {
        $csvPath = $this->config->storagePath . '/sde-' . bin2hex(random_bytes(4)) . '.csv';
        $handle = fopen($csvPath, 'w');
        if ($handle === false) {
            throw new RuntimeException('Unable to write csv file');
        }

        foreach ($rows as $row) {
            $output = [];
            foreach ($columns as $column) {
                $output[] = $row[$column] ?? null;
            }
            $this->writeCsvRow($handle, $output);
        }

        fclose($handle);
        return $csvPath;
    }

    private function writeCsvRow($handle, array $row): void
    {
        $normalized = array_map(static fn ($value) => $value === null ? '\\N' : $value, $row);
        fputcsv($handle, $normalized, ',', '"', '\\');
    }

    private function mapSystemRow(array $row, string $now): array
    {
        $id = (int) ($row['solarSystemID'] ?? $row['solarSystemId'] ?? $row['id'] ?? 0);
        $name = (string) ($row['solarSystemName'] ?? $row['name'] ?? 'Unknown');
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
}
