<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class JumpMath
{
    public const METERS_PER_LY = 9.4607304725808e15;

    public static function distanceMeters(array $from, array $to): float
    {
        $dx = (float) $from['x'] - (float) $to['x'];
        $dy = (float) $from['y'] - (float) $to['y'];
        $dz = (float) $from['z'] - (float) $to['z'];
        return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
    }

    public static function distanceLy(array $from, array $to): float
    {
        return self::distanceMeters($from, $to) / self::METERS_PER_LY;
    }
}
