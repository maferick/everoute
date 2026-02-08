<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class PrecomputeCheckpointRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function getCursor(string $jobKey): ?int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT `cursor` FROM precompute_checkpoints WHERE job_key = :job');
        $stmt->execute(['job' => $jobKey]);
        $cursor = $stmt->fetchColumn();
        if ($cursor === false || $cursor === null) {
            return null;
        }
        return (int) $cursor;
    }

    public function updateCursor(string $jobKey, ?int $cursor): void
    {
        $this->connection->pdo()->prepare(
            'INSERT INTO precompute_checkpoints (job_key, `cursor`, started_at, updated_at)
            VALUES (:job, :cursor, COALESCE((SELECT started_at FROM precompute_checkpoints WHERE job_key = :job_lookup), NOW()), NOW())
            ON DUPLICATE KEY UPDATE `cursor` = VALUES(`cursor`), updated_at = VALUES(updated_at)'
        )->execute(['job' => $jobKey, 'job_lookup' => $jobKey, 'cursor' => $cursor]);
    }

    public function clear(string $jobKey): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM precompute_checkpoints WHERE job_key = :job');
        $stmt->execute(['job' => $jobKey]);
    }
}
