<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class StaticMetaRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function ensureInitialized(?int $activeSdeBuildNumber = null, int $precomputeVersion = 1): void
    {
        $pdo = $this->connection->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS static_meta (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                active_sde_build_number BIGINT NULL,
                precompute_version INT NOT NULL,
                built_at DATETIME NOT NULL,
                active_build_id VARCHAR(64) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $stmt = $pdo->prepare(
            'INSERT INTO static_meta (id, active_sde_build_number, precompute_version, built_at, active_build_id)
             VALUES (1, :active_sde_build_number, :precompute_version, NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                active_sde_build_number = COALESCE(:active_sde_build_number_update, active_sde_build_number),
                precompute_version = :precompute_version_update'
        );
        $stmt->execute([
            'active_sde_build_number' => $activeSdeBuildNumber,
            'precompute_version' => $precomputeVersion,
            'active_sde_build_number_update' => $activeSdeBuildNumber,
            'precompute_version_update' => $precomputeVersion,
        ]);
    }

    public function getActiveBuildId(): ?string
    {
        $stmt = $this->connection->pdo()->query('SELECT active_build_id FROM static_meta WHERE id = 1');
        $value = $stmt?->fetchColumn();
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return $value;
    }

    public function setActiveBuildId(string $buildId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE static_meta
             SET active_build_id = :build_id,
                 built_at = NOW()
             WHERE id = 1'
        );
        $stmt->execute(['build_id' => $buildId]);
    }

    public function latestSdeBuildNumber(): ?int
    {
        $stmt = $this->connection->pdo()->query('SELECT build_number FROM sde_meta ORDER BY installed_at DESC, id DESC LIMIT 1');
        $value = $stmt?->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        return (int) $value;
    }

    public function cacheBuildIdentifier(int $defaultPrecomputeVersion = 1): string
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT active_build_id, active_sde_build_number, precompute_version FROM static_meta WHERE id = 1 LIMIT 1'
        );
        $row = $stmt?->fetch();

        $activeBuildId = is_array($row) ? trim((string) ($row['active_build_id'] ?? '')) : '';
        if ($activeBuildId !== '') {
            return $activeBuildId;
        }

        $activeSdeBuild = null;
        if (is_array($row) && isset($row['active_sde_build_number'])) {
            $activeSdeBuild = (int) $row['active_sde_build_number'];
            if ($activeSdeBuild <= 0) {
                $activeSdeBuild = null;
            }
        }

        $precomputeVersion = $defaultPrecomputeVersion;
        if (is_array($row) && isset($row['precompute_version'])) {
            $precomputeVersion = max(1, (int) $row['precompute_version']);
        }

        $sdeBuild = $activeSdeBuild ?? $this->latestSdeBuildNumber() ?? 0;
        return sprintf('sde:%d-precompute:%d', $sdeBuild, $precomputeVersion);
    }
}
