<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class JumpPlanner
{
    private const METERS_PER_LY = 9.4607e15;
    private const BASE_JUMP_TIME_S = 60;
    private const DOCK_OVERHEAD_S = 90;

    public function __construct(
        private JumpRangeCalculator $rangeCalculator,
        private WeightCalculator $calculator,
        private MovementRules $rules,
        private JumpFatigueModel $fatigueModel
    ) {
    }

    public function plan(
        int $startId,
        int $endId,
        array $systems,
        array $risk,
        array $options,
        array $npcStationIds,
        array $gatePath
    ): array {
        $shipType = (string) ($options['jump_ship_type'] ?? '');
        $skillLevel = (int) ($options['jump_skill_level'] ?? 0);
        $effectiveRange = $this->rangeCalculator->effectiveRange($shipType, $skillLevel);

        if ($effectiveRange === null) {
            return [
                'feasible' => false,
                'error' => 'jump-assisted plan not feasible for current ship/skills',
                'reason' => 'Unknown jump ship type for range calculation.',
                'effective_jump_range_ly' => null,
                'jump_cooldown_total_minutes' => null,
                'jump_fatigue_risk_label' => 'not_applicable',
            ];
        }

        $start = $systems[$startId] ?? null;
        $end = $systems[$endId] ?? null;
        if ($start === null || $end === null) {
            return [
                'feasible' => false,
                'error' => 'jump-assisted plan not feasible for current ship/skills',
                'reason' => 'Missing system data for jump planning.',
                'effective_jump_range_ly' => $effectiveRange,
                'jump_cooldown_total_minutes' => null,
                'jump_fatigue_risk_label' => 'not_applicable',
            ];
        }

        $jumpHighSecRestricted = $this->rules->isCapitalRestricted($options) || $shipType === 'jump_freighter';
        $startAllowed = $this->checkJumpSystemAllowed($start, $options, $jumpHighSecRestricted);
        if (!$startAllowed['allowed']) {
            return [
                'feasible' => false,
                'error' => 'jump-assisted plan not feasible for current ship/skills',
                'reason' => $startAllowed['reason'],
                'effective_jump_range_ly' => $effectiveRange,
                'jump_cooldown_total_minutes' => null,
                'jump_fatigue_risk_label' => 'not_applicable',
            ];
        }

        $endAllowed = $this->checkJumpSystemAllowed($end, $options, $jumpHighSecRestricted);
        if (!$endAllowed['allowed']) {
            return [
                'feasible' => false,
                'error' => 'jump-assisted plan not feasible for current ship/skills',
                'reason' => $endAllowed['reason'],
                'effective_jump_range_ly' => $effectiveRange,
                'jump_cooldown_total_minutes' => null,
                'jump_fatigue_risk_label' => 'not_applicable',
            ];
        }

        $segments = [];
        $midpoints = [];
        $candidates = array_values(array_unique(array_merge($npcStationIds, $gatePath)));
        $visited = [$startId => true];
        $current = $startId;
        $maxHops = 10;
        $rangeMeters = $effectiveRange * self::METERS_PER_LY;

        while ($this->distanceMeters($systems[$current], $end) > $rangeMeters) {
            $next = $this->selectNextCandidate(
                $current,
                $endId,
                $systems,
                $risk,
                $candidates,
                $visited,
                $rangeMeters,
                $options,
                $npcStationIds,
                $jumpHighSecRestricted
            );
            if ($next === null || count($midpoints) >= $maxHops) {
                return [
                    'feasible' => false,
                    'error' => 'jump-assisted plan not feasible for current ship/skills',
                    'reason' => 'No valid midpoint chain within jump range.',
                    'effective_jump_range_ly' => $effectiveRange,
                    'jump_cooldown_total_minutes' => null,
                    'jump_fatigue_risk_label' => 'not_applicable',
                ];
            }

            $segments[] = $this->segmentPayload($current, $next, $systems);
            $midpoints[] = $systems[$next]['name'];
            $visited[$next] = true;
            $current = $next;
        }

        $segments[] = $this->segmentPayload($current, $endId, $systems);

        foreach ($segments as $segment) {
            if ($segment['distance_ly'] > $effectiveRange) {
                return [
                    'feasible' => false,
                    'error' => 'jump-assisted plan not feasible for current ship/skills',
                    'reason' => 'A jump segment exceeds the effective jump range.',
                    'effective_jump_range_ly' => $effectiveRange,
                    'jump_cooldown_total_minutes' => null,
                    'jump_fatigue_risk_label' => 'not_applicable',
                ];
            }
        }

        $fatigue = $this->fatigueModel->evaluate($segments);
        $cooldownMinutes = $fatigue['cooldown_total_minutes'];
        $jumpTime = count($segments) * self::BASE_JUMP_TIME_S;
        $dockTime = count($midpoints) * self::DOCK_OVERHEAD_S;
        $estimatedTime = $jumpTime + ($cooldownMinutes * 60) + $dockTime;

        [$riskScore, $exposureScore] = $this->summarizeJumpRisk($segments, $systems, $risk, $options, $npcStationIds);
        $totalLy = 0.0;
        foreach ($segments as $segment) {
            $totalLy += (float) $segment['distance_ly'];
        }

        return [
            'feasible' => true,
            'effective_jump_range_ly' => $effectiveRange,
            'total_jumps' => count($segments),
            'jump_hops_count' => count($segments),
            'jump_total_ly' => round($totalLy, 2),
            'estimated_time_s' => round($estimatedTime, 1),
            'jump_travel_time_s' => $jumpTime,
            'jump_dock_overhead_s' => $dockTime,
            'cooldown_minutes_estimate' => round($cooldownMinutes, 2),
            'jump_cooldown_total_minutes' => round($cooldownMinutes, 2),
            'fatigue_hours_estimate' => round($fatigue['fatigue_minutes'] / 60, 2),
            'jump_fatigue_estimate_minutes' => $fatigue['fatigue_minutes'],
            'fatigue_risk' => $fatigue['fatigue_risk_label'],
            'jump_fatigue_risk_label' => $fatigue['fatigue_risk_label'],
            'midpoints' => $midpoints,
            'segments' => $segments,
            'jump_segments' => $segments,
            'jump_cooldown_per_jump_minutes' => $fatigue['cooldowns_minutes'],
            'jump_fatigue_caps' => $fatigue['caps'],
            'risk_score' => $riskScore,
            'exposure_score' => $exposureScore,
        ];
    }

    private function selectNextCandidate(
        int $current,
        int $endId,
        array $systems,
        array $risk,
        array $candidates,
        array $visited,
        float $rangeMeters,
        array $options,
        array $npcStationIds,
        bool $jumpHighSecRestricted
    ): ?int {
        $best = null;
        $bestScore = INF;
        $end = $systems[$endId];
        $npcSet = array_fill_keys($npcStationIds, true);

        foreach ($candidates as $candidateId) {
            if (isset($visited[$candidateId]) || $candidateId === $endId || $candidateId === $current) {
                continue;
            }
            $candidate = $systems[$candidateId] ?? null;
            if ($candidate === null) {
                continue;
            }
            $allowed = $this->checkJumpSystemAllowed($candidate, $options, $jumpHighSecRestricted);
            if (!$allowed['allowed']) {
                continue;
            }
            if ($this->distanceMeters($systems[$current], $candidate) > $rangeMeters) {
                continue;
            }
            $distanceToEnd = $this->distanceMeters($candidate, $end);
            $riskScore = ($risk[$candidateId]['kills_last_24h'] ?? 0) + ($risk[$candidateId]['pod_kills_last_24h'] ?? 0);
            $npcPenalty = isset($npcSet[$candidateId]) ? 0.0 : 5e12;
            $score = $distanceToEnd + ($riskScore * 1e12) + $npcPenalty;

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidateId;
            }
        }

        return $best;
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    private function checkJumpSystemAllowed(array $system, array $options, bool $jumpHighSecRestricted): array
    {
        if ($jumpHighSecRestricted && $this->rules->isHighSec($system)) {
            return [
                'allowed' => false,
                'reason' => 'Capital ships cannot jump into or out of high-sec systems.',
            ];
        }

        $security = (float) ($system['security'] ?? 0.0);
        if (!empty($options['avoid_lowsec']) && $security >= 0.1 && $security < 0.5) {
            return [
                'allowed' => false,
                'reason' => sprintf('Avoid lowsec setting blocks jump planning through %s.', $system['name'] ?? 'a low-sec system'),
            ];
        }

        if (!empty($options['avoid_nullsec']) && $security < 0.1) {
            return [
                'allowed' => false,
                'reason' => sprintf('Avoid nullsec setting blocks jump planning through %s.', $system['name'] ?? 'a null-sec system'),
            ];
        }

        if (!$this->rules->isSystemAllowed($system, $options)) {
            return [
                'allowed' => false,
                'reason' => 'Destination is restricted by movement rules.',
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    private function segmentPayload(int $from, int $to, array $systems): array
    {
        $distanceLy = $this->distanceMeters($systems[$from], $systems[$to]) / self::METERS_PER_LY;
        return [
            'from_id' => $from,
            'from' => $systems[$from]['name'],
            'to_id' => $to,
            'to' => $systems[$to]['name'],
            'distance_ly' => round($distanceLy, 2),
        ];
    }

    private function distanceMeters(array $from, array $to): float
    {
        $dx = (float) $from['x'] - (float) $to['x'];
        $dy = (float) $from['y'] - (float) $to['y'];
        $dz = (float) $from['z'] - (float) $to['z'];
        return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
    }

    private function summarizeJumpRisk(array $segments, array $systems, array $risk, array $options, array $npcStationIds): array
    {
        $ids = [];
        foreach ($segments as $segment) {
            $ids[$segment['from_id']] = true;
            $ids[$segment['to_id']] = true;
        }

        $npcSet = array_fill_keys($npcStationIds, true);
        $riskTotal = 0.0;
        $exposureTotal = 0.0;
        $count = 0;
        foreach (array_keys($ids) as $systemId) {
            $system = $systems[$systemId] ?? null;
            if ($system === null) {
                continue;
            }
            $costs = $this->calculator->cost($system, $risk[$systemId] ?? [], false, isset($npcSet[$systemId]), $options);
            $riskTotal += $costs['risk'];
            $exposureTotal += $costs['exposure'];
            $count++;
        }

        $riskScore = $count > 0 ? min(100, ($riskTotal / $count) * 2) : 0;

        return [round($riskScore, 2), round($exposureTotal, 2)];
    }
}
