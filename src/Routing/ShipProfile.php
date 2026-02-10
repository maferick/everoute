<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class ShipProfile
{
    public const DEFAULT_MODE = 'subcap';

    private const ALLOWED_MODES = ['hauling', 'subcap', 'capital'];

    private const ALLOWED_SHIP_CLASSES = [
        'interceptor',
        'subcap',
        'dst',
        'freighter',
        'capital',
        'jump_freighter',
        'super',
        'titan',
    ];

    public function __construct(
        public readonly string $mode,
        public readonly string $shipClass,
        public readonly string $jumpShipType,
        public readonly int $jumpSkillLevel,
        public readonly ?float $fuelPerLyFactor,
        public readonly float $jumpFuelWeight,
        public readonly float $shipModifier
    ) {
    }

    public static function create(
        string $mode,
        string $shipClass,
        ?string $jumpShipType,
        int $jumpSkillLevel,
        ?float $fuelPerLyFactor = null,
        ?float $jumpFuelWeight = null
    ): self {
        $normalizedShipClass = self::normalizeShipClass($shipClass);
        $normalizedMode = self::normalizeMode($mode, $normalizedShipClass);

        $candidateJumpShipType = trim((string) $jumpShipType);
        if ($candidateJumpShipType === '') {
            $candidateJumpShipType = $normalizedShipClass === JumpShipType::JUMP_FREIGHTER
                ? JumpShipType::JUMP_FREIGHTER
                : JumpShipType::CARRIER;
        }

        $normalizedJumpShipType = JumpShipType::normalizeJumpShipType($candidateJumpShipType);
        $skillLevel = max(0, min(5, $jumpSkillLevel));
        $normalizedFuelFactor = $fuelPerLyFactor === null ? null : max(0.0, $fuelPerLyFactor);
        $resolvedJumpFuelWeight = max(0.0, $jumpFuelWeight ?? self::defaultJumpFuelWeight($normalizedMode));

        return new self(
            $normalizedMode,
            $normalizedShipClass,
            $normalizedJumpShipType,
            $skillLevel,
            $normalizedFuelFactor,
            $resolvedJumpFuelWeight,
            self::shipModifier($normalizedShipClass)
        );
    }

    private static function normalizeShipClass(string $shipClass): string
    {
        $normalized = strtolower(trim($shipClass));

        return in_array($normalized, self::ALLOWED_SHIP_CLASSES, true) ? $normalized : 'subcap';
    }

    private static function normalizeMode(string $mode, string $shipClass): string
    {
        if (in_array($shipClass, ['capital', 'jump_freighter', 'super', 'titan'], true)) {
            return 'capital';
        }

        $normalized = strtolower(trim($mode));

        return in_array($normalized, self::ALLOWED_MODES, true) ? $normalized : self::DEFAULT_MODE;
    }

    private static function defaultJumpFuelWeight(string $mode): float
    {
        return $mode === 'capital' ? 1.0 : 0.6;
    }

    private static function shipModifier(string $shipClass): float
    {
        return match ($shipClass) {
            'interceptor' => 0.4,
            'dst' => 1.4,
            'freighter' => 1.8,
            'capital' => 2.2,
            'jump_freighter' => 2.0,
            default => 1.0,
        };
    }
}
