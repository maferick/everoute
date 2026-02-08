<?php

declare(strict_types=1);

namespace Everoute\Risk;

use DateTimeImmutable;
use DateTimeZone;
use Everoute\Cache\RedisCache;
use Everoute\DB\Connection;

final class RiskAggregator
{
    public function __construct(
        private Connection $connection,
        private ?RedisCache $cache,
        private int $cacheTtlSeconds
    ) {
    }

    public function updateSystem(int $systemId): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff1h = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $cutoff24h = $now->modify('-24 hours')->format('Y-m-d H:i:s');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                SUM(CASE WHEN happened_at >= :cutoff1h THEN 1 ELSE 0 END) as kills_last_1h,
                COUNT(*) as kills_last_24h,
                SUM(CASE WHEN happened_at >= :cutoff1h AND is_pod_kill = 1 THEN 1 ELSE 0 END) as pod_kills_last_1h,
                SUM(CASE WHEN is_pod_kill = 1 THEN 1 ELSE 0 END) as pod_kills_last_24h
            FROM kill_events
            WHERE system_id = :system_id AND happened_at >= :cutoff24h'
        );
        $stmt->execute([
            'system_id' => $systemId,
            'cutoff1h' => $cutoff1h,
            'cutoff24h' => $cutoff24h,
        ]);
        $row = $stmt->fetch();

        $payload = [
            'system_id' => $systemId,
            'kills_last_1h' => (int) ($row['kills_last_1h'] ?? 0),
            'kills_last_24h' => (int) ($row['kills_last_24h'] ?? 0),
            'pod_kills_last_1h' => (int) ($row['pod_kills_last_1h'] ?? 0),
            'pod_kills_last_24h' => (int) ($row['pod_kills_last_24h'] ?? 0),
            'last_updated_at' => $now->format('Y-m-d H:i:s'),
        ];

        $upsert = $this->connection->pdo()->prepare(
            'INSERT INTO system_risk (system_id, kills_last_1h, kills_last_24h, pod_kills_last_1h, pod_kills_last_24h, last_updated_at)
            VALUES (:system_id, :k1h, :k24h, :p1h, :p24h, :updated)
            ON DUPLICATE KEY UPDATE
                kills_last_1h=VALUES(kills_last_1h),
                kills_last_24h=VALUES(kills_last_24h),
                pod_kills_last_1h=VALUES(pod_kills_last_1h),
                pod_kills_last_24h=VALUES(pod_kills_last_24h),
                last_updated_at=VALUES(last_updated_at)'
        );
        $upsert->execute([
            'system_id' => $payload['system_id'],
            'k1h' => $payload['kills_last_1h'],
            'k24h' => $payload['kills_last_24h'],
            'p1h' => $payload['pod_kills_last_1h'],
            'p24h' => $payload['pod_kills_last_24h'],
            'updated' => $payload['last_updated_at'],
        ]);

        $this->refreshCache($payload);

        return $payload;
    }

    public function reconcileRecent(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff1h = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $cutoff24h = $now->modify('-24 hours')->format('Y-m-d H:i:s');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                system_id,
                SUM(CASE WHEN happened_at >= :cutoff1h THEN 1 ELSE 0 END) as kills_last_1h,
                COUNT(*) as kills_last_24h,
                SUM(CASE WHEN happened_at >= :cutoff1h AND is_pod_kill = 1 THEN 1 ELSE 0 END) as pod_kills_last_1h,
                SUM(CASE WHEN is_pod_kill = 1 THEN 1 ELSE 0 END) as pod_kills_last_24h
            FROM kill_events
            WHERE happened_at >= :cutoff24h
            GROUP BY system_id'
        );
        $stmt->execute([
            'cutoff1h' => $cutoff1h,
            'cutoff24h' => $cutoff24h,
        ]);
        $rows = $stmt->fetchAll();

        $upsert = $this->connection->pdo()->prepare(
            'INSERT INTO system_risk (system_id, kills_last_1h, kills_last_24h, pod_kills_last_1h, pod_kills_last_24h, last_updated_at)
            VALUES (:system_id, :k1h, :k24h, :p1h, :p24h, :updated)
            ON DUPLICATE KEY UPDATE
                kills_last_1h=VALUES(kills_last_1h),
                kills_last_24h=VALUES(kills_last_24h),
                pod_kills_last_1h=VALUES(pod_kills_last_1h),
                pod_kills_last_24h=VALUES(pod_kills_last_24h),
                last_updated_at=VALUES(last_updated_at)'
        );

        foreach ($rows as $row) {
            $payload = [
                'system_id' => (int) $row['system_id'],
                'kills_last_1h' => (int) ($row['kills_last_1h'] ?? 0),
                'kills_last_24h' => (int) ($row['kills_last_24h'] ?? 0),
                'pod_kills_last_1h' => (int) ($row['pod_kills_last_1h'] ?? 0),
                'pod_kills_last_24h' => (int) ($row['pod_kills_last_24h'] ?? 0),
                'last_updated_at' => $now->format('Y-m-d H:i:s'),
            ];
            $upsert->execute([
                'system_id' => $payload['system_id'],
                'k1h' => $payload['kills_last_1h'],
                'k24h' => $payload['kills_last_24h'],
                'p1h' => $payload['pod_kills_last_1h'],
                'p24h' => $payload['pod_kills_last_24h'],
                'updated' => $payload['last_updated_at'],
            ]);

            $this->refreshCache($payload);
        }

        $reset = $this->connection->pdo()->prepare(
            'UPDATE system_risk
                SET kills_last_1h = 0,
                    kills_last_24h = 0,
                    pod_kills_last_1h = 0,
                    pod_kills_last_24h = 0,
                    last_updated_at = :updated
            WHERE system_id NOT IN (
                SELECT DISTINCT system_id FROM kill_events WHERE happened_at >= :cutoff24h
            )
            AND (kills_last_1h > 0 OR kills_last_24h > 0 OR pod_kills_last_1h > 0 OR pod_kills_last_24h > 0)'
        );
        $reset->execute([
            'updated' => $now->format('Y-m-d H:i:s'),
            'cutoff24h' => $cutoff24h,
        ]);

        if ($this->cache) {
            $this->cache->delete('heatmap:global');
            $this->cache->set('risk:latest_update', $now->format('Y-m-d H:i:s'), $this->cacheTtlSeconds);
        }
    }

    private function refreshCache(array $payload): void
    {
        if (!$this->cache) {
            return;
        }

        $this->cache->setJson('risk:' . $payload['system_id'], $payload, $this->cacheTtlSeconds);
        $this->cache->delete('heatmap:global');
        $this->cache->set('risk:latest_update', $payload['last_updated_at'], $this->cacheTtlSeconds);
    }
}
