<?php

declare(strict_types=1);

namespace Everoute\Routing;

if (!class_exists(\Everoute\Universe\SecurityStatus::class)) {
    require_once __DIR__ . '/../Universe/SecurityStatus.php';
}

use Everoute\Universe\SecurityStatus;

final class SecurityNav
{
    public const HIGH_SEC_MIN = 0.5;
    public const LOW_SEC_MIN = 0.1;

    public static function value(array $system): float
    {
        if (array_key_exists('security_nav', $system) && $system['security_nav'] !== null) {
            return (float) $system['security_nav'];
        }

        if (array_key_exists('security_raw', $system) && $system['security_raw'] !== null) {
            return SecurityStatus::navFromRaw((float) $system['security_raw']);
        }

        return SecurityStatus::navFromRaw((float) ($system['security'] ?? 0.0));
    }

    public static function isHighsec(array $system): bool
    {
        return self::value($system) >= self::HIGH_SEC_MIN;
    }

    public static function isLowsec(array $system): bool
    {
        $security = self::value($system);
        return $security >= self::LOW_SEC_MIN && $security < self::HIGH_SEC_MIN;
    }

    public static function isNullsec(array $system): bool
    {
        return self::value($system) < self::LOW_SEC_MIN;
    }

    public static function spaceType(array $system): string
    {
        $security = self::value($system);
        if ($security >= self::HIGH_SEC_MIN) {
            return 'highsec';
        }
        if ($security >= self::LOW_SEC_MIN) {
            return 'lowsec';
        }

        return 'nullsec';
    }
}

