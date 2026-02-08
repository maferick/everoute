<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class JumpFatigueModel
{
    private const MAX_FATIGUE_MIN = 300.0;
    private const COOLDOWN_BASE_PER_LY_MIN = 1.0;
    private const COOLDOWN_FATIGUE_SCALE = 60.0;
    private const FATIGUE_BASE_PER_JUMP_MIN = 5.0;
    private const FATIGUE_PER_LY_MIN = 6.0;
    private const MAX_COOLDOWN_MIN = 30.0;

    /**
     * @param array<int, array{distance_ly: float|int}> $segments
     * @return array{
     *   cooldowns_minutes: array<int, float>,
     *   cooldown_total_minutes: float,
     *   fatigue_minutes: float,
     *   fatigue_risk_label: string,
     *   caps: array{max_fatigue_minutes: float, max_cooldown_minutes: float}
     * }
     */
    public function evaluate(array $segments): array
    {
        $fatigue = 0.0;
        $cooldowns = [];
        $cooldownTotal = 0.0;

        foreach ($segments as $segment) {
            $distanceLy = max(0.0, (float) ($segment['distance_ly'] ?? 0.0));
            $cooldownBase = max(1.0, $distanceLy * self::COOLDOWN_BASE_PER_LY_MIN);
            $cooldown = min(self::MAX_COOLDOWN_MIN, $cooldownBase * (1 + ($fatigue / self::COOLDOWN_FATIGUE_SCALE)));

            $fatigueIncrease = self::FATIGUE_BASE_PER_JUMP_MIN + ($distanceLy * self::FATIGUE_PER_LY_MIN);
            $fatigue = min(self::MAX_FATIGUE_MIN, $fatigue + $fatigueIncrease);

            $cooldowns[] = round($cooldown, 2);
            $cooldownTotal += $cooldown;
        }

        $risk = 'low';
        if ($fatigue >= 180.0) {
            $risk = 'high';
        } elseif ($fatigue >= 60.0) {
            $risk = 'medium';
        }

        return [
            'cooldowns_minutes' => $cooldowns,
            'cooldown_total_minutes' => round($cooldownTotal, 2),
            'fatigue_minutes' => round($fatigue, 2),
            'fatigue_risk_label' => $risk,
            'caps' => [
                'max_fatigue_minutes' => self::MAX_FATIGUE_MIN,
                'max_cooldown_minutes' => self::MAX_COOLDOWN_MIN,
            ],
        ];
    }
}
