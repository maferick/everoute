<?php

declare(strict_types=1);

namespace Everoute\Universe;

final class SecurityStatus
{
    public static function navFromRaw(float $securityRaw): float
    {
        return floor($securityRaw * 10.0) / 10.0;
    }
}
