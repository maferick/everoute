<?php

declare(strict_types=1);

namespace Everoute\Cache;

use Everoute\Config\Env;
use Everoute\Security\Logger;
use Redis;
use RedisException;

final class RedisCache
{
    private function __construct(private Redis $client, private ?Logger $logger = null)
    {
    }

    public static function fromEnv(?Logger $logger = null): ?self
    {
        if (!Env::bool('REDIS_ENABLED', false)) {
            return null;
        }

        if (!class_exists(Redis::class)) {
            return null;
        }

        $host = Env::get('REDIS_HOST', '');
        if ($host === '') {
            return null;
        }

        $port = Env::int('REDIS_PORT', 6379);

        try {
            $redis = new Redis();
            $redis->connect($host, $port, 1.5);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, 1.5);
        } catch (RedisException $exception) {
            $logger?->warning('Redis connection failed', [
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return new self($redis, $logger);
    }

    public function get(string $key): ?string
    {
        try {
            $value = $this->client->get($key);
        } catch (RedisException $exception) {
            $this->logger?->warning('Redis get failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        if ($value === false) {
            return null;
        }

        return (string) $value;
    }

    public function getJson(string $key): ?array
    {
        $raw = $this->get($key);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        try {
            if ($ttlSeconds > 0) {
                $this->client->setex($key, $ttlSeconds, $value);
            } else {
                $this->client->set($key, $value);
            }
        } catch (RedisException $exception) {
            $this->logger?->warning('Redis set failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function setJson(string $key, array $value, int $ttlSeconds): void
    {
        $this->set($key, json_encode($value, JSON_UNESCAPED_SLASHES), $ttlSeconds);
    }

    public function ping(): bool
    {
        try {
            $pong = $this->client->ping();
        } catch (RedisException $exception) {
            $this->logger?->warning('Redis ping failed', [
                'error' => $exception->getMessage(),
            ]);
            return false;
        }

        return $pong !== false;
    }

    public function stats(): array
    {
        try {
            $info = $this->client->info();
        } catch (RedisException $exception) {
            $this->logger?->warning('Redis info failed', [
                'error' => $exception->getMessage(),
            ]);
            return ['connected' => false];
        }

        $keys = null;
        if (isset($info['db0']) && is_array($info['db0'])) {
            $keys = $info['db0']['keys'] ?? null;
        } elseif (isset($info['db0']) && is_string($info['db0'])) {
            $parts = explode(',', $info['db0']);
            foreach ($parts as $part) {
                if (str_starts_with($part, 'keys=')) {
                    $keys = (int) substr($part, 5);
                }
            }
        }

        return [
            'connected' => true,
            'keys' => $keys,
            'keyspace_hits' => $info['keyspace_hits'] ?? null,
            'keyspace_misses' => $info['keyspace_misses'] ?? null,
            'used_memory' => $info['used_memory_human'] ?? ($info['used_memory'] ?? null),
        ];
    }

    public function delete(string $key): void
    {
        try {
            $this->client->del($key);
        } catch (RedisException $exception) {
            $this->logger?->warning('Redis delete failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
