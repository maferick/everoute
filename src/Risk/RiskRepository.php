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

        $stmt = $this->connection->pdo()->query(
            'SELECT system_id, ship_kills_1h, pod_kills_1h, npc_kills_1h, updated_at, risk_updated_at, kills_last_1h, kills_last_24h, pod_kills_last_1h, pod_kills_last_24h, last_updated_at FROM system_risk'
        );
        $rows = $stmt->fetchAll();

        if ($this->cache) {
            $ttl = max(5, $this->heatmapCacheTtlSeconds);
            $this->cache->setJson('heatmap:global', $rows, $ttl);
        }

        return $rows;
    }

    public function getLatestUpdate(?string $provider = null): ?string
    {
        $cacheKey = $provider ? 'risk:latest_update:' . $provider : 'risk:latest_update';
        if ($this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $latest = null;
        if ($provider) {
            $stmt = $this->connection->pdo()->prepare('SELECT last_modified, updated_at FROM risk_meta WHERE provider = :provider');
            $stmt->execute(['provider' => $provider]);
            $row = $stmt->fetch();
            if (is_array($row)) {
                $latest = $row['last_modified'] ?? $row['updated_at'] ?? null;
            }
        }

        if ($latest === null) {
            $stmt = $this->connection->pdo()->query(
                'SELECT MAX(COALESCE(risk_updated_at, last_updated_at, updated_at)) as latest FROM system_risk'
            );
            $row = $stmt->fetch();
            $latest = $row['latest'] ?? null;
        }

        if ($latest && $this->cache) {
            $this->cache->set($cacheKey, (string) $latest, $this->cacheTtlSeconds);
        }

        return $latest;
    }


    public function latestRiskEpochBucket(int $bucketSeconds = 300, ?string $provider = null): int
    {
        $bucketSeconds = max(1, $bucketSeconds);
        $cacheKey = sprintf('risk:epoch_bucket:%d:%s', $bucketSeconds, $provider ?? 'global');
        if ($this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null && ctype_digit((string) $cached)) {
                return (int) $cached;
            }
        }

        $timestamp = null;
        if ($provider) {
            $stmt = $this->connection->pdo()->prepare('SELECT COALESCE(updated_at, checked_at, last_modified) AS latest FROM risk_meta WHERE provider = :provider LIMIT 1');
            $stmt->execute(['provider' => $provider]);
            $row = $stmt->fetch();
            if (is_array($row)) {
                $timestamp = $row['latest'] ?? null;
            }
        }

        if ($timestamp === null) {
            $stmt = $this->connection->pdo()->query('SELECT MAX(COALESCE(risk_updated_at, last_updated_at, updated_at)) AS latest FROM system_risk');
            $row = $stmt?->fetch();
            if (is_array($row)) {
                $timestamp = $row['latest'] ?? null;
            }
        }

        $epoch = is_string($timestamp) ? strtotime($timestamp) : false;
        if (!is_int($epoch) || $epoch <= 0) {
            $epoch = time();
        }

        $bucket = intdiv($epoch, $bucketSeconds);

        if ($this->cache) {
            $ttl = min($this->cacheTtlSeconds, $bucketSeconds);
            $this->cache->set($cacheKey, (string) $bucket, max(1, $ttl));
        }

        return $bucket;
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
