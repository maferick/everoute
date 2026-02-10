<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class StaticTableResolver
{
    public const GATE_DISTANCES = 'gate_distances';
    public const JUMP_NEIGHBORS = 'jump_neighbors';
    public const REGION_HIERARCHY = 'region_hierarchy';
    public const CONSTELLATION_HIERARCHY = 'constellation_hierarchy';

    public function __construct(private Connection $connection, private ?StaticMetaRepository $metaRepository = null)
    {
        $this->metaRepository ??= new StaticMetaRepository($connection);
    }

    public function readTable(string $baseTable): string
    {
        $activeBuildId = $this->metaRepository->getActiveBuildId();
        if ($activeBuildId === null) {
            return $baseTable;
        }

        $candidate = $this->buildTableName($baseTable, $activeBuildId);
        if ($this->tableExists($candidate)) {
            return $candidate;
        }

        return $baseTable;
    }

    public function writeTable(string $baseTable, ?string $buildId = null): string
    {
        if ($buildId === null || trim($buildId) === '') {
            return $baseTable;
        }
        return $this->buildTableName($baseTable, $buildId);
    }

    public function buildTableName(string $baseTable, string $buildId): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $buildId) ?? '';
        if ($sanitized === '') {
            throw new \InvalidArgumentException('Invalid build id.');
        }
        return sprintf('%s__%s', $baseTable, $sanitized);
    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $tableName]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
