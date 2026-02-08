<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Cache\RedisCache;
use Everoute\Risk\RiskRepository;
use Everoute\Security\Logger;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;
use Everoute\Routing\JumpMath;

final class RouteService
{
    private static ?array $riskCache = null;
    private static int $riskCacheLoadedAt = 0;
    private static ?array $chokepointsCache = null;
    private array $systems = [];
    private array $risk = [];
    private array $chokepoints = [];
    private Graph $graph;
    private array $gateNeighbors = [];
    private array $adjacency = [];
    private array $reverseAdjacency = [];

    public function __construct(
        private SystemRepository $systemsRepo,
        private StargateRepository $stargatesRepo,
        private RiskRepository $riskRepo,
        private WeightCalculator $calculator,
        private MovementRules $rules,
        private JumpPlanner $jumpPlanner,
        private Logger $logger,
        private ?RedisCache $cache = null,
        private int $routeCacheTtlSeconds = 600,
        private int $riskCacheTtlSeconds = 60
    ) {
        $this->loadData();
    }

    public function refresh(): void
    {
        GraphStore::refresh($this->systemsRepo, $this->stargatesRepo, $this->logger);
        $this->loadData();
    }

    public function computeRoutes(array $options): array
    {
        $start = GraphStore::systemByNameOrId($options['from']);
        $end = GraphStore::systemByNameOrId($options['to']);

        if ($start === null || $end === null) {
            return ['error' => 'Unknown system'];
        }

        $cacheKey = $this->routeCacheKey((int) $start['id'], (int) $end['id'], $options);
        if ($this->cache) {
            $cached = $this->cache->getJson($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $validation = $this->rules->validateEndpoints($start, $end, $options);
        if ($validation !== null) {
            return $validation;
        }

        $profiles = [
            'fast' => $this->profileWeights(0),
            'balanced' => $this->profileWeights((int) ($options['safety_vs_speed'] ?? 50)),
            'safe' => $this->profileWeights(100),
        ];

        $results = [];
        foreach ($profiles as $key => $weights) {
            $result = $this->computeRoute($start['id'], $end['id'], $options, $weights);
            $results[$key] = $result;
        }

        $payload = [
            'from' => $start,
            'to' => $end,
            'routes' => $results,
            'risk_updated_at' => $this->riskRepo->getLatestUpdate(),
        ];

        if ($this->cache) {
            $this->cache->setJson($cacheKey, $payload, $this->routeCacheTtlSeconds);
        }

        return $payload;
    }

    private function profileWeights(int $safety): array
    {
        $riskWeight = 0.2 + ($safety / 100) * 0.8;
        $travelWeight = 1.0 - ($safety / 100) * 0.5;
        $exposureWeight = 0.4 + ($safety / 100) * 0.6;

        return [
            'risk_weight' => $riskWeight,
            'travel_weight' => $travelWeight,
            'exposure_weight' => $exposureWeight,
        ];
    }

    private function computeRoute(int $startId, int $endId, array $options, array $weights): array
    {
        $avoidLow = $options['avoid_lowsec'] ?? false;
        $avoidNull = $options['avoid_nullsec'] ?? false;
        $avoidSystems = $options['avoid_systems'] ?? [];
        $avoidSet = array_fill_keys($avoidSystems, true);

        $pathResult = $this->computeGatePath($startId, $endId, $options, $weights, $avoidLow, $avoidNull, $avoidSet);
        $this->logger->info('Route search metrics', [
            'type' => 'gate',
            'nodes_explored' => $pathResult['nodes_explored'],
            'duration_ms' => round($pathResult['duration_ms'], 2),
            'status' => $pathResult['status'],
        ]);

        if (empty($pathResult['path'])) {
            return ['error' => 'No route found'];
        }

        $summary = $this->summarizeRoute($pathResult['path'], $options);
        $why = $this->explainRoute($pathResult['path'], $startId, $endId, $options);
        $rules = [
            'constraints' => $this->rules->rejectionReasons($options),
        ];

        $plans = [];
        if (($options['mode'] ?? '') === 'capital') {
            $plans['gate'] = [
                'estimated_time_s' => $summary['travel_time_proxy'],
                'total_time_s' => $summary['travel_time_proxy'],
                'risk_score' => $summary['risk_score'],
                'exposure_score' => $summary['exposure_score'],
                'total_jumps' => $summary['total_jumps'],
            ];
            $npcStationIds = $this->npcStationIdsFromPath($pathResult['path']);
            $jumpPlan = $this->jumpPlanner->plan(
                $startId,
                $endId,
                $this->systems,
                $this->risk,
                $options,
                $npcStationIds,
                $pathResult['path']
            );
            $plans['jump'] = $jumpPlan;
            $rules['jump'] = [
                'cooldown_minutes_estimate' => $jumpPlan['jump_cooldown_total_minutes'] ?? null,
                'fatigue_minutes_estimate' => $jumpPlan['jump_fatigue_estimate_minutes'] ?? null,
                'fatigue_risk' => $jumpPlan['jump_fatigue_risk_label'] ?? null,
            ];
            $hybridStart = microtime(true);
            $plans['hybrid'] = $this->computeHybridPlan(
                $startId,
                $endId,
                $options,
                $weights,
                $jumpPlan
            );
            $hybridDurationMs = (microtime(true) - $hybridStart) * 1000;
            $this->logger->info('Route search metrics', [
                'type' => 'hybrid',
                'nodes_explored' => $plans['hybrid']['candidates_evaluated'] ?? 0,
                'duration_ms' => round($hybridDurationMs, 2),
                'status' => !empty($plans['hybrid']['feasible']) ? 'success' : 'failed',
            ]);

            $plans['recommended'] = $this->selectRecommendedPlan($plans);
        }

        return array_merge($summary, [
            'partial' => $pathResult['status'] === 'partial',
            'why' => $why,
            'midpoints' => $this->suggestMidpoints($pathResult['path']),
            'rules' => $rules,
            'plans' => $plans,
        ]);
    }

    private function summarizeRoute(array $path, array $options): array
    {
        $systems = [];
        $totalRisk = 0.0;
        $totalExposure = 0.0;
        $totalJumps = max(0, count($path) - 1);

        foreach ($path as $systemId) {
            $system = $this->systems[$systemId];
            $risk = $this->risk[$systemId] ?? [];
            $isChokepoint = isset($this->chokepoints[$systemId]);
            $hasNpc = !empty($system['has_npc_station']);
            $costs = $this->calculator->cost($system, $risk, $isChokepoint, $hasNpc, $options);
            $totalRisk += $costs['risk'];
            $totalExposure += $costs['exposure'];

            $systems[] = [
                'id' => (int) $system['id'],
                'name' => $system['name'],
                'security' => (float) $system['security'],
                'risk' => $costs['risk'],
                'chokepoint' => $isChokepoint,
                'npc_station' => $hasNpc,
                'npc_station_count' => (int) ($system['npc_station_count'] ?? 0),
            ];
        }

        $riskScore = min(100, ($totalRisk / max(1, count($path))) * 2);
        $exposureScore = $totalExposure;
        $timeProxy = $totalJumps * 60 + $totalExposure * 10;

        return [
            'systems' => $systems,
            'total_jumps' => $totalJumps,
            'total_gates' => $totalJumps,
            'risk_score' => round($riskScore, 2),
            'exposure_score' => round($exposureScore, 2),
            'travel_time_proxy' => round($timeProxy, 1),
        ];
    }

    private function explainRoute(array $path, int $startId, int $endId, array $options): array
    {
        $topRisk = [];
        foreach ($path as $systemId) {
            $system = $this->systems[$systemId];
            $risk = $this->risk[$systemId] ?? [];
            $score = (($risk['kills_last_24h'] ?? 0) + ($risk['pod_kills_last_24h'] ?? 0));
            $topRisk[] = [
                'id' => (int) $system['id'],
                'name' => $system['name'],
                'score' => $score,
            ];
        }

        usort($topRisk, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $topRisk = array_slice($topRisk, 0, 5);

        $fastest = $this->computeFastestPath($startId, $endId);
        $avoided = [];
        if (!empty($fastest)) {
            $fastSet = array_fill_keys($fastest, true);
            foreach ($fastSet as $id => $_) {
                if (!in_array($id, $path, true)) {
                    $avoided[] = $this->systems[$id]['name'];
                }
            }
        }

        $tradeoffs = [
            'jumps_saved' => max(0, count($fastest) - count($path)),
            'risk_reduction_estimate' => round(($options['safety_vs_speed'] ?? 50) / 2, 1),
        ];

        return [
            'top_risk_systems' => $topRisk,
            'avoided_hotspots' => array_slice($avoided, 0, 5),
            'key_tradeoffs' => $tradeoffs,
            'data_freshness' => $this->riskRepo->getLatestUpdate(),
        ];
    }

    private function computeFastestPath(int $startId, int $endId): array
    {
        $astar = new AStar();
        $corridor = $this->buildCorridorSet($startId, $endId);
        $heuristic = function (int $node) use ($endId): float {
            return JumpMath::distanceLy($this->systems[$node], $this->systems[$endId]);
        };
        $result = $astar->shortestPath(
            $this->gateNeighbors,
            $startId,
            $endId,
            static fn () => 1.0,
            $heuristic,
            null,
            $corridor
        );
        return $result['path'] ?? [];
    }

    private function suggestMidpoints(array $path): array
    {
        if (count($path) < 3) {
            return [];
        }
        $midIndex = (int) floor(count($path) / 2);
        $candidateIds = array_slice($path, max(1, $midIndex - 5), 10);
        $candidates = [];
        foreach ($candidateIds as $systemId) {
            $system = $this->systems[$systemId] ?? null;
            if ($system === null || empty($system['has_npc_station'])) {
                continue;
            }
            $risk = $this->risk[$systemId] ?? [];
            $riskScore = ($risk['kills_last_24h'] ?? 0) + ($risk['pod_kills_last_24h'] ?? 0);
            $candidates[] = [
                'system_id' => $systemId,
                'system_name' => $this->systems[$systemId]['name'],
                'npc_station_count' => (int) ($system['npc_station_count'] ?? 0),
                'risk_score' => $riskScore,
            ];
        }

        usort($candidates, static fn ($a, $b) => $a['risk_score'] <=> $b['risk_score']);
        return array_slice($candidates, 0, 5);
    }

    private function buildGateCostFn(array $options, array $weights, bool $avoidLow, bool $avoidNull, array $avoidSet): callable
    {
        return function (int $from, int $to) use ($options, $weights, $avoidLow, $avoidNull, $avoidSet): float {
            $system = $this->systems[$to] ?? null;
            if ($system === null) {
                return INF;
            }

            if (!$this->isGateSystemAllowed($system, $options, $avoidLow, $avoidNull, $avoidSet)) {
                return INF;
            }

            $risk = $this->risk[$to] ?? [];
            $isChokepoint = isset($this->chokepoints[$to]);
            $hasNpc = !empty($system['has_npc_station']);
            $costs = $this->calculator->cost($system, $risk, $isChokepoint, $hasNpc, $options);

            return $costs['travel'] * $weights['travel_weight']
                + $costs['risk'] * $weights['risk_weight']
                + $costs['exposure'] * $weights['exposure_weight']
                + $costs['infrastructure'];
        };
    }

    private function isGateSystemAllowed(array $system, array $options, bool $avoidLow, bool $avoidNull, array $avoidSet): bool
    {
        if (!$this->rules->isSystemAllowed($system, $options)) {
            return false;
        }

        $security = (float) ($system['security'] ?? 0);
        if ($avoidNull && $security < 0.1) {
            return false;
        }
        if ($avoidLow && $security >= 0.1 && $security < 0.5) {
            return false;
        }
        if (isset($avoidSet[$system['name']])) {
            return false;
        }

        return true;
    }

    private function computeGatePath(
        int $startId,
        int $endId,
        array $options,
        array $weights,
        bool $avoidLow,
        bool $avoidNull,
        array $avoidSet
    ): array {
        if ($startId === $endId) {
            return [
                'path' => [$startId],
                'status' => 'success',
                'nodes_explored' => 0,
                'duration_ms' => 0.0,
            ];
        }

        $astar = new AStar();
        $corridor = $this->buildCorridorSet($startId, $endId);
        $costFn = $this->buildGateCostFn($options, $weights, $avoidLow, $avoidNull, $avoidSet);
        $heuristic = function (int $node) use ($endId): float {
            return JumpMath::distanceLy($this->systems[$node], $this->systems[$endId]);
        };
        $allowFn = function (int $node) use ($options, $avoidLow, $avoidNull, $avoidSet): bool {
            $system = $this->systems[$node] ?? null;
            return $system !== null && $this->isGateSystemAllowed($system, $options, $avoidLow, $avoidNull, $avoidSet);
        };

        return $astar->shortestPath(
            $this->gateNeighbors,
            $startId,
            $endId,
            $costFn,
            $heuristic,
            $allowFn,
            $corridor
        );
    }

    private function findGateCandidates(
        int $startId,
        int $maxHops,
        array $options,
        bool $avoidLow,
        bool $avoidNull,
        array $avoidSet,
        bool $reverse = false
    ): array {
        $candidates = [];
        $queue = [[$startId, 0, false]];
        $visited = [$startId => 0];
        $adjacency = $reverse ? $this->reverseAdjacency : $this->adjacency;

        while ($queue !== []) {
            [$current, $depth, $usedRegional] = array_shift($queue);
            if ($depth >= $maxHops) {
                continue;
            }

            foreach ($adjacency[$current] ?? [] as $edge) {
                $neighbor = (int) $edge['to'];
                $system = $this->systems[$neighbor] ?? null;
                if ($system === null) {
                    continue;
                }
                if (!$this->isGateSystemAllowed($system, $options, $avoidLow, $avoidNull, $avoidSet)) {
                    continue;
                }
                $nextDepth = $depth + 1;
                $nextRegional = $usedRegional || !empty($edge['is_regional_gate']);
                $seenDepth = $visited[$neighbor] ?? null;
                if ($seenDepth === null || $nextDepth < $seenDepth) {
                    $visited[$neighbor] = $nextDepth;
                    $candidates[$neighbor] = [
                        'hops' => $nextDepth,
                        'used_regional_gate' => $nextRegional,
                    ];
                    $queue[] = [$neighbor, $nextDepth, $nextRegional];
                } elseif ($seenDepth === $nextDepth && isset($candidates[$neighbor]) && !$candidates[$neighbor]['used_regional_gate'] && $nextRegional) {
                    $candidates[$neighbor]['used_regional_gate'] = true;
                }
            }
        }

        unset($candidates[$startId]);
        return $candidates;
    }

    private function pathHasRegionalGate(array $path): bool
    {
        if (count($path) < 2) {
            return false;
        }
        for ($i = 0; $i < count($path) - 1; $i++) {
            $from = (int) $path[$i];
            $to = (int) $path[$i + 1];
            foreach ($this->adjacency[$from] ?? [] as $edge) {
                if ((int) $edge['to'] === $to && !empty($edge['is_regional_gate'])) {
                    return true;
                }
            }
        }
        return false;
    }

    private function distanceLy(array $from, array $to): float
    {
        return JumpMath::distanceLy($from, $to);
    }

    private function computeHybridPlan(
        int $startId,
        int $endId,
        array $options,
        array $weights,
        array $jumpOnlyPlan
    ): array {
        $avoidLow = $options['avoid_lowsec'] ?? false;
        $avoidNull = $options['avoid_nullsec'] ?? false;
        $avoidSystems = $options['avoid_systems'] ?? [];
        $avoidSet = array_fill_keys($avoidSystems, true);

        $maxLaunch = \Everoute\Config\Env::int('HYBRID_LAUNCH_MAX_GATES', 6);
        $launchCandidates = $this->findGateCandidates($startId, $maxLaunch, $options, $avoidLow, $avoidNull, $avoidSet);
        $launchCandidates[$startId] = ['hops' => 0, 'used_regional_gate' => false];
        if ($launchCandidates === []) {
            return [
                'feasible' => false,
                'reason' => $avoidLow || $avoidNull
                    ? 'Hybrid planning could not find a launch system within the configured gate hop limit. Avoid low/null-sec settings may be too restrictive for capital repositioning.'
                    : 'No launch systems available within the configured gate hop limit.',
                'gate_segment' => [],
                'jump_segment' => [],
            ];
        }

        $end = $this->systems[$endId];
        $best = null;
        $topCandidates = [];
        $candidatesEvaluated = 0;

        foreach ($launchCandidates as $launchId => $launchMeta) {
            $gatePathToLaunch = $this->computeGatePath($startId, $launchId, $options, $weights, $avoidLow, $avoidNull, $avoidSet);
            if ($gatePathToLaunch['path'] === []) {
                continue;
            }
            $gateSummary = $this->summarizeRoute($gatePathToLaunch['path'], $options);
            $launchRegional = $this->pathHasRegionalGate($gatePathToLaunch['path']);
            $launchSystem = $this->systems[$launchId] ?? null;
            if ($launchSystem === null) {
                continue;
            }

            $distanceLy = $this->distanceLy($launchSystem, $end);
            $riskScore = ($this->risk[$launchId]['kills_last_24h'] ?? 0) + ($this->risk[$launchId]['pod_kills_last_24h'] ?? 0);
            $hasNpc = !empty($launchSystem['has_npc_station']);
            $candidateScore = ($distanceLy * 10) + ($riskScore * 2) + ($hasNpc ? -8 : 6) + ($launchRegional ? -15 : 0);

            $npcStationIds = $this->npcStationIdsFromPath($gatePathToLaunch['path']);
            $candidatesEvaluated++;
            $jumpPlan = $this->jumpPlanner->plan(
                $launchId,
                $endId,
                $this->systems,
                $this->risk,
                $options,
                $npcStationIds,
                $gatePathToLaunch['path']
            );
            if (empty($jumpPlan['feasible'])) {
                continue;
            }

            $jumpTravel = (float) ($jumpPlan['jump_travel_time_s'] ?? $jumpPlan['estimated_time_s'] ?? 0.0);
            $cooldownMinutes = (float) ($jumpPlan['jump_cooldown_total_minutes'] ?? 0.0);
            $totalTime = $gateSummary['travel_time_proxy']
                + $jumpTravel
                + ($cooldownMinutes * 60);

            $riskScores = array_filter([
                $gateSummary['risk_score'] ?? null,
                $jumpPlan['risk_score'] ?? null,
            ], static fn ($value) => $value !== null);
            $totalRisk = $riskScores !== []
                ? round(array_sum($riskScores) / count($riskScores), 2)
                : 0.0;
            $totalExposure = round(
                ($gateSummary['exposure_score'] ?? 0)
                + ($jumpPlan['exposure_score'] ?? 0),
                2
            );

            $selectionScore = $totalTime + ($totalRisk * 8) + ($totalExposure * 2) + ($launchRegional ? -120 : 0);

            $payload = [
                'feasible' => true,
                'total_time_s' => round($totalTime, 1),
                'total_risk_score' => $totalRisk,
                'total_exposure_score' => $totalExposure,
                'launch_system' => [
                    'id' => $launchId,
                    'name' => $launchSystem['name'],
                    'gate_hops' => $gateSummary['total_gates'],
                    'used_regional_gate' => $launchRegional,
                    'npc_station' => $hasNpc,
                ],
                'landing_system' => [
                    'id' => $endId,
                    'name' => $end['name'],
                    'gate_hops_to_destination' => 0,
                    'npc_station' => !empty($end['has_npc_station']),
                ],
                'gate_segment' => [
                    'systems' => array_map(fn ($id) => $this->systems[$id]['name'], $gatePathToLaunch['path']),
                    'estimated_time_s' => $gateSummary['travel_time_proxy'],
                    'risk_score' => $gateSummary['risk_score'],
                    'exposure_score' => $gateSummary['exposure_score'],
                    'total_gates' => $gateSummary['total_gates'],
                ],
                'jump_segment' => [
                    'jump_hops_count' => $jumpPlan['jump_hops_count'] ?? $jumpPlan['total_jumps'] ?? 0,
                    'jump_total_ly' => $jumpPlan['jump_total_ly'] ?? null,
                    'jump_segments' => $jumpPlan['jump_segments'] ?? $jumpPlan['segments'] ?? [],
                    'jump_cooldown_total_minutes' => $jumpPlan['jump_cooldown_total_minutes'] ?? null,
                    'jump_fatigue_estimate_minutes' => $jumpPlan['jump_fatigue_estimate_minutes'] ?? null,
                    'jump_fatigue_risk_label' => $jumpPlan['jump_fatigue_risk_label'] ?? null,
                ],
                'landing_gate_segment' => null,
                'score' => round($selectionScore, 2),
                'candidates_evaluated' => $candidatesEvaluated,
            ];

            $payload['launch_score'] = round($candidateScore, 2);
            $topCandidates[] = [
                'id' => $launchId,
                'name' => $launchSystem['name'],
                'distance_to_destination_ly' => round($distanceLy, 2),
                'risk_score' => $riskScore,
                'npc_station' => $hasNpc,
                'used_regional_gate' => $launchRegional,
                'score' => round($candidateScore, 2),
            ];

            if ($best === null || $payload['score'] < $best['score']) {
                $best = $payload;
            }
        }

        if ($best === null) {
            return [
                'feasible' => false,
                'reason' => $avoidLow || $avoidNull
                    ? 'Hybrid planning could not find a launch chain. Avoid low/null-sec settings may be too restrictive for capital repositioning.'
                    : 'No hybrid plan could find a valid launch chain.',
                'gate_segment' => [],
                'jump_segment' => [],
                'candidates_evaluated' => $candidatesEvaluated,
            ];
        }

        usort($topCandidates, static fn ($a, $b) => $a['score'] <=> $b['score']);
        $best['candidate_launches'] = array_slice($topCandidates, 0, 3);
        $best['reasons'] = $this->hybridReasons($best, $jumpOnlyPlan);
        $best['jump_only_infeasible'] = empty($jumpOnlyPlan['feasible']);
        $best['candidates_evaluated'] = $candidatesEvaluated;

        return $best;
    }

    private function hybridReasons(array $hybridPlan, array $jumpOnlyPlan): array
    {
        $reasons = [];
        if (!empty($hybridPlan['launch_system']['used_regional_gate'])) {
            $reasons[] = 'Gated across region boundary to reposition.';
        }

        $jumpOnlyCount = (int) ($jumpOnlyPlan['jump_hops_count'] ?? $jumpOnlyPlan['total_jumps'] ?? 0);
        $hybridCount = (int) ($hybridPlan['jump_segment']['jump_hops_count'] ?? 0);
        if (!empty($jumpOnlyPlan['feasible']) && $jumpOnlyCount > 0 && $hybridCount > 0 && $hybridCount < $jumpOnlyCount) {
            $reasons[] = sprintf('Reduced jump count from %d to %d.', $jumpOnlyCount, $hybridCount);
        }

        $jumpOnlyCooldown = (float) ($jumpOnlyPlan['jump_cooldown_total_minutes'] ?? 0.0);
        $hybridCooldown = (float) ($hybridPlan['jump_segment']['jump_cooldown_total_minutes'] ?? 0.0);
        if (!empty($jumpOnlyPlan['feasible']) && $jumpOnlyCooldown > 0 && $hybridCooldown > 0 && $hybridCooldown < $jumpOnlyCooldown) {
            $reasons[] = sprintf(
                'Reduced cooldown/fatigue overhead estimate by %.1f minutes.',
                $jumpOnlyCooldown - $hybridCooldown
            );
        }

        if (empty($jumpOnlyPlan['feasible'])) {
            $reasons[] = 'Jump-only was infeasible; hybrid route provides a valid launch chain.';
        }

        return $reasons;
    }

    private function selectRecommendedPlan(array $plans): array
    {
        $candidates = [];
        foreach (['gate', 'jump', 'hybrid'] as $type) {
            if (!isset($plans[$type])) {
                continue;
            }
            $plan = $plans[$type];
            if ($type !== 'gate' && empty($plan['feasible'])) {
                continue;
            }
            $time = (float) ($plan['total_time_s'] ?? $plan['estimated_time_s'] ?? INF);
            $candidates[$type] = $time;
        }

        if ($candidates === []) {
            return ['best' => 'gate', 'reason' => 'Only gate routing is available.'];
        }

        asort($candidates);
        $best = array_key_first($candidates);
        $reason = $best === 'hybrid' ? 'Hybrid plan offers the lowest total time estimate.' : 'Selected the lowest total time estimate.';

        return [
            'best' => $best,
            'reason' => $reason,
        ];
    }

    private function loadData(): void
    {
        GraphStore::load($this->systemsRepo, $this->stargatesRepo, $this->logger);
        $this->systems = GraphStore::systems();

        $this->risk = [];
        $now = time();
        $riskRows = null;
        if (self::$riskCache !== null && ($now - self::$riskCacheLoadedAt) < $this->riskCacheTtlSeconds && $this->cache === null) {
            $riskRows = self::$riskCache;
        } else {
            $riskRows = $this->riskRepo->getHeatmap();
            if ($this->cache === null) {
                self::$riskCache = $riskRows;
                self::$riskCacheLoadedAt = $now;
            }
        }

        foreach ($riskRows as $row) {
            $this->risk[(int) $row['system_id']] = $row;
        }

        if (self::$chokepointsCache === null) {
            self::$chokepointsCache = $this->riskRepo->listChokepoints();
        }
        $this->chokepoints = array_fill_keys(self::$chokepointsCache, true);

        $this->graph = GraphStore::graph();
        $this->gateNeighbors = GraphStore::gateNeighbors();
        $this->adjacency = GraphStore::adjacency();
        $this->reverseAdjacency = GraphStore::reverseAdjacency();

        $this->jumpPlanner->preloadJumpGraphs($this->systems);

        $this->logger->info('Route data loaded', ['systems' => count($this->systems), 'risk' => count($this->risk)]);
    }

    /** @return array<int, bool> */
    private function buildCorridorSet(int $startId, int $endId): array
    {
        $start = $this->systems[$startId] ?? null;
        $end = $this->systems[$endId] ?? null;
        if ($start === null || $end === null) {
            return [];
        }

        $direct = JumpMath::distanceLy($start, $end);
        $limit = $direct * 3.0;
        $allowed = [];

        foreach ($this->systems as $id => $system) {
            $distance = JumpMath::distanceLy($start, $system) + JumpMath::distanceLy($system, $end);
            if ($distance < $limit) {
                $allowed[$id] = true;
            }
        }

        $allowed[$startId] = true;
        $allowed[$endId] = true;

        return $allowed;
    }

    private function routeCacheKey(int $startId, int $endId, array $options): string
    {
        $payload = $options;
        if (isset($payload['avoid_systems']) && is_array($payload['avoid_systems'])) {
            sort($payload['avoid_systems']);
        }
        $payload['from_id'] = $startId;
        $payload['to_id'] = $endId;
        $payload['cache_version'] = 1;
        ksort($payload);
        return 'route:' . hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /** @return int[] */
    private function npcStationIdsFromPath(array $path): array
    {
        $ids = [];
        foreach ($path as $systemId) {
            $system = $this->systems[$systemId] ?? null;
            if ($system && !empty($system['has_npc_station'])) {
                $ids[] = (int) $systemId;
            }
        }
        return $ids;
    }
}
