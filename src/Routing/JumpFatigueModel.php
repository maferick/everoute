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
        $detail = $this->evaluateWithWaits($segments, $options);

        return [
            'cooldowns_minutes' => $detail['cooldowns_minutes'],
            'cooldown_total_minutes' => $detail['cooldown_total_minutes'],
            'fatigue_minutes' => $detail['fatigue_minutes'],
            'fatigue_risk_label' => $detail['fatigue_risk_label'],
            'caps' => $detail['caps'],
        ];
    }

    /**
     * @param array<int, array{distance_ly: float|int, bridge_chain_type?: string}> $segments
     * @param array{ship_class?: string, jump_ship_type?: string, bridge_chain_type?: string} $options
     * @return array{
     *   cooldowns_minutes: array<int, float>,
     *   cooldown_total_minutes: float,
     *   fatigue_minutes: float,
     *   fatigue_risk_label: string,
     *   caps: array{max_fatigue_minutes: float, max_cooldown_minutes: float},
     *   fatigue_after_hop_minutes: array<int, float>,
     *   waits_minutes: array<int, float>,
     *   total_wait_minutes: float
     * }
     */
    public function evaluateWithWaits(array $segments, array $options = []): array
    {
        $fatigue = 0.0;
        $cooldowns = [];
        $cooldownTotal = 0.0;
        $fatigueAfter = [];
        $waits = [];
        $totalWait = 0.0;
        $shipClass = (string) ($options['ship_class'] ?? '');
        $jumpShipType = (string) ($options['jump_ship_type'] ?? '');
        $defaultBridgeChain = (string) ($options['bridge_chain_type'] ?? '');
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
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
            $fatigueAfter[] = round($fatigue, 2);

            $wait = $index < $lastIndex ? $cooldown : 0.0;
            $waits[] = round($wait, 2);
            $totalWait += $wait;
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
            'fatigue_after_hop_minutes' => $fatigueAfter,
            'waits_minutes' => $waits,
            'total_wait_minutes' => round($totalWait, 2),
        ];
    }
}
