<?php

declare(strict_types=1);

namespace Everoute\Universe;

final class SecurityStatus
{
    public static function normalizeSecurityRaw(float $input): float
    {
        if ($input > 1.0) {
            return 1.0;
        }

        if ($input < -1.0) {
            return -1.0;
        }

        return $input;
    }

    public static function secEffectiveFromRaw(float $securityRaw): float
    {
        $normalized = self::normalizeSecurityRaw($securityRaw);
        return floor($normalized * 10.0) / 10.0;
    }

    public static function secBandFromEffective(float $secEffective): string
    {
        if ($secEffective >= 0.5) {
            return 'high';
        }

        if ($secEffective >= 0.0) {
            return 'low';
        }

        return 'null';
    }

    public static function navFromRaw(float $securityRaw): float
    {
        return self::secEffectiveFromRaw($securityRaw);
    }
}
