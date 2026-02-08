<?php

declare(strict_types=1);

namespace Everoute\Sde;

use Everoute\DB\Connection;

final class SdeMetaRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function currentBuildNumber(): ?int
    {
        $stmt = $this->connection->pdo()->query('SELECT build_number FROM sde_meta ORDER BY installed_at DESC, build_number DESC LIMIT 1');
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    public function recordInstall(int $buildNumber, string $variant, string $sourceUrl, ?string $notes = null): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO sde_meta (build_number, variant, installed_at, source_url, notes) VALUES (:build_number, :variant, NOW(), :source_url, :notes)'
        );
        $stmt->execute([
            'build_number' => $buildNumber,
            'variant' => $variant,
            'source_url' => $sourceUrl,
            'notes' => $notes,
        ]);
    }
}
