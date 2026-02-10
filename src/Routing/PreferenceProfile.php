<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class PreferenceProfile
{
    public const SPEED = 'speed';
    public const BALANCED = 'balanced';
    public const SAFETY = 'safety';

    /** @var array<string, array{base_gate_cost: float, base_jump_cost: float, risk_multiplier: float, sec_band_penalty_multiplier: float, fuel_multiplier: float, per_jump_constant: float}> */
    private const COEFFICIENTS = [
        self::SPEED => [
            'base_gate_cost' => 0.85,
            'base_jump_cost' => 0.75,
            'risk_multiplier' => 0.35,
            'sec_band_penalty_multiplier' => 0.25,
            'fuel_multiplier' => 0.80,
            'per_jump_constant' => 0.45,
        ],
        self::BALANCED => [
            'base_gate_cost' => 1.00,
            'base_jump_cost' => 0.95,
            'risk_multiplier' => 0.75,
            'sec_band_penalty_multiplier' => 0.60,
            'fuel_multiplier' => 1.00,
            'per_jump_constant' => 0.75,
        ],
        self::SAFETY => [
            'base_gate_cost' => 1.15,
            'base_jump_cost' => 1.10,
            'risk_multiplier' => 1.20,
            'sec_band_penalty_multiplier' => 1.00,
            'fuel_multiplier' => 1.10,
            'per_jump_constant' => 1.05,
        ],
    ];

    public function __construct(
        public readonly string $name,
        public readonly int $safetyVsSpeed
    ) {
    }

    public static function create(?string $requestedProfile, int $safetyVsSpeed): self
    {
        $clampedSafety = max(0, min(100, $safetyVsSpeed));
        $resolved = self::resolve($requestedProfile, $clampedSafety);

        return new self($resolved, $clampedSafety);
    }

    /** @return array{base_gate_cost: float, base_jump_cost: float, risk_multiplier: float, sec_band_penalty_multiplier: float, fuel_multiplier: float, per_jump_constant: float} */
    public function coefficientsForSelection(): array
    {
        return self::coefficients($this->name);
    }

    /** @return array{base_gate_cost: float, base_jump_cost: float, risk_multiplier: float, sec_band_penalty_multiplier: float, fuel_multiplier: float, per_jump_constant: float} */
    public static function coefficients(string $profile): array
    {
        $normalized = self::normalize($profile);

        return self::COEFFICIENTS[$normalized] ?? self::COEFFICIENTS[self::BALANCED];
    }

    public static function normalize(string $profile): string
    {
        $value = strtolower(trim($profile));

        return match ($value) {
            self::SPEED, self::BALANCED, self::SAFETY => $value,
            default => self::BALANCED,
        };
    }

    public static function fromSafetyVsSpeed(int $safetyVsSpeed): string
    {
        if ($safetyVsSpeed <= 40) {
            return self::SPEED;
        }
        if ($safetyVsSpeed >= 60) {
            return self::SAFETY;
        }

        return self::BALANCED;
    }

    public static function resolve(?string $requestedProfile, int $safetyVsSpeed): string
    {
        if ($requestedProfile !== null && trim($requestedProfile) !== '') {
            return self::normalize($requestedProfile);
        }

        return self::fromSafetyVsSpeed($safetyVsSpeed);
    }
}
