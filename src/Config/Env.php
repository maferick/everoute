<?php

declare(strict_types=1);

namespace Everoute\Config;

use Symfony\Component\Dotenv\Dotenv;

final class Env
{
    private static bool $loaded = false;

    public static function load(string $rootDir): void
    {
        if (self::$loaded) {
            return;
        }

        $dotenv = new Dotenv();
        $envFile = $rootDir . '/.env';
        if (file_exists($envFile)) {
            $dotenv->usePutenv(true)->load($envFile);
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return (int) $value;
    }
}
