<?php

declare(strict_types=1);

namespace Everoute\Risk;

use Everoute\Config\Env;

final class RiskScorer
{
    private float $shipWeight;
    private float $podWeight;
    private float $npcWeight;
    private float $riskScale;
    private float $maxPenalty;

    public function __construct()
    {
        $this->shipWeight = Env::float('RISK_WEIGHT_SHIP', 1.0);
        $this->podWeight = Env::float('RISK_WEIGHT_POD', 2.0);
        $this->npcWeight = Env::float('RISK_WEIGHT_NPC', 0.05);
        $this->riskScale = Env::float('RISK_SCALE', 0.5);
        $this->maxPenalty = Env::float('MAX_RISK_PENALTY', 25.0);
    }

    public function score(array $risk): float
    {
        $shipKills = (float) ($risk['ship_kills_1h'] ?? $risk['kills_last_1h'] ?? $risk['kills_last_24h'] ?? 0);
        $podKills = (float) ($risk['pod_kills_1h'] ?? $risk['pod_kills_last_1h'] ?? $risk['pod_kills_last_24h'] ?? 0);
        $npcKills = (float) ($risk['npc_kills_1h'] ?? 0);

        return ($this->shipWeight * $shipKills)
            + ($this->podWeight * $podKills)
            + ($this->npcWeight * $npcKills);
    }

    public function penalty(array $risk): float
    {
        $score = $this->score($risk);
        $penalty = $score * $this->riskScale;
        return min($this->maxPenalty, $penalty);
    }
}
