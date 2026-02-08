<?php

declare(strict_types=1);

namespace Everoute\Risk;

use Everoute\Cache\RedisCache;
use Everoute\DB\Connection;

final class RiskRepository
{
    public function __construct(
        private Connection $connection,
        private ?RedisCache $cache = null,
        private int $cacheTtlSeconds = 60,
        private int $heatmapCacheTtlSeconds = 30,
        private int $chokepointCacheTtlSeconds = 300
    ) {
    }

    public function getSystemRisk(int $systemId): ?array
    {
        $cacheKey = 'risk:' . $systemId;
        if ($this->cache) {
            $cached = $this->cache->getJson($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM system_risk WHERE system_id = :id');
        $stmt->execute(['id' => $systemId]);
        $row = $stmt->fetch();

        if ($row && $this->cache) {
            $this->cache->setJson($cacheKey, $row, $this->cacheTtlSeconds);
        }

        return $row ?: null;
    }

    public function getHeatmap(): array
    {
        if ($this->cache) {
            $cached = $this->cache->getJson('heatmap:global');
            if (is_array($cached)) {
                return $cached;
            }
        }

        $stmt = $this->connection->pdo()->query('SELECT system_id, kills_last_1h, kills_last_24h, pod_kills_last_1h, pod_kills_last_24h, last_updated_at FROM system_risk');
        $rows = $stmt->fetchAll();

        if ($this->cache) {
            $ttl = max(5, $this->heatmapCacheTtlSeconds);
            $this->cache->setJson('heatmap:global', $rows, $ttl);
        }

        return $rows;
    }

    public function getLatestUpdate(): ?string
    {
        if ($this->cache) {
            $cached = $this->cache->get('risk:latest_update');
            if ($cached !== null) {
                return $cached;
            }
        }

        $stmt = $this->connection->pdo()->query('SELECT MAX(last_updated_at) as latest FROM system_risk');
        $row = $stmt->fetch();
        $latest = $row['latest'] ?? null;

        if ($latest && $this->cache) {
            $this->cache->set('risk:latest_update', (string) $latest, $this->cacheTtlSeconds);
        }

        return $latest;
    }

    public function listChokepoints(): array
    {
        if ($this->cache) {
            $cached = $this->cache->getJson('chokepoints:active');
            if (is_array($cached)) {
                return array_map('intval', $cached);
            }
        }

        $stmt = $this->connection->pdo()->query('SELECT system_id FROM chokepoints WHERE is_active = 1');
        $rows = array_map(static fn ($row) => (int) $row['system_id'], $stmt->fetchAll());

        if ($this->cache) {
            $this->cache->setJson('chokepoints:active', $rows, $this->chokepointCacheTtlSeconds);
        }

        return $rows;
    }

    public function getIngestLastSeen(): ?string
    {
        if (!$this->cache) {
            return null;
        }

        return $this->cache->get('risk:ingest:last_seen');
    }
}
