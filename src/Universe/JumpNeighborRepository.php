<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\Config\Env;
use Everoute\DB\Connection;
use Everoute\Security\Logger;

final class JumpNeighborRepository
{
    private bool $debugMode;

    public function __construct(private Connection $connection, private Logger $logger)
    {
        $this->debugMode = Env::bool('APP_DEBUG', false);
    }

    /** @return array<int, int[]>|null */
    public function loadRangeBucket(int $rangeBucket, int $expectedSystems): ?array
    {
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare(sprintf(
            'SELECT system_id, range_ly, neighbor_count, neighbor_ids_blob FROM jump_neighbors WHERE range_ly = :range_ly'
        ));
        $stmt->execute(['range_ly' => $rangeBucket]);
        $neighbors = [];
        while ($row = $stmt->fetch()) {
            $neighborCount = (int) ($row['neighbor_count'] ?? 0);
            $payload = $row['neighbor_ids_blob'] ?? null;
            $decoded = is_string($payload) ? JumpNeighborCodec::decodeNeighborIds($payload) : [];
            $systemId = (int) $row['system_id'];
            $rangeLy = (int) ($row['range_ly'] ?? $rangeBucket);
            $this->assertNeighborCount($systemId, $rangeLy, $neighborCount, $decoded);
            $neighbors[$systemId] = $decoded;
        }

        if (count($neighbors) < $expectedSystems) {
            return null;
        }

        return $neighbors;
    }

    /** @return array{neighbor_count:int, neighbor_ids:int[]}|null */
    public function loadSystemNeighbors(int $systemId, int $rangeBucket): ?array
    {
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare(sprintf(
            'SELECT system_id, range_ly, neighbor_count, neighbor_ids_blob FROM jump_neighbors WHERE system_id = :system_id AND range_ly = :range_ly'
        ));
        $stmt->execute(['system_id' => $systemId, 'range_ly' => $rangeBucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $neighborCount = (int) ($row['neighbor_count'] ?? 0);
        $payload = $row['neighbor_ids_blob'] ?? null;
        $decoded = is_string($payload) ? JumpNeighborCodec::decodeNeighborIds($payload) : [];
        $rangeLy = (int) ($row['range_ly'] ?? $rangeBucket);
        $this->assertNeighborCount($systemId, $rangeLy, $neighborCount, $decoded);
        return [
            'neighbor_count' => $neighborCount,
            'neighbor_ids' => $decoded,
        ];
    }

    /** @param int[] $decoded */
    private function assertNeighborCount(int $systemId, int $rangeLy, int $neighborCount, array $decoded): void
    {
        $decodedCount = count($decoded);
        if ($decodedCount === $neighborCount) {
            return;
        }
        $this->logger->error('Jump neighbor decode count mismatch', [
            'system_id' => $systemId,
            'range_ly' => $rangeLy,
            'neighbor_count' => $neighborCount,
            'decoded_count' => $decodedCount,
        ]);
        if ($this->debugMode) {
            throw new \RuntimeException(sprintf(
                'Jump neighbor decode count mismatch for system %d at %d LY (db=%d decoded=%d).',
                $systemId,
                $rangeLy,
                $neighborCount,
                $decodedCount
            ));
        }
    }
}
