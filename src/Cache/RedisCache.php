<?php

declare(strict_types=1);

namespace Everoute\Cache;

use Everoute\Config\Env;
use Redis;
use RedisException;

final class RedisCache
{
    private function __construct(private Redis $client)
    {
    }

    public static function fromEnv(): ?self
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
        } catch (RedisException) {
            return null;
        }

        return new self($redis);
    }

    public function get(string $key): ?string
    {
        try {
            $value = $this->client->get($key);
        } catch (RedisException) {
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
        } catch (RedisException) {
        }
    }

    public function setJson(string $key, array $value, int $ttlSeconds): void
    {
        $this->set($key, json_encode($value, JSON_UNESCAPED_SLASHES), $ttlSeconds);
    }

    public function delete(string $key): void
    {
        try {
            $this->client->del($key);
        } catch (RedisException) {
        }
    }
}
