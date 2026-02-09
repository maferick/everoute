<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Config\Env;
use Everoute\Risk\RiskRepository;
use Everoute\Risk\RiskScorer;
use Everoute\Security\Logger;
use Everoute\Universe\JumpNeighborRepository;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;

final class NavigationEngine
{
    /** @var array<int, array<string, mixed>> */
    private array $systems = [];
    /** @var array<int, array<string, mixed>> */
    private array $risk = [];
    /** @var array<int, int[]> */
    private array $gateNeighbors = [];
    private RiskScorer $riskScorer;

    public function __construct(
        private SystemRepository $systemsRepo,
        private StargateRepository $stargatesRepo,
        private JumpNeighborRepository $jumpNeighborRepo,
        private RiskRepository $riskRepo,
        private JumpRangeCalculator $jumpRangeCalculator,
        private JumpFatigueModel $fatigueModel,
        private ShipRules $shipRules,
        private SystemLookup $systemLookup,
        private Logger $logger
    ) {
        $this->riskScorer = new RiskScorer();
        $this->loadData();
    }

    public function refresh(): void
    {
        GraphStore::refresh($this->systemsRepo, $this->stargatesRepo, $this->logger);
        $this->loadData();
    }

    public function compute(array $options): array
    {
        $start = $this->systemLookup->resolveByNameOrId($options['from']);
        $end = $this->systemLookup->resolveByNameOrId($options['to']);

        if ($start === null || $end === null) {
            return ['error' => 'Unknown system'];
        }

        $shipType = $this->resolveShipType($options);
        $jumpSkillLevel = (int) ($options['jump_skill_level'] ?? 0);
        $effectiveRange = $this->jumpRangeCalculator->effectiveRange($shipType, $jumpSkillLevel);
        $rangeBucketFloor = $effectiveRange !== null ? (int) floor($effectiveRange) : null;
        $rangeBucket = $this->resolveRangeBucket($effectiveRange);
        $debugEnabled = Env::bool('APP_DEBUG', false) || !empty($options['debug']);

        $gateRoute = $this->computeGateRoute($start['id'], $end['id'], $shipType, $options);
        $jumpRoute = $this->computeJumpRoute(
            $start['id'],
            $end['id'],
            $shipType,
            $effectiveRange,
            $rangeBucket,
            $options
        );
        $hybridRoute = $this->computeHybridRoute(
            $start['id'],
            $end['id'],
            $shipType,
            $effectiveRange,
            $rangeBucket,
            $options
        );

        $best = $this->selectBest($gateRoute, $jumpRoute, $hybridRoute);
        $explanation = $this->buildExplanation($best, $gateRoute, $jumpRoute, $hybridRoute, $options);
        $payload = [
            'gate_route' => $gateRoute,
            'jump_route' => $jumpRoute,
            'hybrid_route' => $hybridRoute,
            'best' => $best,
            'explanation' => $explanation,
            'effective_range_ly' => $effectiveRange,
        ];

        if ($debugEnabled) {
            $payload['debug'] = [
                'origin' => [
                    'name' => $start['name'] ?? (string) $start['id'],
                    'id' => (int) $start['id'],
                ],
                'destination' => [
                    'name' => $end['name'] ?? (string) $end['id'],
                    'id' => (int) $end['id'],
                ],
                'effective_range_ly' => $effectiveRange,
                'range_bucket_floor' => $rangeBucketFloor,
                'range_bucket_clamped' => $rangeBucket,
                'gate_nodes_explored' => $gateRoute['nodes_explored'] ?? 0,
                'jump_nodes_explored' => $jumpRoute['nodes_explored'] ?? 0,
                'hybrid_nodes_explored' => $hybridRoute['nodes_explored'] ?? 0,
                'illegal_systems_filtered' => [
                    'gate' => $gateRoute['illegal_systems_filtered'] ?? 0,
                    'jump' => $jumpRoute['illegal_systems_filtered'] ?? 0,
                    'hybrid' => $hybridRoute['illegal_systems_filtered'] ?? 0,
                ],
            ];
            if (isset($jumpRoute['debug']) && is_array($jumpRoute['debug'])) {
                $payload['debug']['jump_origin'] = $jumpRoute['debug'];
            }
        }

        return $payload;
    }

    private function resolveShipType(array $options): string
    {
        $mode = (string) ($options['mode'] ?? 'subcap');
        $shipClass = JumpShipType::normalizeJumpShipType((string) ($options['ship_class'] ?? ''));
        $jumpShipType = (string) ($options['jump_ship_type'] ?? '');

        if ($mode === 'capital'
            || in_array($shipClass, JumpShipType::CAPITALS, true)
            || $shipClass === JumpShipType::JUMP_FREIGHTER
        ) {
            $candidate = $jumpShipType !== '' ? $jumpShipType : $shipClass;
            return $this->shipRules->normalizeShipType($candidate);
        }

        return '';
    }

    private function computeGateRoute(int $startId, int $endId, string $shipType, array $options): array
    {
        $route = $this->computeGateRouteAttempt($startId, $endId, $shipType, $options);
        $fallbackUsed = false;
        if ($this->shouldAttemptFallback($route, $options)) {
            $relaxedOptions = $this->relaxAvoidOptions($options);
            $route = $this->computeGateRouteAttempt($startId, $endId, $shipType, $relaxedOptions);
            $fallbackUsed = true;
        }
        return $this->withRouteMeta($route, $fallbackUsed);
    }

    private function computeGateRouteAttempt(int $startId, int $endId, string $shipType, array $options): array
    {
        $preference = $this->normalizeGatePreference($options);
        if ($startId === $endId) {
            return [
                'feasible' => true,
                'total_cost' => 0.0,
                'total_gates' => 0,
                'total_jump_ly' => 0.0,
                'segments' => [],
                'systems' => [$this->systemSummary($startId)],
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
                'preference' => $preference,
                'penalty' => 0.0,
                'avoid_flags' => $this->buildAvoidFlags($options),
                'exception_corridor' => [
                    'start' => ['required' => false, 'hops' => 0],
                    'destination' => ['required' => false, 'hops' => 0],
                ],
            ];
        }
        $useSubcapPolicy = ($options['mode'] ?? 'subcap') === 'subcap';
        $policy = [
            'allowed' => null,
            'filtered' => 0,
            'exception' => [
                'start' => ['required' => false, 'hops' => 0],
                'destination' => ['required' => false, 'hops' => 0],
            ],
            'reason' => null,
        ];
        $neighbors = [];
        if ($useSubcapPolicy) {
            $policy = $this->buildSubcapGatePolicy($startId, $endId, $options);
            if ($policy['reason'] !== null) {
                return [
                    'feasible' => false,
                    'reason' => $policy['reason'],
                    'nodes_explored' => 0,
                    'illegal_systems_filtered' => $policy['filtered'],
                    'preference' => $preference,
                    'penalty' => 0.0,
                    'avoid_flags' => $this->buildAvoidFlags($options),
                    'exception_corridor' => $policy['exception'],
                ];
            }
            $neighbors = $this->buildGateNeighbors();
        } else {
            $graph = $this->buildGateGraph($startId, $endId, $shipType, $options);
            $neighbors = $graph['neighbors'];
            $policy['filtered'] = $graph['filtered'];
        }
        if (!isset($neighbors[$startId]) || !isset($neighbors[$endId])) {
            return [
                'feasible' => false,
                'reason' => 'Start or destination not allowed for gate travel.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => $policy['filtered'],
                'preference' => $preference,
                'penalty' => 0.0,
                'avoid_flags' => $this->buildAvoidFlags($options),
                'exception_corridor' => $policy['exception'],
            ];
        }

        $dijkstra = new Dijkstra();
        if (!$useSubcapPolicy) {
            $this->riskWeightCache = $this->riskWeight($options);
        }
        $result = $dijkstra->shortestPath(
            $neighbors,
            $startId,
            $endId,
            function (int $from, int $to) use ($useSubcapPolicy, $preference, $options): float {
                $system = $this->systems[$to] ?? null;
                if ($system === null) {
                    return INF;
                }
                if ($useSubcapPolicy) {
                    return $this->gateStepCost($preference, $system)
                        + $this->npcStationBonus($system, $options)
                        + $this->avoidPenalty($system, $options);
                }
                $riskScore = $this->riskScore($to);
                return 1.0
                    + ($riskScore * $this->riskWeightCache)
                    + $this->npcStationBonus($system, $options)
                    + $this->avoidPenalty($system, $options);
            },
            null,
            $useSubcapPolicy ? $policy['allowed'] : null,
            50000
        );

        if ($result['path'] === [] || ($result['path'][count($result['path']) - 1] ?? null) !== $endId) {
            return [
                'feasible' => false,
                'reason' => 'No gate route found.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $policy['filtered'],
                'preference' => $preference,
                'penalty' => 0.0,
                'avoid_flags' => $this->buildAvoidFlags($options),
                'exception_corridor' => $policy['exception'],
            ];
        }

        $segments = $this->buildSegments($result['path'], $result['edges'], $shipType);
        if (!$this->validateRoute($segments, $shipType, null)) {
            return [
                'feasible' => false,
                'reason' => 'Gate route failed validation.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $policy['filtered'],
                'preference' => $preference,
                'penalty' => 0.0,
                'avoid_flags' => $this->buildAvoidFlags($options),
                'exception_corridor' => $policy['exception'],
            ];
        }

        $summary = $this->summarizeRoute($segments, $result['distance']);
        $summary['nodes_explored'] = $result['nodes_explored'];
        $summary['illegal_systems_filtered'] = $policy['filtered'];
        $summary['preference'] = $preference;
        $summary['penalty'] = $this->routeSecurityPenalty($summary['systems'] ?? []);
        $summary['avoid_flags'] = $this->buildAvoidFlags($options);
        $summary['exception_corridor'] = $policy['exception'];
        return $summary;
    }

    private float $riskWeightCache = 0.0;

    private function computeJumpRoute(
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        ?int $rangeBucket,
        array $options
    ): array {
        $route = $this->computeJumpRouteAttempt($startId, $endId, $shipType, $effectiveRange, $rangeBucket, $options);
        $fallbackUsed = false;
        if ($this->shouldAttemptFallback($route, $options)) {
            $relaxedOptions = $this->relaxAvoidOptions($options);
            $route = $this->computeJumpRouteAttempt($startId, $endId, $shipType, $effectiveRange, $rangeBucket, $relaxedOptions);
            $fallbackUsed = true;
        }
        return $this->withRouteMeta($route, $fallbackUsed);
    }

    private function computeJumpRouteAttempt(
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        ?int $rangeBucket,
        array $options
    ): array {
        if ($startId === $endId) {
            return [
                'feasible' => true,
                'total_cost' => 0.0,
                'total_gates' => 0,
                'total_jump_ly' => 0.0,
                'segments' => [],
                'systems' => [$this->systemSummary($startId)],
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }
        if (!$this->shipRules->isJumpCapable($options)) {
            return [
                'feasible' => false,
                'reason' => 'Jump route unavailable for subcapital ships.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }
        if ($effectiveRange === null || $rangeBucket === null || $rangeBucket < 1 || $rangeBucket > 10) {
            return [
                'feasible' => false,
                'reason' => 'Jump range unavailable for ship.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }

        $debugLogs = $this->isJumpDebugEnabled($options);
        $originDiagnostics = [];
        if ($debugLogs) {
            $this->logger->debug('Jump route bucket selection', [
                'effective_range_ly' => $effectiveRange,
                'bucket' => $rangeBucket,
            ]);
            $originDiagnostics = $this->logJumpOriginNeighborDiagnostics($startId, $endId, $shipType, $options, $rangeBucket);
        }

        $neighbors = $this->jumpNeighborRepo->loadRangeBucket($rangeBucket, count($this->systems));
        if ($neighbors === null) {
            return [
                'feasible' => false,
                'reason' => 'Missing precomputed jump neighbors.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
                'debug' => $originDiagnostics,
            ];
        }

        $graph = $this->buildJumpGraph($neighbors, $startId, $endId, $shipType, $options, $debugLogs);
        if ($debugLogs && $graph['debug_sample'] !== []) {
            $this->logger->debug('Jump neighbor sample', [
                'bucket' => $rangeBucket,
                'samples' => $graph['debug_sample'],
            ]);
        }
        if (!isset($graph['neighbors'][$startId]) || !isset($graph['neighbors'][$endId])) {
            return [
                'feasible' => false,
                'reason' => 'Start or destination not allowed for jumping.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => $graph['filtered'],
                'debug' => $originDiagnostics,
            ];
        }

        $dijkstra = new Dijkstra();
        $this->riskWeightCache = $this->riskWeight($options);
        $result = $dijkstra->shortestPath(
            $graph['neighbors'],
            $startId,
            $endId,
            function (int $from, int $to, mixed $edgeData) use ($options): float {
                $system = $this->systems[$to] ?? null;
                if ($system === null) {
                    return INF;
                }
                $distance = (float) ($edgeData['distance_ly'] ?? 0.0);
                $fatigue = 5.0 + ($distance * 6.0);
                $cooldown = max(1.0, $distance * 1.0);
                $riskScore = $this->riskScore($to);
                return $distance
                    + $fatigue
                    + $cooldown
                    + ($riskScore * $this->riskWeightCache)
                    + $this->avoidPenalty($system, $options);
            },
            null,
            null,
            50000
        );

        if ($result['path'] === [] || ($result['path'][count($result['path']) - 1] ?? null) !== $endId) {
            if ($debugLogs) {
                $this->runJumpDiagnostics($startId, $endId, $shipType, $options, $rangeBucket);
            }
            return [
                'feasible' => false,
                'reason' => 'No jump route found.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $graph['filtered'],
                'debug' => $originDiagnostics,
            ];
        }

        $segments = $this->buildSegments($result['path'], $result['edges'], $shipType);
        if (!$this->validateRoute($segments, $shipType, $effectiveRange)) {
            if ($debugLogs) {
                $this->runJumpDiagnostics($startId, $endId, $shipType, $options, $rangeBucket);
            }
            return [
                'feasible' => false,
                'reason' => 'Jump route failed validation.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $graph['filtered'],
                'debug' => $originDiagnostics,
            ];
        }

        $summary = $this->summarizeRoute($segments, $result['distance']);
        $summary['nodes_explored'] = $result['nodes_explored'];
        $summary['illegal_systems_filtered'] = $graph['filtered'];
        $summary['fatigue'] = $this->fatigueModel->evaluate($this->jumpSegments($segments));
        $summary['debug'] = $originDiagnostics;
        return $summary;
    }

    private function computeHybridRoute(
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        ?int $rangeBucket,
        array $options
    ): array {
        $route = $this->computeHybridRouteAttempt($startId, $endId, $shipType, $effectiveRange, $rangeBucket, $options);
        $fallbackUsed = false;
        if ($this->shouldAttemptFallback($route, $options)) {
            $relaxedOptions = $this->relaxAvoidOptions($options);
            $route = $this->computeHybridRouteAttempt($startId, $endId, $shipType, $effectiveRange, $rangeBucket, $relaxedOptions);
            $fallbackUsed = true;
        }
        return $this->withRouteMeta($route, $fallbackUsed);
    }

    private function computeHybridRouteAttempt(
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        ?int $rangeBucket,
        array $options
    ): array {
        if ($startId === $endId) {
            return [
                'feasible' => true,
                'total_cost' => 0.0,
                'total_gates' => 0,
                'total_jump_ly' => 0.0,
                'segments' => [],
                'systems' => [$this->systemSummary($startId)],
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }
        if (!$this->shipRules->isJumpCapable($options)) {
            return [
                'feasible' => false,
                'reason' => 'Hybrid route unavailable for subcapital ships.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }
        if ($effectiveRange === null || $rangeBucket === null || $rangeBucket < 1 || $rangeBucket > 10) {
            return [
                'feasible' => false,
                'reason' => 'Jump range unavailable for ship.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }

        $neighbors = $this->jumpNeighborRepo->loadRangeBucket($rangeBucket, count($this->systems));
        if ($neighbors === null) {
            return [
                'feasible' => false,
                'reason' => 'Missing precomputed jump neighbors.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }

        $gateGraph = $this->buildGateGraph($startId, $endId, $shipType, $options);
        $jumpGraph = $this->buildJumpGraph($neighbors, $startId, $endId, $shipType, $options, false);
        $graph = $this->mergeGraphs($gateGraph['neighbors'], $jumpGraph['neighbors']);
        $filtered = $gateGraph['filtered'] + $jumpGraph['filtered'];

        if (!isset($graph[$startId]) || !isset($graph[$endId])) {
            return [
                'feasible' => false,
                'reason' => 'Start or destination not allowed for hybrid route.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => $filtered,
            ];
        }

        $dijkstra = new Dijkstra();
        $this->riskWeightCache = $this->riskWeight($options);
        $result = $dijkstra->shortestPath(
            $graph,
            $startId,
            $endId,
            function (int $from, int $to, mixed $edgeData) use ($options): float {
                $system = $this->systems[$to] ?? null;
                if ($system === null) {
                    return INF;
                }
                $edgeType = $edgeData['type'] ?? 'gate';
                if ($edgeType === 'jump') {
                    $distance = (float) ($edgeData['distance_ly'] ?? 0.0);
                    $fatigue = 5.0 + ($distance * 6.0);
                    $cooldown = max(1.0, $distance * 1.0);
                    $riskScore = $this->riskScore($to);
                    return $distance
                        + $fatigue
                        + $cooldown
                        + ($riskScore * $this->riskWeightCache)
                        + $this->npcStationBonus($system, $options)
                        + $this->avoidPenalty($system, $options);
                }

                $riskScore = $this->riskScore($to);
                return 1.0
                    + ($riskScore * $this->riskWeightCache)
                    + $this->npcStationBonus($system, $options)
                    + $this->avoidPenalty($system, $options);
            },
            null,
            null,
            80000
        );

        if ($result['path'] === [] || ($result['path'][count($result['path']) - 1] ?? null) !== $endId) {
            return [
                'feasible' => false,
                'reason' => 'No hybrid route found.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $filtered,
            ];
        }

        $segments = $this->buildSegments($result['path'], $result['edges'], $shipType);
        if (!$this->validateRoute($segments, $shipType, $effectiveRange)) {
            return [
                'feasible' => false,
                'reason' => 'Hybrid route failed validation.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $filtered,
            ];
        }

        $summary = $this->summarizeRoute($segments, $result['distance']);
        $summary['nodes_explored'] = $result['nodes_explored'];
        $summary['illegal_systems_filtered'] = $filtered;
        $summary['fatigue'] = $this->fatigueModel->evaluate($this->jumpSegments($segments));
        return $summary;
    }

    /** @return array{neighbors: array<int, array<int, array<string, mixed>>>, filtered: int} */
    private function buildGateGraph(int $startId, int $endId, string $shipType, array $options): array
    {
        $neighbors = [];
        $filtered = 0;
        foreach ($this->gateNeighbors as $from => $toList) {
            if (!isset($this->systems[$from])) {
                continue;
            }
            $fromIsMidpoint = $from !== $startId && $from !== $endId;
            if (!$this->isSystemAllowedForRoute($shipType, $this->systems[$from], $fromIsMidpoint, $options)) {
                $filtered++;
                continue;
            }
            foreach ($toList as $to) {
                if (!isset($this->systems[$to])) {
                    continue;
                }
                $toIsMidpoint = $to !== $startId && $to !== $endId;
                if (!$this->isSystemAllowedForRoute($shipType, $this->systems[$to], $toIsMidpoint, $options)) {
                    $filtered++;
                    continue;
                }
                $neighbors[$from][] = [
                    'to' => $to,
                    'type' => 'gate',
                ];
            }
        }
        foreach ([$startId, $endId] as $endpointId) {
            if (isset($this->systems[$endpointId])
                && $this->isSystemAllowedForRoute($shipType, $this->systems[$endpointId], false, $options)
                && !isset($neighbors[$endpointId])
            ) {
                $neighbors[$endpointId] = [];
            }
        }

        return ['neighbors' => $neighbors, 'filtered' => $filtered];
    }

    /** @param array<int, int[]> $precomputed */
    private function buildJumpGraph(
        array $precomputed,
        int $startId,
        int $endId,
        string $shipType,
        array $options,
        bool $debugLogs
    ): array
    {
        $neighbors = [];
        $filtered = 0;
        $debugSample = [];
        $sampleLimit = $debugLogs ? 6 : 0;
        $sampled = 0;
        foreach ($precomputed as $from => $toList) {
            if (!isset($this->systems[$from])) {
                continue;
            }
            $fromIsMidpoint = $from !== $startId && $from !== $endId;
            if (!$this->isSystemAllowedForRoute($shipType, $this->systems[$from], $fromIsMidpoint, $options)) {
                $filtered++;
                continue;
            }
            $filteredForNode = 0;
            foreach ($toList as $to) {
                if (!isset($this->systems[$to])) {
                    continue;
                }
                $toIsMidpoint = $to !== $startId && $to !== $endId;
                if (!$this->isSystemAllowedForRoute($shipType, $this->systems[$to], $toIsMidpoint, $options)) {
                    $filtered++;
                    $filteredForNode++;
                    continue;
                }
                $neighbors[$from][] = [
                    'to' => $to,
                    'type' => 'jump',
                    'distance_ly' => JumpMath::distanceLy($this->systems[$from], $this->systems[$to]),
                ];
            }
            if ($debugLogs && $sampled < $sampleLimit) {
                $debugSample[] = [
                    'system_id' => $from,
                    'fetched_neighbor_count' => count($toList),
                    'filtered_illegal_count' => $filteredForNode,
                ];
                $sampled++;
            }
        }
        foreach ([$startId, $endId] as $endpointId) {
            if (isset($this->systems[$endpointId])
                && $this->isSystemAllowedForRoute($shipType, $this->systems[$endpointId], false, $options)
                && !isset($neighbors[$endpointId])
            ) {
                $neighbors[$endpointId] = [];
            }
        }

        return ['neighbors' => $neighbors, 'filtered' => $filtered, 'debug_sample' => $debugSample];
    }

    /** @param array<int, array<int, array<string, mixed>>> $gate */
    /** @param array<int, array<int, array<string, mixed>>> $jump */
    private function mergeGraphs(array $gate, array $jump): array
    {
        $merged = $gate;
        foreach ($jump as $from => $edges) {
            foreach ($edges as $edge) {
                $merged[$from][] = $edge;
            }
        }
        return $merged;
    }

    private function isSystemAllowedForRoute(string $shipType, array $system, bool $isMidpoint, array $options): bool
    {
        if (!$this->shipRules->isSystemAllowed($shipType, $system, $isMidpoint)) {
            return false;
        }
        $security = (float) ($system['security'] ?? 0.0);
        if ($isMidpoint) {
            if ($this->shouldFilterAvoidedSpace($options)) {
                if (!empty($options['avoid_nullsec']) && $security < 0.1) {
                    return false;
                }
                if (!empty($options['avoid_lowsec']) && $security >= 0.1 && $security < 0.5) {
                    return false;
                }
            }
            if (!empty($options['avoid_systems']) && in_array($system['name'], (array) $options['avoid_systems'], true)) {
                return false;
            }
        }
        return true;
    }

    private function normalizeGatePreference(array $options): string
    {
        $preference = strtolower((string) ($options['preference'] ?? 'shorter'));
        return in_array($preference, ['shorter', 'safer', 'less_secure'], true) ? $preference : 'shorter';
    }

    private function gateStepCost(string $preference, array $system): float
    {
        if ($preference === 'shorter') {
            return 1.0;
        }
        $security = (float) ($system['security'] ?? 0.0);
        $penalty = exp(0.15 * $this->securityPenalty($security));

        if ($preference === 'safer') {
            if ($security <= 0.0) {
                return 2.0 * $penalty;
            }
            if ($security < 0.45) {
                return $penalty;
            }
            return 0.90;
        }

        if ($security <= 0.0) {
            return 2.0 * $penalty;
        }
        if ($security < 0.45) {
            return 0.90;
        }
        return $penalty;
    }

    private function securityPenalty(float $security): float
    {
        $penalty = (1.0 - $security) * 100.0;
        return max(0.0, min(100.0, $penalty));
    }

    private function npcStationBonus(array $system, array $options): float
    {
        if (empty($options['prefer_npc'])) {
            return 0.0;
        }

        $npcCount = (int) ($system['npc_station_count'] ?? 0);
        $hasNpcStation = !empty($system['has_npc_station']) || $npcCount > 0;

        if (!$hasNpcStation) {
            return 0.0;
        }

        $count = max(1, $npcCount);
        $bonusMagnitude = min(0.5, 0.1 * $count);
        return -$bonusMagnitude;
    }

    /** @param array<int, array{id: int, security: float}> $systems */
    private function routeSecurityPenalty(array $systems): float
    {
        if ($systems === []) {
            return 0.0;
        }
        $total = 0.0;
        $count = 0;
        foreach ($systems as $system) {
            $security = (float) ($system['security'] ?? 0.0);
            $total += $this->securityPenalty($security);
            $count++;
        }
        return $count > 0 ? round($total / $count, 2) : 0.0;
    }

    private function buildAvoidFlags(array $options): array
    {
        return [
            'avoid_lowsec' => !empty($options['avoid_lowsec']),
            'avoid_nullsec' => !empty($options['avoid_nullsec']),
        ];
    }

    private function shouldFilterAvoidedSpace(array $options): bool
    {
        $strictness = strtolower((string) ($options['avoid_strictness'] ?? 'soft'));
        return $strictness === 'strict';
    }

    private function relaxAvoidOptions(array $options): array
    {
        $options['avoid_strictness'] = 'soft';
        return $options;
    }

    private function shouldAttemptFallback(array $route, array $options): bool
    {
        if (!empty($route['feasible'])) {
            return false;
        }
        if (!$this->shouldFilterAvoidedSpace($options)) {
            return false;
        }
        if (empty($options['avoid_lowsec']) && empty($options['avoid_nullsec'])) {
            return false;
        }
        $reason = (string) ($route['reason'] ?? '');
        if (in_array($reason, [
            'Jump route unavailable for subcapital ships.',
            'Hybrid route unavailable for subcapital ships.',
            'Jump range unavailable for ship.',
            'Missing precomputed jump neighbors.',
        ], true)) {
            return false;
        }
        return true;
    }

    private function withRouteMeta(array $route, bool $fallbackUsed): array
    {
        $route['fallback_used'] = $fallbackUsed;
        $systems = is_array($route['systems'] ?? null) ? $route['systems'] : [];
        $route['space_types'] = $this->spaceTypesUsed($systems);
        return $route;
    }

    /** @param array<int, array{security: float}> $systems */
    private function spaceTypesUsed(array $systems): array
    {
        $types = [];
        foreach ($systems as $system) {
            $security = (float) ($system['security'] ?? 0.0);
            if ($security >= 0.5) {
                $types['highsec'] = true;
            } elseif ($security >= 0.1) {
                $types['lowsec'] = true;
            } else {
                $types['nullsec'] = true;
            }
        }
        $ordered = ['highsec', 'lowsec', 'nullsec'];
        $result = [];
        foreach ($ordered as $type) {
            if (isset($types[$type])) {
                $result[] = $type;
            }
        }
        return $result;
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

    private function resolveRangeBucket(?float $effectiveRange): ?int
    {
        if ($effectiveRange === null) {
            return null;
        }
        $bucket = (int) floor($effectiveRange);
        if ($bucket < 1) {
            return null;
        }
        return min(10, $bucket);
    }

    private function isJumpDebugEnabled(array $options): bool
    {
        $logLevel = strtolower((string) Env::get('LOG_LEVEL', ''));
        return Env::bool('ROUTE_DEBUG', false) || $logLevel === 'debug' || !empty($options['debug']);
    }

    private function runJumpDiagnostics(int $startId, int $endId, string $shipType, array $options, int $rangeBucket): void
    {
        $buckets = [$rangeBucket - 1, $rangeBucket - 2];
        foreach ($buckets as $bucket) {
            if ($bucket < 1 || $bucket > 10) {
                continue;
            }
            $neighbors = $this->jumpNeighborRepo->loadRangeBucket($bucket, count($this->systems));
            if ($neighbors === null) {
                $this->logger->debug('Jump diagnostic missing neighbors', [
                    'bucket' => $bucket,
                ]);
                continue;
            }
            $graph = $this->buildJumpGraph($neighbors, $startId, $endId, $shipType, $options, false);
            if (!isset($graph['neighbors'][$startId]) || !isset($graph['neighbors'][$endId])) {
                $this->logger->debug('Jump diagnostic filtered endpoints', [
                    'bucket' => $bucket,
                    'filtered' => $graph['filtered'],
                ]);
                continue;
            }

            $dijkstra = new Dijkstra();
            $this->riskWeightCache = $this->riskWeight($options);
            $result = $dijkstra->shortestPath(
                $graph['neighbors'],
                $startId,
                $endId,
                function (int $from, int $to, mixed $edgeData) use ($options): float {
                    $system = $this->systems[$to] ?? null;
                    if ($system === null) {
                        return INF;
                    }
                    $distance = (float) ($edgeData['distance_ly'] ?? 0.0);
                    $fatigue = 5.0 + ($distance * 6.0);
                    $cooldown = max(1.0, $distance * 1.0);
                    $riskScore = $this->riskScore($to);
                    return $distance
                        + $fatigue
                        + $cooldown
                        + ($riskScore * $this->riskWeightCache)
                        + $this->avoidPenalty($system, $options);
                },
                null,
                null,
                20000
            );

            $feasible = $result['path'] !== [] && ($result['path'][count($result['path']) - 1] ?? null) === $endId;
            $this->logger->debug('Jump diagnostic search', [
                'bucket' => $bucket,
                'feasible' => $feasible,
                'nodes_explored' => $result['nodes_explored'] ?? 0,
                'filtered' => $graph['filtered'],
            ]);
        }
    }

    private function logJumpOriginNeighborDiagnostics(
        int $startId,
        int $endId,
        string $shipType,
        array $options,
        int $rangeBucket
    ): array {
        $origin = $this->systems[$startId] ?? null;
        if ($origin === null) {
            $this->logger->debug('Jump origin missing system data', ['origin_id' => $startId]);
            return [
                'origin_id' => $startId,
                'db_neighbor_count' => 0,
                'decoded_count' => 0,
                'filtered_highsec' => 0,
                'filtered_avoided' => 0,
                'filtered_other' => 0,
                'filtered_other_reasons' => [],
                'note' => 'Jump neighbors empty at origin: decode/query/mapping failure.',
            ];
        }

        $this->logger->debug('Jump origin resolved', [
            'origin_name' => $origin['name'] ?? (string) $startId,
            'origin_id' => $startId,
            'origin_security' => (float) ($origin['security'] ?? 0.0),
        ]);
        $originReason = $this->jumpFilterReason($shipType, $origin, false, $options);
        if ($originReason !== null) {
            $this->logger->warning('Jump origin disallowed by routing rules', [
                'origin_id' => $startId,
                'bucket' => $rangeBucket,
                'reason' => $originReason,
            ]);
        }

        $originNeighbors = $this->jumpNeighborRepo->loadSystemNeighbors($startId, $rangeBucket);
        if ($originNeighbors === null) {
            $this->logger->debug('Jump neighbors missing at origin', [
                'origin_id' => $startId,
                'bucket' => $rangeBucket,
            ]);
            return [
                'origin_id' => $startId,
                'origin_name' => $origin['name'] ?? (string) $startId,
                'db_neighbor_count' => 0,
                'decoded_count' => 0,
                'filtered_highsec' => 0,
                'filtered_avoided' => 0,
                'filtered_other' => 0,
                'filtered_other_reasons' => [],
                'note' => 'Jump neighbors empty at origin: decode/query/mapping failure.',
            ];
        }

        $decodedNeighbors = $originNeighbors['neighbor_ids'];
        $decodedCount = count($decodedNeighbors);
        $fetchedCount = $originNeighbors['neighbor_count'];
        $this->logger->debug('Jump origin neighbor payload', [
            'origin_id' => $startId,
            'bucket' => $rangeBucket,
            'db_neighbor_count' => $fetchedCount,
            'decoded_count' => $decodedCount,
        ]);

        if ($decodedCount === 0 && $fetchedCount > 0) {
            $this->logger->warning('Jump neighbors empty at origin: decode/query/mapping failure.', [
                'origin_id' => $startId,
                'bucket' => $rangeBucket,
                'db_neighbor_count' => $fetchedCount,
            ]);
        }

        $beforeList = $this->formatNeighborSamples($decodedNeighbors, 10);
        $filteredCounts = [
            'filtered_highsec' => 0,
            'filtered_avoided' => 0,
            'filtered_other' => 0,
        ];
        $filteredOtherReasons = [];
        $afterNeighbors = [];
        foreach ($decodedNeighbors as $neighborId) {
            $system = $this->systems[$neighborId] ?? null;
            if ($system === null) {
                $filteredCounts['filtered_other']++;
                $filteredOtherReasons['missing_system'] = true;
                continue;
            }
            $toIsMidpoint = $neighborId !== $startId && $neighborId !== $endId;
            $reason = $this->jumpFilterReason($shipType, $system, $toIsMidpoint, $options);
            if ($reason === null) {
                $afterNeighbors[] = $neighborId;
                continue;
            }
            if ($reason === 'highsec') {
                $filteredCounts['filtered_highsec']++;
            } elseif (str_starts_with($reason, 'avoid_')) {
                $filteredCounts['filtered_avoided']++;
            } else {
                $filteredCounts['filtered_other']++;
                $filteredOtherReasons[$reason] = true;
            }
        }

        $afterList = $this->formatNeighborSamples($afterNeighbors, 10);
        $this->logger->debug('Jump origin neighbor filtering', array_merge([
            'origin_id' => $startId,
            'bucket' => $rangeBucket,
            'before_filter' => $beforeList,
            'after_filter' => $afterList,
        ], $filteredCounts));

        if ($filteredCounts['filtered_other'] > 0) {
            $this->logger->warning('Jump origin neighbor filter predicate flagged', [
                'origin_id' => $startId,
                'bucket' => $rangeBucket,
                'filtered_other_reasons' => array_keys($filteredOtherReasons),
            ]);
        }

        if ($decodedCount === 0 && $fetchedCount === 0) {
            $this->logger->warning('Jump origin has zero neighbors in DB.', [
                'origin_id' => $startId,
                'bucket' => $rangeBucket,
            ]);
            return [
                'origin_id' => $startId,
                'origin_name' => $origin['name'] ?? (string) $startId,
                'origin_security' => (float) ($origin['security'] ?? 0.0),
                'db_neighbor_count' => 0,
                'decoded_count' => 0,
                'filtered_highsec' => 0,
                'filtered_avoided' => 0,
                'filtered_other' => 0,
                'filtered_other_reasons' => [],
                'note' => 'Jump neighbors empty at origin: decode/query/mapping failure.',
            ];
        }

        if ($beforeList === [] && $decodedCount > 0) {
            $this->logger->warning('Jump neighbors decoded but missing system entries at origin.', [
                'origin_id' => $startId,
                'bucket' => $rangeBucket,
            ]);
        }

        if ($beforeList !== [] && $afterList === []) {
            $this->logger->warning('All jump neighbors filtered at origin.', [
                'origin_id' => $startId,
                'bucket' => $rangeBucket,
            ]);
        }

        $diagnostics = [
            'origin_id' => $startId,
            'origin_name' => $origin['name'] ?? (string) $startId,
            'origin_security' => (float) ($origin['security'] ?? 0.0),
            'db_neighbor_count' => $fetchedCount,
            'decoded_count' => $decodedCount,
            'filtered_highsec' => $filteredCounts['filtered_highsec'],
            'filtered_avoided' => $filteredCounts['filtered_avoided'],
            'filtered_other' => $filteredCounts['filtered_other'],
            'filtered_other_reasons' => array_keys($filteredOtherReasons),
        ];
        if ($decodedCount === 0) {
            $diagnostics['note'] = 'Jump neighbors empty at origin: decode/query/mapping failure.';
        }
        return $diagnostics;
    }

    /** @return array<int, array{name:string, security:float, security_raw:float|null}> */
    private function formatNeighborSamples(array $neighborIds, int $limit): array
    {
        $samples = [];
        foreach ($neighborIds as $neighborId) {
            if (count($samples) >= $limit) {
                break;
            }
            $system = $this->systems[$neighborId] ?? null;
            $samples[] = [
                'name' => $system['name'] ?? (string) $neighborId,
                'security' => (float) ($system['security'] ?? 0.0),
                'security_raw' => $system !== null && array_key_exists('security_raw', $system)
                    ? (float) $system['security_raw']
                    : null,
            ];
        }
        return $samples;
    }

    private function jumpFilterReason(string $shipType, array $system, bool $isMidpoint, array $options): ?string
    {
        if (!$this->shipRules->isSystemAllowed($shipType, $system, $isMidpoint)) {
            $security = (float) ($system['security'] ?? 0.0);
            if ($security >= 0.5) {
                return 'highsec';
            }
            return 'ship_rules';
        }

        $security = (float) ($system['security'] ?? 0.0);
        if ($isMidpoint) {
            if ($this->shouldFilterAvoidedSpace($options)) {
                if (!empty($options['avoid_nullsec']) && $security < 0.1) {
                    return 'avoid_nullsec';
                }
                if (!empty($options['avoid_lowsec']) && $security >= 0.1 && $security < 0.5) {
                    return 'avoid_lowsec';
                }
            }
            if (!empty($options['avoid_systems'])
                && in_array($system['name'], (array) $options['avoid_systems'], true)
            ) {
                return 'avoid_systems';
            }
        }

        return null;
    }

    /** @return array{allowed: ?array<int, bool>, filtered: int, exception: array<string, array<string, int|bool>>, reason: ?string} */
    private function buildSubcapGatePolicy(int $startId, int $endId, array $options): array
    {
        $avoidFlags = $this->buildAvoidFlags($options);
        $applyAvoidFilters = $this->shouldFilterAvoidedSpace($options);
        $avoidNames = array_fill_keys((array) ($options['avoid_systems'] ?? []), true);
        $blocked = [];
        $allowedCore = [];

        foreach ($this->systems as $id => $system) {
            $name = (string) ($system['name'] ?? '');
            if (isset($avoidNames[$name]) && $id !== $startId && $id !== $endId) {
                $blocked[$id] = true;
                continue;
            }
            $security = (float) ($system['security'] ?? 0.0);
            $avoidLowsec = $applyAvoidFilters ? $avoidFlags['avoid_lowsec'] : false;
            $avoidNullsec = $applyAvoidFilters ? $avoidFlags['avoid_nullsec'] : false;
            if ($this->isInAllowedCore($security, $avoidLowsec, $avoidNullsec)) {
                $allowedCore[$id] = true;
            }
        }

        if ($allowedCore === []) {
            return [
                'allowed' => null,
                'filtered' => count($this->systems),
                'exception' => [
                    'start' => ['required' => false, 'hops' => 0],
                    'destination' => ['required' => false, 'hops' => 0],
                ],
                'reason' => 'No allowed core systems available for gate travel.',
            ];
        }

        $exceptionStart = [];
        $exceptionEnd = [];
        $exceptionStartHops = 0;
        $exceptionEndHops = 0;

        if (!isset($allowedCore[$startId])) {
            $corridor = $this->findShortestGateCorridor($startId, $allowedCore, $blocked);
            if (!$corridor['found']) {
                return [
                    'allowed' => null,
                    'filtered' => count($this->systems),
                    'exception' => [
                        'start' => ['required' => true, 'hops' => 0],
                        'destination' => ['required' => false, 'hops' => 0],
                    ],
                    'reason' => 'No allowed core reachable from start.',
                ];
            }
            $exceptionStart = $corridor['nodes'];
            $exceptionStartHops = $corridor['hops'];
        }

        if (!isset($allowedCore[$endId])) {
            $corridor = $this->findShortestGateCorridor($endId, $allowedCore, $blocked);
            if (!$corridor['found']) {
                return [
                    'allowed' => null,
                    'filtered' => count($this->systems),
                    'exception' => [
                        'start' => ['required' => !isset($allowedCore[$startId]), 'hops' => $exceptionStartHops],
                        'destination' => ['required' => true, 'hops' => 0],
                    ],
                    'reason' => 'No allowed core reachable from destination.',
                ];
            }
            $exceptionEnd = $corridor['nodes'];
            $exceptionEndHops = $corridor['hops'];
        }

        $allowed = $allowedCore;
        $allowed[$startId] = true;
        $allowed[$endId] = true;
        foreach ($exceptionStart as $node => $_value) {
            $allowed[$node] = true;
        }
        foreach ($exceptionEnd as $node => $_value) {
            $allowed[$node] = true;
        }

        $filtered = count($this->systems) - count($allowed);

        return [
            'allowed' => $allowed,
            'filtered' => max(0, $filtered),
            'exception' => [
                'start' => ['required' => !isset($allowedCore[$startId]), 'hops' => $exceptionStartHops],
                'destination' => ['required' => !isset($allowedCore[$endId]), 'hops' => $exceptionEndHops],
            ],
            'reason' => null,
        ];
    }

    private function isInAllowedCore(float $security, bool $avoidLowsec, bool $avoidNullsec): bool
    {
        $isHighsec = $security >= 0.5;
        $isLowsec = $security >= 0.1 && $security < 0.5;
        $isNullsec = $security < 0.1;

        if ($avoidLowsec && $avoidNullsec) {
            return $isHighsec;
        }
        if ($avoidLowsec && !$avoidNullsec) {
            return $isHighsec || $isNullsec;
        }
        if (!$avoidLowsec && $avoidNullsec) {
            return $isHighsec || $isLowsec;
        }
        return true;
    }

    /** @return array{found: bool, nodes: array<int, bool>, hops: int} */
    private function findShortestGateCorridor(int $startId, array $allowedCore, array $blocked): array
    {
        if (isset($allowedCore[$startId])) {
            return ['found' => true, 'nodes' => [$startId => true], 'hops' => 0];
        }

        $queue = new \SplQueue();
        $queue->enqueue($startId);
        $visited = [$startId => true];
        $prev = [];

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            foreach ($this->gateNeighbors[$current] ?? [] as $neighbor) {
                $neighbor = (int) $neighbor;
                if (isset($visited[$neighbor])) {
                    continue;
                }
                if (isset($blocked[$neighbor])) {
                    continue;
                }
                if (!isset($this->systems[$neighbor])) {
                    continue;
                }
                $visited[$neighbor] = true;
                $prev[$neighbor] = $current;
                if (isset($allowedCore[$neighbor])) {
                    $pathNodes = [$neighbor => true];
                    $cursor = $neighbor;
                    $hops = 0;
                    while (isset($prev[$cursor])) {
                        $cursor = $prev[$cursor];
                        $pathNodes[$cursor] = true;
                        $hops++;
                        if ($cursor === $startId) {
                            break;
                        }
                    }
                    return ['found' => true, 'nodes' => $pathNodes, 'hops' => $hops];
                }
                $queue->enqueue($neighbor);
            }
        }

        return ['found' => false, 'nodes' => [], 'hops' => 0];
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private function buildGateNeighbors(): array
    {
        $neighbors = [];
        foreach ($this->gateNeighbors as $from => $toList) {
            if (!isset($this->systems[$from])) {
                continue;
            }
            foreach ($toList as $to) {
                if (!isset($this->systems[$to])) {
                    continue;
                }
                $neighbors[$from][] = [
                    'to' => $to,
                    'type' => 'gate',
                ];
            }
            if (!isset($neighbors[$from])) {
                $neighbors[$from] = [];
            }
        }
        return $neighbors;
    }

    private function riskScore(int $systemId): float
    {
        $risk = $this->risk[$systemId] ?? [];
        return $this->riskScorer->penalty($risk);
    }

    private function riskWeight(array $options): float
    {
        $safety = max(0, min(100, (int) ($options['safety_vs_speed'] ?? 50)));
        return 0.2 + ($safety / 100) * 0.8;
    }

    /** @param array<int, mixed> $edges */
    private function buildSegments(array $path, array $edges, string $shipType): array
    {
        $segments = [];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $from = (int) $path[$i];
            $to = (int) $path[$i + 1];
            $edge = $edges[$i] ?? ['type' => 'gate'];
            $segments[] = [
                'from_id' => $from,
                'from' => $this->systems[$from]['name'] ?? (string) $from,
                'to_id' => $to,
                'to' => $this->systems[$to]['name'] ?? (string) $to,
                'type' => $edge['type'] ?? 'gate',
                'distance_ly' => $edge['distance_ly'] ?? null,
            ];
        }
        return $segments;
    }

    private function validateRoute(array $segments, string $shipType, ?float $effectiveRange): bool
    {
        if ($segments === []) {
            return true;
        }
        foreach ($segments as $index => $segment) {
            $toId = $segment['to_id'];
            $system = $this->systems[$toId] ?? null;
            if ($system === null) {
                return false;
            }
            $isMidpoint = $index < count($segments) - 1;
            if (!$this->shipRules->isSystemAllowed($shipType, $system, $isMidpoint)) {
                return false;
            }
            if (($segment['type'] ?? 'gate') === 'jump' && $effectiveRange !== null) {
                $distance = (float) ($segment['distance_ly'] ?? 0.0);
                if ($distance > $effectiveRange + 0.0001) {
                    return false;
                }
            }
        }
        return true;
    }

    private function summarizeRoute(array $segments, float $distance): array
    {
        $systems = [];
        if ($segments !== []) {
            $startId = $segments[0]['from_id'] ?? null;
            if ($startId !== null && isset($this->systems[$startId])) {
                $systems[] = $this->systemSummary($startId);
            }
        }
        $totalJumpLy = 0.0;
        $gateHops = 0;
        foreach ($segments as $segment) {
            $toId = $segment['to_id'];
            $system = $this->systems[$toId] ?? null;
            if ($system) {
                $systems[] = $this->systemSummary($toId);
            }
            if (($segment['type'] ?? 'gate') === 'jump') {
                $totalJumpLy += (float) ($segment['distance_ly'] ?? 0.0);
            } else {
                $gateHops++;
            }
        }
        $lowsecCount = 0;
        $nullsecCount = 0;
        $npcStationsInRoute = 0;
        foreach ($systems as $system) {
            $security = (float) ($system['security'] ?? 0.0);
            if ($security >= 0.1 && $security < 0.5) {
                $lowsecCount++;
            } elseif ($security < 0.1) {
                $nullsecCount++;
            }
            if (!empty($system['has_npc_station'])) {
                $npcStationsInRoute++;
            }
        }
        $systemCount = count($systems);
        $npcStationRatio = $systemCount > 0 ? round($npcStationsInRoute / $systemCount, 3) : 0.0;
        return [
            'feasible' => true,
            'total_cost' => round($distance, 2),
            'total_gates' => $gateHops,
            'total_jump_ly' => round($totalJumpLy, 2),
            'segments' => $segments,
            'systems' => $systems,
            'lowsec_count' => $lowsecCount,
            'nullsec_count' => $nullsecCount,
            'npc_stations_in_route' => $npcStationsInRoute,
            'npc_station_ratio' => $npcStationRatio,
        ];
    }

    private function systemSummary(int $systemId): array
    {
        $system = $this->systems[$systemId] ?? [];

        return [
            'id' => $systemId,
            'name' => $system['name'] ?? (string) $systemId,
            'security' => (float) ($system['security'] ?? 0.0),
            'security_raw' => array_key_exists('security_raw', $system) ? (float) $system['security_raw'] : null,
            'security_nav' => array_key_exists('security_nav', $system) ? (float) $system['security_nav'] : null,
            'has_npc_station' => isset($system['has_npc_station']) ? (bool) $system['has_npc_station'] : null,
        ];
    }

    /** @return array<int, array{distance_ly: float|int}> */
    private function jumpSegments(array $segments): array
    {
        $jumpSegments = [];
        foreach ($segments as $segment) {
            if (($segment['type'] ?? 'gate') !== 'jump') {
                continue;
            }
            $jumpSegments[] = ['distance_ly' => (float) ($segment['distance_ly'] ?? 0.0)];
        }
        return $jumpSegments;
    }

    private function selectBest(array $gate, array $jump, array $hybrid): string
    {
        $candidates = [];
        foreach (['gate' => $gate, 'jump' => $jump, 'hybrid' => $hybrid] as $key => $route) {
            if (!empty($route['feasible'])) {
                $candidates[$key] = (float) ($route['total_cost'] ?? INF);
            }
        }
        if ($candidates === []) {
            return 'none';
        }
        asort($candidates);
        return (string) array_key_first($candidates);
    }

    private function buildExplanation(string $best, array $gate, array $jump, array $hybrid, array $options): array
    {
        if ($best === 'none') {
            return ['No feasible routes found.'];
        }
        $reasons = [];
        $reasons[] = sprintf('Selected %s with lowest total cost.', $best);
        if ($best === 'hybrid') {
            $reasons[] = 'Hybrid combines gates and jumps while respecting ship restrictions.';
        }
        if ($best === 'jump') {
            $reasons[] = 'Jump-only route minimizes gate usage for capital movement.';
        }
        if ($best === 'gate') {
            $reasons[] = 'Gate-only route avoids jump fatigue considerations.';
        }
        if (!empty($options['prefer_npc'])) {
            $selected = match ($best) {
                'gate' => $gate,
                'jump' => $jump,
                'hybrid' => $hybrid,
                default => [],
            };
            $npcCount = (int) ($selected['npc_stations_in_route'] ?? 0);
            if ($npcCount > 0) {
                $reasons[] = sprintf('Selected %d systems with NPC stations (toggle enabled).', $npcCount);
            }
        }
        return $reasons;
    }

    private function loadData(): void
    {
        GraphStore::load($this->systemsRepo, $this->stargatesRepo, $this->logger);
        $this->systems = GraphStore::systems();
        $this->gateNeighbors = GraphStore::gateNeighbors();

        $riskRows = $this->riskRepo->getHeatmap();
        $this->risk = [];
        foreach ($riskRows as $row) {
            $this->risk[(int) $row['system_id']] = $row;
        }
    }
}
