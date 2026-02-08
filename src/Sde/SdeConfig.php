<?php

declare(strict_types=1);

namespace Everoute\Sde;

use Everoute\Config\Env;

final class SdeConfig
{
    public function __construct(
        public readonly string $storagePath,
        public readonly string $variant,
        public readonly string $baseUrl,
        public readonly int $timeout,
        public readonly int $retries,
        public readonly string $userAgent,
    ) {
    }

    public static function fromEnv(): self
    {
        $storagePath = Env::get('SDE_STORAGE_PATH', '/var/lib/everoute/sde');
        $variant = Env::get('SDE_VARIANT', 'jsonl');
        $baseUrl = Env::get('SDE_BASE_URL', 'https://developers.eveonline.com/static-data/tranquility');
        $timeout = Env::int('SDE_TIMEOUT', 60);
        $retries = Env::int('SDE_RETRIES', 3);
        $userAgent = sprintf('EverouteSde/1.0 (+%s)', Env::get('APP_BASE_URL', 'https://routing.lonewolves.online'));

        return new self($storagePath, $variant, $baseUrl, $timeout, $retries, $userAgent);
    }
}
