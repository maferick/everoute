<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Risk\RiskScorer;

final class WeightCalculator
{
    private const SECURITY_LOWSEC = 0.4;
    private const SECURITY_NULLSEC = 0.1;
    private RiskScorer $riskScorer;

    public function __construct()
    {
        $this->riskScorer = new RiskScorer();
    }

    public function cost(array $system, array $risk, bool $isChokepoint, bool $hasNpcStation, array $options): array
    {
        $travelCost = 1.0;

        $riskCost = $this->riskScorer->penalty($risk);

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
