<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class MovementRules
{
    public const HIGH_SEC_MIN = 0.5;

    public function isCapitalRestricted(array $options): bool
    {
        $shipClass = (string) ($options['ship_class'] ?? '');
        $jumpShipType = (string) ($options['jump_ship_type'] ?? '');

        if (in_array($shipClass, ['capital', 'super', 'titan'], true)) {
            return true;
        }

        if (($options['mode'] ?? '') !== 'capital') {
            return false;
        }

        return in_array($jumpShipType, ['carrier', 'dread', 'fax', 'supercarrier', 'titan'], true);
    }

    public function isHighSec(array $system): bool
    {
        return (float) ($system['security'] ?? 0.0) >= self::HIGH_SEC_MIN;
    }

    public function isSystemAllowed(array $system, array $options): bool
    {
        if ($this->isCapitalRestricted($options) && $this->isHighSec($system)) {
            return false;
        }

        return true;
    }

    public function validateEndpoints(array $start, array $end, array $options): ?array
    {
        if ($this->isCapitalRestricted($options) && ($this->isHighSec($start) || $this->isHighSec($end))) {
            return [
                'error' => 'not_feasible',
                'reason' => 'Capital ships cannot start or end in high-sec systems (sec >= 0.5).',
            ];
        }

        return null;
    }

    public function rejectionReasons(array $options): array
    {
        if ($this->isCapitalRestricted($options)) {
            return ['Rejected systems because: capital hulls cannot enter high-sec systems (sec >= 0.5).'];
        }

        return [];
    }
}
