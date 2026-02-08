<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class JumpNeighborRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<int, array<int, float>>|null */
    public function loadRangeBucket(int $rangeBucket, int $expectedSystems): ?array
    {
        $pdo = $this->connection->pdo();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM jump_neighbors WHERE range_bucket = :range');
        $countStmt->execute(['range' => $rangeBucket]);
        $count = (int) $countStmt->fetchColumn();
        if ($count < $expectedSystems) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT system_id, neighbor_ids_blob FROM jump_neighbors WHERE range_bucket = :range');
        $stmt->execute(['range' => $rangeBucket]);
        $neighbors = [];
        while ($row = $stmt->fetch()) {
            $payload = $row['neighbor_ids_blob'];
            $decoded = null;
            if (is_string($payload)) {
                $decompressed = @gzuncompress($payload);
                if ($decompressed !== false) {
                    $decoded = json_decode($decompressed, true);
                } else {
                    $decoded = json_decode($payload, true);
                }
            }
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $neighbors[(int) $row['system_id']] = $decoded;
        }

        return $neighbors;
    }
}
