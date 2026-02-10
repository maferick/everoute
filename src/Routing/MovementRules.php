<?php

declare(strict_types=1);

namespace Everoute\Routing;

if (!class_exists(SecurityNav::class)) {
    require_once __DIR__ . '/SecurityNav.php';
}

final class MovementRules
{
    /** @var array<string, string> */
    private array $spaceTypeCache = [];

    public function isCapitalRestricted(array $options): bool
    {
        $shipClass = JumpShipType::normalizeJumpShipType((string) ($options['ship_class'] ?? ''));
        $jumpShipType = JumpShipType::normalizeJumpShipType((string) ($options['jump_ship_type'] ?? ''));

        if ($shipClass === 'capital' || in_array($shipClass, JumpShipType::CAPITALS, true)) {
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
        $cacheKey = (string) ($system['id'] ?? $system['system_id'] ?? $system['name'] ?? '');
        if ($cacheKey !== '' && isset($this->spaceTypeCache[$cacheKey])) {
            return $this->spaceTypeCache[$cacheKey];
        }

        $spaceType = strtolower((string) ($system['space_type'] ?? $system['space'] ?? $system['region_type'] ?? ''));
        $spaceType = str_replace([' ', '-', '_'], '', $spaceType);
        if ($spaceType !== '') {
            if ($spaceType === 'wormhole' || $spaceType === 'wh') {
                return $this->rememberSpaceType($cacheKey, 'wh');
            }
            if (in_array($spaceType, ['highsec', 'lowsec', 'nullsec', 'pochven'], true)) {
                return $this->rememberSpaceType($cacheKey, $spaceType);
            }
        }

        if (!empty($system['is_pochven'])) {
            return $this->rememberSpaceType($cacheKey, 'pochven');
        }

        if (!empty($system['is_wormhole']) || !empty($system['is_wh'])) {
            return $this->rememberSpaceType($cacheKey, 'wh');
        }

        return $this->rememberSpaceType($cacheKey, SecurityNav::spaceType($system));
    }

    private function rememberSpaceType(string $cacheKey, string $spaceType): string
    {
        if ($cacheKey !== '') {
            $this->spaceTypeCache[$cacheKey] = $spaceType;
        }

        return $spaceType;
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
