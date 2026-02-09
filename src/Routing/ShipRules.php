<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class ShipRules
{
    public const CARRIER = 'carrier';
    public const DREAD = 'dread';
    public const FAX = 'fax';
    public const SUPER = 'super';
    public const TITAN = 'titan';
    public const JUMP_FREIGHTER = 'jump_freighter';

    private const HIGHSEC_THRESHOLD = 0.5;

    /** @return string[] */
    public function supportedShips(): array
    {
        return [
            self::CARRIER,
            self::DREAD,
            self::FAX,
            self::SUPER,
            self::TITAN,
            self::JUMP_FREIGHTER,
        ];
    }

    public function normalizeShipType(string $shipType): string
    {
        $shipType = strtolower(trim($shipType));
        $map = [
            'carrier' => self::CARRIER,
            'dread' => self::DREAD,
            'dreadnought' => self::DREAD,
            'fax' => self::FAX,
            'forceauxiliary' => self::FAX,
            'force_auxiliary' => self::FAX,
            'super' => self::SUPER,
            'supercarrier' => self::SUPER,
            'titan' => self::TITAN,
            'jump_freighter' => self::JUMP_FREIGHTER,
            'jumpfreighter' => self::JUMP_FREIGHTER,
        ];

        return $map[$shipType] ?? $shipType;
    }

    public function isSupported(string $shipType): bool
    {
        return in_array($shipType, $this->supportedShips(), true);
    }

    public function isSystemAllowed(string $shipType, array $system, bool $isMidpoint): bool
    {
        $shipType = $this->normalizeShipType($shipType);
        $security = (float) ($system['security'] ?? 0.0);
        $isHighsec = $security >= self::HIGHSEC_THRESHOLD;

        if (!$this->isSupported($shipType)) {
            return true;
        }

        if ($shipType === self::JUMP_FREIGHTER) {
            if ($isMidpoint && $isHighsec) {
                return false;
            }
            return true;
        }

        if ($isHighsec) {
            return false;
        }

        return true;
    }

    public function isJumpCapable(array $options): bool
    {
        $mode = (string) ($options['mode'] ?? '');
        $shipClass = JumpShipType::normalizeJumpShipType((string) ($options['ship_class'] ?? ''));
        $jumpShipType = JumpShipType::normalizeJumpShipType((string) ($options['jump_ship_type'] ?? ''));

        if (in_array($shipClass, JumpShipType::CAPITALS, true) || $shipClass === JumpShipType::JUMP_FREIGHTER) {
            return true;
        }

        if ($mode !== 'capital') {
            return false;
        }

        return in_array($jumpShipType, JumpShipType::CAPITALS, true)
            || $jumpShipType === JumpShipType::JUMP_FREIGHTER;
    }
}
