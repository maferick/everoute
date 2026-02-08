<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Config\Env;
use Everoute\Security\Logger;

final class JumpPlanner
{
    private const BASE_JUMP_TIME_S = 60;
    private const DOCK_OVERHEAD_S = 90;

    public function __construct(
        private JumpRangeCalculator $rangeCalculator,
        private WeightCalculator $calculator,
        private MovementRules $rules,
        private JumpFatigueModel $fatigueModel,
        private Logger $logger
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

        $rangeMeters = $effectiveRange * JumpMath::METERS_PER_LY;
        $avoidFlags = $this->buildAvoidFlags($options);
        $debugEnabled = Env::bool('APP_DEBUG', false);
        $plannerDebug = $this->debugPayload($systems, $startId, $endId, $rangeMeters, $avoidFlags, $debugEnabled);
        $planResult = $this->buildJumpPlan(
            $startId,
            $endId,
            $systems,
            $risk,
            $npcStationIds,
            $options,
            $jumpHighSecRestricted,
            $rangeMeters,
            $avoidFlags
        );
        if (!empty($planResult['error'])) {
            if ($debugEnabled && $plannerDebug !== []) {
                $this->logger->info('Jump planning debug', $plannerDebug + $planResult['debug']);
            }

            return [
                'feasible' => false,
                'error' => 'jump-assisted plan not feasible for current ship/skills',
                'reason' => $planResult['error'],
                'effective_jump_range_ly' => $effectiveRange,
                'jump_cooldown_total_minutes' => null,
                'jump_fatigue_risk_label' => 'not_applicable',
                'debug' => $debugEnabled ? ($planResult['debug'] ?? null) : null,
            ];
        }

        $segments = $planResult['segments'];
        $midpoints = $planResult['midpoints'];

        if ($debugEnabled && $plannerDebug !== []) {
            $this->logger->info('Jump planning debug', $plannerDebug + $planResult['debug']);
        }

        foreach ($segments as $segment) {
            if ($segment['distance_ly'] > $effectiveRange) {
                return [
                    'feasible' => false,
                    'error' => 'jump-assisted plan not feasible for current ship/skills',
                    'reason' => 'A jump segment exceeds the effective jump range.',
                    'effective_jump_range_ly' => $effectiveRange,
                    'jump_cooldown_total_minutes' => null,
                    'jump_fatigue_risk_label' => 'not_applicable',
                    'debug' => $debugEnabled ? ($planResult['debug'] ?? null) : null,
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
            'debug' => $debugEnabled ? ($planResult['debug'] ?? null) : null,
        ];
    }

    private function buildJumpPlan(
        int $startId,
        int $endId,
        array $systems,
        array $risk,
        array $npcStationIds,
        array $options,
        bool $jumpHighSecRestricted,
        float $rangeMeters,
        array $avoidFlags
    ): array {
        if ($startId === $endId) {
            return ['segments' => [], 'midpoints' => [], 'debug' => []];
        }

        $bucketIndex = $this->buildBucketIndex($systems, $rangeMeters);
        $startNeighbors = $this->reachableNeighbors($startId, $systems, $bucketIndex, $rangeMeters, $options, $jumpHighSecRestricted);
        if ($startNeighbors === []) {
            return [
                'error' => $this->buildInfeasibleReason('No candidates within range from start.', $avoidFlags, $jumpHighSecRestricted),
                'debug' => [
                    'candidate_systems_evaluated' => $bucketIndex['evaluated'],
                    'edges_built' => $bucketIndex['edges'],
                    'max_segment_distance_ly' => $bucketIndex['max_distance_ly'],
                    'chain_length' => 0,
                ],
            ];
        }

        $npcStationSet = $npcStationIds === [] ? [] : array_fill_keys($npcStationIds, true);
        $pathResult = $this->shortestJumpPath(
            $startId,
            $endId,
            $systems,
            $risk,
            $npcStationSet,
            $options,
            $jumpHighSecRestricted,
            $rangeMeters,
            $bucketIndex
        );
        if ($pathResult['path'] === []) {
            return [
                'error' => $this->buildInfeasibleReason('No valid midpoint chain within jump range.', $avoidFlags, $jumpHighSecRestricted),
                'debug' => [
                    'candidate_systems_evaluated' => $bucketIndex['evaluated'],
                    'edges_built' => $bucketIndex['edges'],
                    'max_segment_distance_ly' => $bucketIndex['max_distance_ly'],
                    'chain_length' => 0,
                ],
            ];
        }

        $segments = [];
        $midpoints = [];
        for ($i = 0; $i < count($pathResult['path']) - 1; $i++) {
            $from = $pathResult['path'][$i];
            $to = $pathResult['path'][$i + 1];
            $segments[] = $this->segmentPayload($from, $to, $systems);
            if ($to !== $endId) {
                $midpoints[] = $systems[$to]['name'];
            }
        }

        return [
            'segments' => $segments,
            'midpoints' => $midpoints,
            'debug' => [
                'candidate_systems_evaluated' => $bucketIndex['evaluated'],
                'edges_built' => $bucketIndex['edges'],
                'max_segment_distance_ly' => $bucketIndex['max_distance_ly'],
                'chain_length' => count($segments),
            ],
        ];
    }

    private function shortestJumpPath(
        int $startId,
        int $endId,
        array $systems,
        array $risk,
        array $npcStationIds,
        array $options,
        bool $jumpHighSecRestricted,
        float $rangeMeters,
        array $bucketIndex
    ): array {
        $gScore = [$startId => 0.0];
        $fScore = [$startId => $this->heuristic($systems[$startId], $systems[$endId])];
        $queue = new \SplPriorityQueue();
        $queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $queue->insert($startId, -$fScore[$startId]);
        $cameFrom = [];

        while (!$queue->isEmpty()) {
            $current = $queue->extract();
            if ($current === $endId) {
                return ['path' => $this->reconstructPath($cameFrom, $current)];
            }

            foreach ($this->reachableNeighbors($current, $systems, $bucketIndex, $rangeMeters, $options, $jumpHighSecRestricted) as $neighborId => $distanceLy) {
                $tentative = $gScore[$current] + 1.0 + ($distanceLy / max(0.1, $rangeMeters / JumpMath::METERS_PER_LY));
                $riskScore = ($risk[$neighborId]['kills_last_24h'] ?? 0) + ($risk[$neighborId]['pod_kills_last_24h'] ?? 0);
                $npcBonus = $this->npcBonus($systems[$neighborId], $npcStationIds, $options);
                $avoidPenalty = $this->avoidPenalty($systems[$neighborId], $options);
                $tentative += ($riskScore * 0.05) - $npcBonus + $avoidPenalty;

                if (!isset($gScore[$neighborId]) || $tentative < $gScore[$neighborId]) {
                    $cameFrom[$neighborId] = $current;
                    $gScore[$neighborId] = $tentative;
                    $fScore[$neighborId] = $tentative + $this->heuristic($systems[$neighborId], $systems[$endId]);
                    $queue->insert($neighborId, -$fScore[$neighborId]);
                }
            }
        }

        return ['path' => []];
    }

    private function reconstructPath(array $cameFrom, int $current): array
    {
        $path = [$current];
        while (isset($cameFrom[$current])) {
            $current = $cameFrom[$current];
            array_unshift($path, $current);
        }
        return $path;
    }

    private function heuristic(array $from, array $to): float
    {
        return JumpMath::distanceLy($from, $to);
    }

    private function npcBonus(array $system, array $npcStationIds, array $options): float
    {
        $hasNpc = !empty($system['has_npc_station']);
        $preferNpc = !empty($options['prefer_npc']);
        $isNpcStation = isset($npcStationIds[$system['id'] ?? -1]);

        if ($preferNpc && ($hasNpc || $isNpcStation)) {
            return 0.5;
        }

        return ($hasNpc || $isNpcStation) ? 0.2 : 0.0;
    }

    private function avoidPenalty(array $system, array $options): float
    {
        $security = (float) ($system['security'] ?? 0.0);
        $penalty = 0.0;
        if (!empty($options['avoid_nullsec']) && $security < 0.1) {
            $penalty += 2.5;
        }
        if (!empty($options['avoid_lowsec']) && $security >= 0.1 && $security < 0.5) {
            $penalty += 1.5;
        }
        return $penalty;
    }

    private function buildBucketIndex(array $systems, float $rangeMeters): array
    {
        $bucketSize = max(1.0, $rangeMeters);
        $buckets = [];
        foreach ($systems as $id => $system) {
            $bucketKey = $this->bucketKey($system, $bucketSize);
            $buckets[$bucketKey][] = (int) $id;
        }

        return [
            'size' => $bucketSize,
            'buckets' => $buckets,
            'evaluated' => 0,
            'edges' => 0,
            'max_distance_ly' => 0.0,
        ];
    }

    private function bucketKey(array $system, float $bucketSize): string
    {
        $bx = (int) floor(((float) $system['x']) / $bucketSize);
        $by = (int) floor(((float) $system['y']) / $bucketSize);
        $bz = (int) floor(((float) $system['z']) / $bucketSize);
        return $bx . ':' . $by . ':' . $bz;
    }

    private function reachableNeighbors(
        int $systemId,
        array $systems,
        array &$bucketIndex,
        float $rangeMeters,
        array $options,
        bool $jumpHighSecRestricted
    ): array {
        $system = $systems[$systemId] ?? null;
        if ($system === null) {
            return [];
        }

        $bucketSize = $bucketIndex['size'];
        $bx = (int) floor(((float) $system['x']) / $bucketSize);
        $by = (int) floor(((float) $system['y']) / $bucketSize);
        $bz = (int) floor(((float) $system['z']) / $bucketSize);

        $neighbors = [];
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dz = -1; $dz <= 1; $dz++) {
                    $key = ($bx + $dx) . ':' . ($by + $dy) . ':' . ($bz + $dz);
                    foreach ($bucketIndex['buckets'][$key] ?? [] as $candidateId) {
                        if ($candidateId === $systemId) {
                            continue;
                        }
                        $candidate = $systems[$candidateId] ?? null;
                        if ($candidate === null) {
                            continue;
                        }
                        $bucketIndex['evaluated']++;
                        $allowed = $this->checkJumpSystemAllowed($candidate, $options, $jumpHighSecRestricted);
                        if (!$allowed['allowed']) {
                            continue;
                        }
                        $distanceMeters = JumpMath::distanceMeters($system, $candidate);
                        $distanceLy = $distanceMeters / JumpMath::METERS_PER_LY;
                        if ($distanceMeters <= $rangeMeters) {
                            $neighbors[$candidateId] = $distanceLy;
                            $bucketIndex['edges']++;
                        }

                        if ($distanceLy > $bucketIndex['max_distance_ly']) {
                            $bucketIndex['max_distance_ly'] = $distanceLy;
                        }
                    }
                }
            }
        }

        return $neighbors;
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
        $distanceLy = JumpMath::distanceLy($systems[$from], $systems[$to]);
        return [
            'from_id' => $from,
            'from' => $systems[$from]['name'],
            'to_id' => $to,
            'to' => $systems[$to]['name'],
            'distance_ly' => round($distanceLy, 2),
        ];
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

    private function buildAvoidFlags(array $options): array
    {
        return [
            'avoid_lowsec' => !empty($options['avoid_lowsec']),
            'avoid_nullsec' => !empty($options['avoid_nullsec']),
        ];
    }

    private function buildInfeasibleReason(string $base, array $avoidFlags, bool $jumpHighSecRestricted): string
    {
        $details = [];
        if ($avoidFlags['avoid_lowsec']) {
            $details[] = 'avoid_lowsec enabled';
        }
        if ($avoidFlags['avoid_nullsec']) {
            $details[] = 'avoid_nullsec enabled';
        }
        if ($jumpHighSecRestricted) {
            $details[] = 'high-sec restricted for capital jumps';
        }

        if ($details === []) {
            return $base;
        }

        $suffix = implode(', ', $details);
        if ($avoidFlags['avoid_lowsec'] || $avoidFlags['avoid_nullsec']) {
            $suffix .= '; jump planning often requires low/null-sec access';
        }

        return $base . ' Constraints: ' . $suffix . '.';
    }

    private function debugPayload(array $systems, int $startId, int $endId, float $rangeMeters, array $avoidFlags, bool $debugEnabled): array
    {
        if (!$debugEnabled) {
            return [];
        }

        return [
            'system_count' => count($systems),
            'start' => $systems[$startId]['name'] ?? $startId,
            'end' => $systems[$endId]['name'] ?? $endId,
            'effective_range_ly' => round($rangeMeters / JumpMath::METERS_PER_LY, 2),
            'avoid_lowsec' => $avoidFlags['avoid_lowsec'],
            'avoid_nullsec' => $avoidFlags['avoid_nullsec'],
        ];
    }
}
