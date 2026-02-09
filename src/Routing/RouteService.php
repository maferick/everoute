<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Cache\RedisCache;
use Everoute\Security\Logger;

final class RouteService
{
    public function __construct(
        private NavigationEngine $engine,
        private Logger $logger,
        private ?RedisCache $cache = null,
        private int $routeCacheTtlSeconds = 600
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
        if (isset($payload['avoid_systems']) && is_array($payload['avoid_systems'])) {
            sort($payload['avoid_systems']);
        }
        ksort($payload);
        return 'route:' . hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
