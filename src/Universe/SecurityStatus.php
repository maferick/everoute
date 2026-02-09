<?php

declare(strict_types=1);

namespace Everoute\Universe;

final class SecurityStatus
{
    public static function navFromRaw(float $securityRaw): float
    {
        $scaled = $securityRaw * 10.0;
        if ($securityRaw >= 0.0) {
            return floor($scaled + 0.5) / 10.0;
        }

        return ceil($scaled - 0.5) / 10.0;
    }
}
