<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class WeightCalculator
{
    private const SECURITY_LOWSEC = 0.4;
    private const SECURITY_NULLSEC = 0.1;

    public function cost(array $system, array $risk, bool $isChokepoint, bool $hasNpcStation, array $options): array
    {
        $travelCost = 1.0;

        $riskCost = ($risk['kills_last_24h'] ?? 0) * 0.2
            + ($risk['kills_last_1h'] ?? 0) * 0.8
            + ($risk['pod_kills_last_24h'] ?? 0) * 0.3
            + ($risk['pod_kills_last_1h'] ?? 0) * 1.0;

        if ($isChokepoint) {
            $riskCost += 10;
        }

        $security = (float) ($system['security'] ?? 0);
        if ($security < self::SECURITY_NULLSEC) {
            $riskCost += 15;
        } elseif ($security < self::SECURITY_LOWSEC) {
            $riskCost += 7;
        }

        $exposureCost = ((float) ($system['system_size_au'] ?? 1.0)) * ($options['ship_modifier'] ?? 1.0);

        $infrastructureCost = 0.0;
        if (!empty($options['prefer_npc']) && !$hasNpcStation) {
            $infrastructureCost += 5.0;
        }

        return [
            'travel' => $travelCost,
            'risk' => $riskCost,
            'exposure' => $exposureCost,
            'infrastructure' => $infrastructureCost,
        ];
    }
}
