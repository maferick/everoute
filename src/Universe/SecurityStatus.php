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

    public static function round1dp(float $value): float
    {
        $rounded = round(self::normalizeSecurityRaw($value), 1, \PHP_ROUND_HALF_UP);

        if (abs($rounded) < 0.05) {
            return 0.0;
        }

        return $rounded;
    }

    public static function secDisplayFromRaw(float $securityRaw): float
    {
        return self::round1dp($securityRaw);
    }

    public static function secBandFromDisplay(float $securityDisplay): string
    {
        if ($securityDisplay >= 0.5) {
            return 'high';
        }

        if ($securityDisplay >= 0.1) {
            return 'low';
        }

        return 'null';
    }

    public static function secEffectiveFromRaw(float $securityRaw): float
    {
        return self::secDisplayFromRaw($securityRaw);
    }

    public static function secBandFromEffective(float $secEffective): string
    {
        return self::secBandFromDisplay($secEffective);
    }

    public static function navFromRaw(float $securityRaw): float
    {
        return self::secDisplayFromRaw($securityRaw);
    }
}
