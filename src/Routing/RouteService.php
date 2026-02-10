<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Cache\RedisCache;
use Everoute\Risk\RiskRepository;
use Everoute\Security\Logger;
use Everoute\Universe\StaticMetaRepository;

final class RouteService
{
    public function __construct(
        private NavigationEngine $engine,
        private Logger $logger,
        private ?RedisCache $cache = null,
        private int $routeCacheTtlSeconds = 600,
        private ?StaticMetaRepository $staticMetaRepository = null,
        private ?RiskRepository $riskRepository = null,
        private int $riskEpochBucketSeconds = 300
    ) {
    }

    public function refresh(): void
    {
        $this->engine->refresh();
    }

    public function computeRoutes(array $options): array
    {
        try {
            $cacheKey = $this->routeCacheKey($options);
            if ($this->cache) {
                try {
                    $cached = $this->cache->getJson($cacheKey);
                    if (is_array($cached)) {
                        return $cached;
                    }
                } catch (\Throwable $exception) {
                    $this->logger->warning('Route cache lookup failed', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $payload = $this->engine->compute($options);

            if ($this->cache && !isset($payload['error'])) {
                try {
                    $this->cache->setJson($cacheKey, $payload, $this->routeCacheTtlSeconds);
                } catch (\Throwable $exception) {
                    $this->logger->warning('Route cache write failed', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            return $payload;
        } finally {
            $this->logger->flushMetrics();
        }
    }

    private function routeCacheKey(array $options): string
    {
        $payload = $options;
        $payload['fatigue_model_version'] = (string) ($payload['fatigue_model_version'] ?? JumpFatigueModel::VERSION);
        $avoidLowsec = !empty($payload['avoid_lowsec']);
        $avoidNullsec = !empty($payload['avoid_nullsec']);
        $defaultStrictness = ($avoidLowsec || $avoidNullsec) ? 'strict' : 'soft';
        $payload['avoid_strictness'] = strtolower((string) ($payload['avoid_strictness'] ?? $defaultStrictness));
        if (!in_array($payload['avoid_strictness'], ['soft', 'strict'], true)) {
            $payload['avoid_strictness'] = $defaultStrictness;
        }
        $payload['prefer_npc'] = (bool) ($payload['prefer_npc'] ?? false);
        $payload['hybrid_launch_hops'] = (int) ($payload['hybrid_launch_hops'] ?? 6);
        $payload['hybrid_landing_hops'] = (int) ($payload['hybrid_landing_hops'] ?? 4);
        $payload['hybrid_launch_candidates_limit'] = (int) ($payload['hybrid_launch_candidates_limit'] ?? 50);
        $payload['hybrid_landing_candidates_limit'] = (int) ($payload['hybrid_landing_candidates_limit'] ?? 50);
        if (isset($payload['avoid_systems']) && is_array($payload['avoid_systems'])) {
            sort($payload['avoid_systems']);
        }

        $payload['static_build_id'] = $this->resolveStaticBuildIdentifier();
        $payload['risk_epoch_bucket'] = $this->resolveRiskEpochBucket();

        $payload = $this->normalizeForCacheKey($payload);
        return 'route:' . hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function resolveStaticBuildIdentifier(): string
    {
        if ($this->staticMetaRepository === null) {
            return 'unknown-static-build';
        }

        try {
            return $this->staticMetaRepository->cacheBuildIdentifier();
        } catch (\Throwable $exception) {
            $this->logger->warning('Route cache static build id lookup failed', [
                'error' => $exception->getMessage(),
            ]);
            return 'unknown-static-build';
        }
    }

    private function resolveRiskEpochBucket(): int
    {
        if ($this->riskRepository === null) {
            return intdiv(time(), max(1, $this->riskEpochBucketSeconds));
        }

        try {
            return $this->riskRepository->latestRiskEpochBucket($this->riskEpochBucketSeconds);
        } catch (\Throwable $exception) {
            $this->logger->warning('Route cache risk epoch lookup failed', [
                'error' => $exception->getMessage(),
            ]);
            return intdiv(time(), max(1, $this->riskEpochBucketSeconds));
        }
    }

    private function normalizeForCacheKey(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $normalized = [];
            foreach ($value as $entry) {
                $normalized[] = $this->normalizeForCacheKey($entry);
            }

            $allScalars = true;
            foreach ($normalized as $entry) {
                if (!is_scalar($entry) && $entry !== null) {
                    $allScalars = false;
                    break;
                }
            }
            if ($allScalars) {
                sort($normalized);
            }

            return $normalized;
        }

        ksort($value);
        foreach ($value as $key => $entry) {
            $value[$key] = $this->normalizeForCacheKey($entry);
        }

        return $value;
    }
}
