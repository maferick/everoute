<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class StaticArtifactShadowManager
{
    public function __construct(private Connection $connection, private StaticTableResolver $resolver)
    {
    }

    public function ensureShadowTables(string $buildId): void
    {
        $pdo = $this->connection->pdo();
        foreach ($this->shadowTableSql($buildId) as $sql) {
            $pdo->exec($sql);
        }
    }

    /** @return array<int, string> */
    private function shadowTableSql(string $buildId): array
    {
        $gate = $this->resolver->writeTable(StaticTableResolver::GATE_DISTANCES, $buildId);
        $jump = $this->resolver->writeTable(StaticTableResolver::JUMP_NEIGHBORS, $buildId);
        $region = $this->resolver->writeTable(StaticTableResolver::REGION_HIERARCHY, $buildId);
        $constellation = $this->resolver->writeTable(StaticTableResolver::CONSTELLATION_HIERARCHY, $buildId);

        return [
            sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
                    from_system_id BIGINT NOT NULL,
                    to_system_id BIGINT NOT NULL,
                    hops SMALLINT UNSIGNED NOT NULL,
                    PRIMARY KEY (from_system_id, to_system_id),
                    INDEX idx_gate_dist_to (to_system_id),
                    INDEX idx_gate_dist_hops (from_system_id, hops)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                $gate
            ),
            sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
                    system_id BIGINT NOT NULL,
                    range_ly SMALLINT UNSIGNED NOT NULL,
                    neighbor_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    neighbor_ids_blob MEDIUMBLOB NOT NULL,
                    encoding_version TINYINT NOT NULL DEFAULT 1,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (system_id, range_ly),
                    INDEX idx_jump_neighbors_range (range_ly)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                $jump
            ),
            sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
                    system_id BIGINT NOT NULL PRIMARY KEY,
                    region_id BIGINT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_region_hierarchy_region (region_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                $region
            ),
            sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
                    system_id BIGINT NOT NULL PRIMARY KEY,
                    constellation_id BIGINT NULL,
                    region_id BIGINT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_constellation_hierarchy_constellation (constellation_id),
                    INDEX idx_constellation_hierarchy_region (region_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                $constellation
            ),
        ];
    }
}
