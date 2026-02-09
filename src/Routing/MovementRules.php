<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class MovementRules
{
    public const HIGH_SEC_MIN = 0.5;
    private const LOW_SEC_MIN = 0.1;

    public function isCapitalRestricted(array $options): bool
    {
        $shipClass = (string) ($options['ship_class'] ?? '');
        $jumpShipType = JumpShipType::normalizeJumpShipType((string) ($options['jump_ship_type'] ?? ''));

        if (in_array($shipClass, ['capital', 'super', 'titan'], true)) {
            return true;
        }

        if (($options['mode'] ?? '') !== 'capital') {
            return false;
        }

        return in_array($jumpShipType, JumpShipType::CAPITALS, true);
    }

    public function isHighSec(array $system): bool
    {
        return $this->getSystemSpaceType($system) === 'highsec';
    }

    public function getSystemSpaceType(array $system): string
    {
        $spaceType = strtolower((string) ($system['space_type'] ?? $system['space'] ?? $system['region_type'] ?? ''));
        if ($spaceType !== '') {
            if ($spaceType === 'wormhole') {
                return 'wh';
            }
            if (in_array($spaceType, ['highsec', 'lowsec', 'nullsec', 'pochven', 'wh'], true)) {
                return $spaceType;
            }
        }

        if (!empty($system['is_pochven'])) {
            return 'pochven';
        }

        if (!empty($system['is_wormhole']) || !empty($system['is_wh'])) {
            return 'wh';
        }

        $security = (float) ($system['security'] ?? 0.0);
        if ($security >= self::HIGH_SEC_MIN) {
            return 'highsec';
        }
        if ($security >= self::LOW_SEC_MIN) {
            return 'lowsec';
        }

        return 'nullsec';
    }

    public function isSystemAllowed(array $system, array $options): bool
    {
        if ($this->isCapitalRestricted($options) && $this->isHighSec($system)) {
            return false;
        }

        return true;
    }

    public function isSystemAllowedForShip(array $system, string $shipType): bool
    {
        $shipType = JumpShipType::normalizeJumpShipType($shipType);
        if (in_array($shipType, JumpShipType::CAPITALS, true)) {
            return $this->getSystemSpaceType($system) !== 'highsec';
        }

        if ($shipType === 'jump_freighter') {
            return $this->getSystemSpaceType($system) !== 'highsec';
        }

        return true;
    }

    public function validateEndpoints(array $start, array $end, array $options): ?array
    {
        if ($this->isCapitalRestricted($options) && ($this->isHighSec($start) || $this->isHighSec($end))) {
            return [
                'error' => 'not_feasible',
                'reason' => 'Rejected systems: capital hulls cannot enter high-sec systems (sec >= 0.5).',
            ];
        }

        return null;
    }

    public function rejectionReasons(array $options): array
    {
        if ($this->isCapitalRestricted($options)) {
            return ['Rejected systems: capital hulls cannot enter high-sec systems (sec >= 0.5).'];
        }

        return [];
    }
}
