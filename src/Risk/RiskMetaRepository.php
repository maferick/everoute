<?php

declare(strict_types=1);

namespace Everoute\Risk;

use DateTimeInterface;
use Everoute\DB\Connection;

final class RiskMetaRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function getMeta(string $provider): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM risk_meta WHERE provider = :provider');
        $stmt->execute(['provider' => $provider]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsertMeta(
        string $provider,
        ?string $etag,
        ?DateTimeInterface $lastModified,
        ?DateTimeInterface $checkedAt,
        ?DateTimeInterface $updatedAt
    ): void {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO risk_meta (provider, etag, last_modified, checked_at, updated_at)
            VALUES (:provider, :etag, :last_modified, :checked_at, :updated_at)
            ON DUPLICATE KEY UPDATE etag=VALUES(etag), last_modified=VALUES(last_modified), checked_at=VALUES(checked_at), updated_at=VALUES(updated_at)'
        );
        $stmt->execute([
            'provider' => $provider,
            'etag' => $etag,
            'last_modified' => $lastModified?->format('Y-m-d H:i:s'),
            'checked_at' => $checkedAt?->format('Y-m-d H:i:s'),
            'updated_at' => $updatedAt?->format('Y-m-d H:i:s'),
        ]);
    }
}
