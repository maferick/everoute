<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class JumpPlanner
{
    private const METERS_PER_LY = 9.4607e15;
    private const BASE_JUMP_TIME_S = 60;
    private const DOCK_OVERHEAD_S = 90;
    private const MAX_FATIGUE_H = 5.0;
    private const MAX_COOLDOWN_MIN = 30.0;
    private const FATIGUE_PER_JUMP_H = 0.5;
    private const COOLDOWN_BASE_MIN = 2.0;
    private const COOLDOWN_PER_FATIGUE_MIN = 5.0;

    public function __construct(
        private JumpRangeCalculator $rangeCalculator,
        private WeightCalculator $calculator,
        private MovementRules $rules
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
            ];
        }

        $jumpHighSecRestricted = $this->rules->isCapitalRestricted($options) || $shipType === 'jump_freighter';
        if ($jumpHighSecRestricted && ($this->rules->isHighSec($start) || $this->rules->isHighSec($end))) {
            return [
                'feasible' => false,
                'error' => 'jump-assisted plan not feasible for current ship/skills',
                'reason' => 'Capital ships cannot jump into or out of high-sec systems.',
                'effective_jump_range_ly' => $effectiveRange,
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
                ];
            }

            $segments[] = $this->segmentPayload($current, $next, $systems);
            $midpoints[] = $systems[$next]['name'];
            $visited[$next] = true;
            $current = $next;
        }

        if (!$this->isJumpSystemAllowed($end, $options, $jumpHighSecRestricted)) {
            return [
                'feasible' => false,
                'error' => 'jump-assisted plan not feasible for current ship/skills',
                'reason' => 'Destination is restricted by movement rules.',
                'effective_jump_range_ly' => $effectiveRange,
            ];
        }

        $segments[] = $this->segmentPayload($current, $endId, $systems);

        foreach ($segments as $segment) {
            if ($segment['distance_ly'] > $effectiveRange) {
                return [
                    'feasible' => false,
                    'error' => 'jump-assisted plan not feasible for current ship/skills',
                    'reason' => 'A jump segment exceeds the effective jump range.',
                    'effective_jump_range_ly' => $effectiveRange,
                ];
            }
        }

        [$cooldownMinutes, $fatigueHours, $fatigueRisk] = $this->estimateFatigue($segments);
        $jumpTime = count($segments) * self::BASE_JUMP_TIME_S;
        $dockTime = count($midpoints) * self::DOCK_OVERHEAD_S;
        $estimatedTime = $jumpTime + ($cooldownMinutes * 60) + $dockTime;

        [$riskScore, $exposureScore] = $this->summarizeJumpRisk($segments, $systems, $risk, $options, $npcStationIds);

        return [
            'feasible' => true,
            'effective_jump_range_ly' => $effectiveRange,
            'total_jumps' => count($segments),
            'estimated_time_s' => round($estimatedTime, 1),
            'cooldown_minutes_estimate' => round($cooldownMinutes, 1),
            'fatigue_hours_estimate' => round($fatigueHours, 2),
            'fatigue_risk' => $fatigueRisk,
            'midpoints' => $midpoints,
            'segments' => $segments,
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
            if ($candidate === null || !$this->isJumpSystemAllowed($candidate, $options, $jumpHighSecRestricted)) {
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

    private function isJumpSystemAllowed(array $system, array $options, bool $jumpHighSecRestricted): bool
    {
        if ($jumpHighSecRestricted && $this->rules->isHighSec($system)) {
            return false;
        }

        return $this->rules->isSystemAllowed($system, $options);
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

    private function estimateFatigue(array $segments): array
    {
        $fatigue = 0.0;
        $cooldownTotal = 0.0;

        foreach ($segments as $index => $_segment) {
            $fatigue = min(self::MAX_FATIGUE_H, $fatigue + self::FATIGUE_PER_JUMP_H);
            if ($index > 0) {
                $cooldown = min(self::MAX_COOLDOWN_MIN, self::COOLDOWN_BASE_MIN + $fatigue * self::COOLDOWN_PER_FATIGUE_MIN);
                $cooldownTotal += $cooldown;
            }
        }

        $risk = 'low';
        if ($fatigue >= 3.5) {
            $risk = 'high';
        } elseif ($fatigue >= 1.5) {
            $risk = 'medium';
        }

        return [$cooldownTotal, $fatigue, $risk];
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
