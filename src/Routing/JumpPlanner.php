<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Config\Env;
use Everoute\Security\Logger;
use Everoute\Universe\JumpNeighborRepository;

final class JumpPlanner
{
    private const BASE_JUMP_TIME_S = 60;
    private const DOCK_OVERHEAD_S = 90;
    /** @var array<float, array<int, array<int, float>>> */
    private array $jumpNeighbors = [];
    /** @var float[] */
    private array $jumpRangeBuckets = [];
    private JumpNeighborGraphBuilder $neighborBuilder;

    public function __construct(
        private JumpRangeCalculator $rangeCalculator,
        private WeightCalculator $calculator,
        private MovementRules $rules,
        private JumpFatigueModel $fatigueModel,
        private Logger $logger,
        private ?JumpNeighborRepository $jumpNeighborRepo = null
    ) {
        $this->neighborBuilder = new JumpNeighborGraphBuilder();
        $this->jumpRangeBuckets = $this->rangeCalculator->rangeBuckets();
    }

    public function preloadJumpGraphs(array $systems, ?array $rangeBuckets = null): void
    {
        $ranges = $rangeBuckets ?? $this->jumpRangeBuckets;
        foreach ($ranges as $rangeLy) {
            $this->loadRangeBucket((float) $rangeLy, $systems);
        }
        $this->logger->info('Jump neighbor graph loaded', [
            'range_buckets' => count($ranges),
            'systems' => count($systems),
        ]);
    }

    private function loadRangeBucket(float $rangeLy, array $systems): void
    {
        if (isset($this->jumpNeighbors[$rangeLy])) {
            return;
        }

        $systemCount = count($systems);
        if ($this->jumpNeighborRepo !== null) {
            $neighbors = $this->jumpNeighborRepo->loadRangeBucket((int) $rangeLy, $systemCount);
            if ($neighbors !== null) {
                $this->jumpNeighbors[$rangeLy] = $neighbors;
                $this->logger->info('Loaded precomputed jump neighbors', [
                    'range_ly' => $rangeLy,
                    'systems' => $systemCount,
                ]);
                return;
            }
        }

        $rangeMeters = $rangeLy * JumpMath::METERS_PER_LY;
        $bucketIndex = $this->neighborBuilder->buildSpatialBuckets($systems, $rangeMeters);
        $neighbors = [];
        foreach ($systems as $id => $system) {
            $neighbors[$id] = $this->capNeighbors(
                $this->neighborBuilder->buildNeighborsForSystem($system, $systems, $bucketIndex, $rangeMeters),
                $this->rangeCalculator->neighborCapPerSystem(),
                $id,
                (int) $rangeLy
            );
        }
        $this->jumpNeighbors[$rangeLy] = $neighbors;
    }

    /** @param array<int, float> $neighbors */
    private function capNeighbors(array $neighbors, int $cap, int $systemId, int $rangeLy): array
    {
        $count = count($neighbors);
        if ($count <= $cap) {
            return $neighbors;
        }

        $this->logger->warning('Capping jump neighbors for system', [
            'system_id' => $systemId,
            'range_ly' => $rangeLy,
            'neighbor_count' => $count,
            'cap' => $cap,
        ]);

        asort($neighbors, SORT_NUMERIC);
        return array_slice($neighbors, 0, $cap, true);
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
        $startAllowed = $this->checkJumpEndpointAllowed($start, $options, 'start');
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

        $endAllowed = $this->checkJumpEndpointAllowed($end, $options, 'end');
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

        $rangeBucket = $this->resolveRangeBucket($effectiveRange);
        if ($rangeBucket !== null) {
            $this->loadRangeBucket($rangeBucket, $systems);
        }
        if ($rangeBucket === null || !isset($this->jumpNeighbors[$rangeBucket])) {
            return [
                'feasible' => false,
                'error' => 'jump-assisted plan not feasible for current ship/skills',
                'reason' => 'Missing precomputed jump range data.',
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
            $avoidFlags,
            $this->jumpNeighbors[$rangeBucket]
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

        $chainValidation = $this->validateChain($segments, $systems, $options, $effectiveRange);
        if (!$chainValidation['valid']) {
            $this->logger->debug('Jump chain rejected', [
                'reason' => $chainValidation['reason'],
                'ship_type' => $shipType,
            ]);
            return [
                'feasible' => false,
                'error' => 'jump-assisted plan not feasible for current ship/skills',
                'reason' => $chainValidation['reason'],
                'effective_jump_range_ly' => $effectiveRange,
                'jump_cooldown_total_minutes' => null,
                'jump_fatigue_risk_label' => 'not_applicable',
                'debug' => $debugEnabled ? ($planResult['debug'] ?? null) : null,
            ];
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
        array $avoidFlags,
        array $neighborsBySystem
    ): array {
        if ($startId === $endId) {
            return ['segments' => [], 'midpoints' => [], 'debug' => []];
        }

        $npcStationSet = $npcStationIds === [] ? [] : array_fill_keys($npcStationIds, true);
        $shipType = (string) ($options['jump_ship_type'] ?? '');
        $filteredNeighbors = $this->filterNeighborsForShip($neighborsBySystem, $systems, $shipType, $endId);
        if (($filteredNeighbors[$startId] ?? []) === []) {
            return [
                'error' => 'No valid jump chain within ship range.',
                'debug' => [
                    'candidate_systems_evaluated' => 0,
                    'edges_built' => 0,
                    'max_segment_distance_ly' => 0.0,
                    'chain_length' => 0,
                ],
            ];
        }
        $corridor = $this->buildCorridorSet($startId, $endId, $systems);
        $pathResult = $this->shortestJumpPath(
            $startId,
            $endId,
            $systems,
            $risk,
            $npcStationSet,
            $options,
            $jumpHighSecRestricted,
            $rangeMeters,
            $filteredNeighbors,
            $corridor
        );
        $this->logger->recordMetric('jump', [
            'nodes_explored' => $pathResult['nodes_explored'],
            'duration_ms' => round($pathResult['duration_ms'], 2),
            'status' => $pathResult['status'],
        ]);
        if ($pathResult['path'] === [] || ($pathResult['path'][count($pathResult['path']) - 1] ?? null) !== $endId) {
            return [
                'error' => 'No valid jump chain within ship range.',
                'debug' => [
                    'nodes_explored' => $pathResult['nodes_explored'] ?? 0,
                    'duration_ms' => round((float) ($pathResult['duration_ms'] ?? 0.0), 2),
                    'status' => $pathResult['status'] ?? 'failed',
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
                'nodes_explored' => $pathResult['nodes_explored'] ?? 0,
                'duration_ms' => round((float) ($pathResult['duration_ms'] ?? 0.0), 2),
                'status' => $pathResult['status'] ?? 'success',
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
        array $neighborsBySystem,
        array $corridor
    ): array {
        $astar = new AStar();
        $heuristic = function (int $node) use ($systems, $endId): float {
            return $this->heuristic($systems[$node], $systems[$endId]);
        };
        $allowFn = function (int $node) use ($systems, $options, $jumpHighSecRestricted, $endId): bool {
            $system = $systems[$node] ?? null;
            return $system !== null && $this->checkJumpNodeAllowed($node, $endId, $system, $options, $jumpHighSecRestricted);
        };
        $costFn = function (int $from, int $to, mixed $edgeData) use ($rangeMeters, $risk, $systems, $npcStationIds, $options): float {
            $distanceLy = (float) ($edgeData ?? 0.0);
            $tentative = 1.0 + ($distanceLy / max(0.1, $rangeMeters / JumpMath::METERS_PER_LY));
            $riskScore = ($risk[$to]['kills_last_24h'] ?? 0) + ($risk[$to]['pod_kills_last_24h'] ?? 0);
            $npcBonus = $this->npcBonus($systems[$to], $npcStationIds, $options);
            $avoidPenalty = $this->avoidPenalty($systems[$to], $options);
            return $tentative + ($riskScore * 0.05) - $npcBonus + $avoidPenalty;
        };

        $maxNodes = Env::int('JUMP_MAX_NODES', 2000);
        $maxMs = Env::int('JUMP_MAX_MS', 350);

        return $astar->shortestPath(
            $neighborsBySystem,
            $startId,
            $endId,
            $costFn,
            $heuristic,
            $allowFn,
            $corridor,
            max(200, $maxNodes),
            max(50, $maxMs) / 1000
        );
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

    private function resolveRangeBucket(float $effectiveRange): ?float
    {
        $bucket = (int) floor($effectiveRange);
        if ($bucket < 1) {
            return null;
        }
        $bucketValue = (float) $bucket;
        return in_array($bucketValue, $this->jumpRangeBuckets, true) ? $bucketValue : null;
    }

    /** @return array<int, bool> */
    private function buildCorridorSet(int $startId, int $endId, array $systems): array
    {
        $start = $systems[$startId] ?? null;
        $end = $systems[$endId] ?? null;
        if ($start === null || $end === null) {
            return [];
        }

        $direct = JumpMath::distanceLy($start, $end);
        $limit = $direct * 3.0;
        $allowed = [];

        foreach ($systems as $id => $system) {
            $distance = JumpMath::distanceLy($start, $system) + JumpMath::distanceLy($system, $end);
            if ($distance < $limit) {
                $allowed[$id] = true;
            }
        }

        $allowed[$startId] = true;
        $allowed[$endId] = true;

        return $allowed;
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    private function checkJumpEndpointAllowed(array $system, array $options, string $endpoint): array
    {
        $shipType = (string) ($options['jump_ship_type'] ?? '');
        if ($shipType !== 'jump_freighter' && !$this->rules->isSystemAllowedForShip($system, $shipType)) {
            return [
                'allowed' => false,
                'reason' => 'Rejected systems: capital hulls cannot enter high-sec systems (sec >= 0.5).',
            ];
        }

        if ($shipType === 'jump_freighter' && $endpoint === 'start' && $this->rules->isHighSec($system)) {
            return [
                'allowed' => false,
                'reason' => 'Jump freighters cannot initiate jumps from high-sec systems.',
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

    private function checkJumpNodeAllowed(
        int $nodeId,
        int $endId,
        array $system,
        array $options,
        bool $jumpHighSecRestricted
    ): bool {
        $shipType = (string) ($options['jump_ship_type'] ?? '');
        if (!$this->rules->isSystemAllowed($system, $options)) {
            return false;
        }

        if (!$this->rules->isSystemAllowedForShip($system, $shipType)) {
            return $shipType === 'jump_freighter' && $nodeId === $endId;
        }

        return true;
    }

    private function isJumpFreighter(array $options): bool
    {
        return ($options['jump_ship_type'] ?? '') === 'jump_freighter';
    }

    /**
     * @return array{valid: bool, reason: string|null}
     */
    private function validateChain(array $segments, array $systems, array $options, float $effectiveRange): array
    {
        if ($segments === []) {
            return ['valid' => true, 'reason' => null];
        }

        $shipType = (string) ($options['jump_ship_type'] ?? '');
        $isCapital = $this->rules->isCapitalRestricted($options);
        $isJumpFreighter = $shipType === 'jump_freighter';
        $lastIndex = count($segments) - 1;
        $startSystem = $systems[$segments[0]['from_id']] ?? null;
        $endSystem = $systems[$segments[$lastIndex]['to_id']] ?? null;
        if ($startSystem !== null) {
            $startAllowed = $this->checkJumpEndpointAllowed($startSystem, $options, 'start');
            if (!$startAllowed['allowed']) {
                $this->logger->debug(sprintf(
                    'Rejected launch %s: %s',
                    $startSystem['name'] ?? $segments[0]['from_id'],
                    $startAllowed['reason'] ?? 'endpoint not allowed'
                ));
                return [
                    'valid' => false,
                    'reason' => (string) ($startAllowed['reason'] ?? 'Launch system not allowed.'),
                ];
            }
        }
        if ($endSystem !== null) {
            $endAllowed = $this->checkJumpEndpointAllowed($endSystem, $options, 'end');
            if (!$endAllowed['allowed']) {
                $this->logger->debug(sprintf(
                    'Rejected landing %s: %s',
                    $endSystem['name'] ?? $segments[$lastIndex]['to_id'],
                    $endAllowed['reason'] ?? 'endpoint not allowed'
                ));
                return [
                    'valid' => false,
                    'reason' => (string) ($endAllowed['reason'] ?? 'Destination not allowed.'),
                ];
            }
        }

        foreach ($segments as $index => $segment) {
            $from = $systems[$segment['from_id']] ?? null;
            $to = $systems[$segment['to_id']] ?? null;
            if ($from === null || $to === null) {
                continue;
            }

            $distanceLy = (float) ($segment['distance_ly'] ?? 0.0);
            if ($distanceLy > $effectiveRange + 0.01) {
                $this->logger->debug(sprintf(
                    'Rejected hop %.2f LY > %s max %.2f',
                    $distanceLy,
                    $shipType === '' ? 'ship' : $shipType,
                    $effectiveRange
                ));
                return [
                    'valid' => false,
                    'reason' => 'A jump segment exceeds the effective jump range.',
                ];
            }

            $fromHigh = $this->rules->getSystemSpaceType($from) === 'highsec';
            $toHigh = $this->rules->getSystemSpaceType($to) === 'highsec';

            if ($isCapital && ($fromHigh || $toHigh)) {
                if ($index === 0 && $fromHigh) {
                    $label = 'launch';
                    $systemName = $from['name'];
                } elseif ($index === $lastIndex && $toHigh) {
                    $label = 'landing';
                    $systemName = $to['name'];
                } elseif ($toHigh) {
                    $label = 'midpoint';
                    $systemName = $to['name'];
                } else {
                    $label = 'midpoint';
                    $systemName = $from['name'];
                }
                $this->logger->debug(sprintf(
                    'Rejected %s %s: highsec not allowed for %s',
                    $label,
                    $systemName,
                    $shipType === '' ? 'capital ship' : $shipType
                ));
                return [
                    'valid' => false,
                    'reason' => sprintf(
                        'Jump chain invalid: %s %s is high-sec and not allowed for %s.',
                        $label,
                        $systemName,
                        $shipType === '' ? 'capital ship' : $shipType
                    ),
                ];
            }

            if ($isJumpFreighter) {
                if ($index === 0 && $fromHigh) {
                    $this->logger->debug(sprintf(
                        'Rejected launch %s: highsec not allowed for jump freighter',
                        $from['name']
                    ));
                    return [
                        'valid' => false,
                        'reason' => sprintf(
                            'Jump chain invalid: launch %s is high-sec and not allowed for jump freighter.',
                            $from['name']
                        ),
                    ];
                }

                if ($index < $lastIndex && $toHigh) {
                    $this->logger->debug(sprintf(
                        'Rejected midpoint %s: highsec not allowed for jump freighter',
                        $to['name']
                    ));
                    return [
                        'valid' => false,
                        'reason' => sprintf(
                            'Jump chain invalid: midpoint %s is high-sec and not allowed for jump freighter.',
                            $to['name']
                        ),
                    ];
                }

                if ($fromHigh && $toHigh) {
                    $this->logger->debug(sprintf(
                        'Rejected hop %s -> %s: highsec to highsec not allowed for jump freighter',
                        $from['name'],
                        $to['name']
                    ));
                    return [
                        'valid' => false,
                        'reason' => sprintf(
                            'Jump chain invalid: jump from %s to %s is high-sec to high-sec and not allowed for jump freighter.',
                            $from['name'],
                            $to['name']
                        ),
                    ];
                }
            }
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * @param array<int, array<int, float>> $neighborsBySystem
     * @return array<int, array<int, float>>
     */
    private function filterNeighborsForShip(
        array $neighborsBySystem,
        array $systems,
        string $shipType,
        int $endId
    ): array {
        $filtered = [];
        foreach ($neighborsBySystem as $systemId => $neighbors) {
            $kept = [];
            foreach ($neighbors as $neighborId => $distance) {
                $system = $systems[$neighborId] ?? null;
                if ($system === null) {
                    continue;
                }

                if (!$this->rules->isSystemAllowedForShip($system, $shipType)) {
                    if ($shipType === 'jump_freighter' && $neighborId === $endId) {
                        $kept[$neighborId] = $distance;
                        continue;
                    }
                    $this->logger->debug(sprintf(
                        'Rejected midpoint %s: highsec not allowed for %s',
                        $system['name'] ?? $neighborId,
                        $shipType === '' ? 'ship' : $shipType
                    ));
                    continue;
                }

                $kept[$neighborId] = $distance;
            }
            $filtered[$systemId] = $kept;
        }

        return $filtered;
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
