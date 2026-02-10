<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\DB\Connection;

final class RouteJobRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(array $payload, int $ttlMinutes = 15): string
    {
        $id = self::uuid();
        $stmt = $this->connection->pdo()->prepare('INSERT INTO route_jobs (id, created_at, status, request_json, expires_at) VALUES (:id, UTC_TIMESTAMP(), :status, :request_json, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl MINUTE))');
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':status', 'queued');
        $stmt->bindValue(':request_json', json_encode($payload, JSON_THROW_ON_ERROR));
        $stmt->bindValue(':ttl', $ttlMinutes, \PDO::PARAM_INT);
        $stmt->execute();

        return $id;
    }

    public function get(string $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM route_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function cancel(string $id): bool
    {
        $stmt = $this->connection->pdo()->prepare("UPDATE route_jobs SET status='canceled', finished_at=UTC_TIMESTAMP() WHERE id=:id AND status IN ('queued','running')");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function claimNext(string $lockToken): ?array
    {
        $sql = "UPDATE route_jobs
                SET status='running', started_at=UTC_TIMESTAMP(), lock_token=:lock_token
                WHERE id = (
                    SELECT id FROM (
                        SELECT id
                        FROM route_jobs
                        WHERE status='queued' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                        ORDER BY created_at ASC
                        LIMIT 1
                    ) q
                )";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([':lock_token' => $lockToken]);
        if ($stmt->rowCount() === 0) {
            return null;
        }

        $jobStmt = $this->connection->pdo()->prepare('SELECT * FROM route_jobs WHERE lock_token = :lock_token LIMIT 1');
        $jobStmt->execute([':lock_token' => $lockToken]);
        $row = $jobStmt->fetch();

        return $row ?: null;
    }

    public function updateProgress(string $id, array $progress): void
    {
        $stmt = $this->connection->pdo()->prepare('UPDATE route_jobs SET progress_json=:progress WHERE id=:id');
        $stmt->execute([
            ':id' => $id,
            ':progress' => json_encode($progress, JSON_THROW_ON_ERROR),
        ]);
    }

    public function markDone(string $id, array $result): void
    {
        $stmt = $this->connection->pdo()->prepare("UPDATE route_jobs SET status='done', finished_at=UTC_TIMESTAMP(), result_json=:result WHERE id=:id");
        $stmt->execute([
            ':id' => $id,
            ':result' => json_encode($result, JSON_THROW_ON_ERROR),
        ]);
    }

    public function markFailed(string $id, string $error): void
    {
        $stmt = $this->connection->pdo()->prepare("UPDATE route_jobs SET status='failed', finished_at=UTC_TIMESTAMP(), error_text=:error WHERE id=:id");
        $stmt->execute([':id' => $id, ':error' => $error]);
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
