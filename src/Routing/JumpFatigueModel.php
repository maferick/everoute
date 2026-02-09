<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class JumpFatigueModel
{
    private const MAX_FATIGUE_MIN = 300.0;
    private const MAX_COOLDOWN_MIN = 30.0;

    /**
     * @param array<int, array{distance_ly: float|int, bridge_chain_type?: string}> $segments
     * @param array{ship_class?: string, jump_ship_type?: string, bridge_chain_type?: string} $options
     * @return array{
     *   cooldowns_minutes: array<int, float>,
     *   cooldown_total_minutes: float,
     *   fatigue_minutes: float,
     *   fatigue_risk_label: string,
     *   caps: array{max_fatigue_minutes: float, max_cooldown_minutes: float}
     * }
     */
    public function evaluate(array $segments, array $options = []): array
    {
        $fatigue = 0.0;
        $cooldowns = [];
        $cooldownTotal = 0.0;
        $shipClass = (string) ($options['ship_class'] ?? '');
        $jumpShipType = (string) ($options['jump_ship_type'] ?? '');
        $defaultBridgeChain = (string) ($options['bridge_chain_type'] ?? '');

        foreach ($segments as $segment) {
            $distanceLy = max(0.0, (float) ($segment['distance_ly'] ?? 0.0));
            $bridgeChain = (string) ($segment['bridge_chain_type'] ?? $defaultBridgeChain);
            $effectiveLy = $distanceLy * JumpShipType::fatigueDistanceMultiplier($shipClass, $jumpShipType, $bridgeChain);
            $priorFatigue = $fatigue;
            $cooldown = min(self::MAX_COOLDOWN_MIN, max(1.0 + $effectiveLy, $priorFatigue / 10.0));

            $fatigue = min(
                self::MAX_FATIGUE_MIN,
                max(10.0 * (1.0 + $effectiveLy), $priorFatigue * (1.0 + $effectiveLy))
            );

            $cooldowns[] = round($cooldown, 2);
            $cooldownTotal += $cooldown;
            $fatigue = max(0.0, $fatigue - $cooldown);
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
