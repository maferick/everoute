<?php

declare(strict_types=1);

namespace Everoute\Routing;

if (!class_exists(SecurityNav::class)) {
    require_once __DIR__ . '/SecurityNav.php';
}
if (!class_exists(PreferenceProfile::class)) {
    require_once __DIR__ . '/PreferenceProfile.php';
}

use Everoute\Config\Env;
use Everoute\Risk\RiskRepository;
use Everoute\Risk\RiskScorer;
use Everoute\Security\Logger;
use Everoute\Universe\ConstellationGraphRepository;
use Everoute\Universe\JumpNeighborRepository;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;

final class NavigationEngine
{
    private const SYSTEM_LEGAL = 'LEGAL';
    private const SYSTEM_ILLEGAL_SOFT = 'ILLEGAL_SOFT';
    private const SYSTEM_ILLEGAL_HARD = 'ILLEGAL_HARD';

    /** @var array<int, array<string, mixed>> */
    private array $systems = [];
    /** @var array<int, array<string, mixed>> */
    private array $risk = [];
    /** @var array<int, int[]> */
    private array $gateNeighbors = [];
    /** @var array<int, array<int, array{to: int, is_regional_gate: bool}>> */
    private array $adjacency = [];
    /** @var array<int, array<int, array{to: int, is_regional_gate: bool}>> */
    private array $reverseAdjacency = [];
    /**
     * @var array<int, array{
     *     risk_penalty: float,
     *     security: float,
     *     security_penalty: float,
     *     security_class: string,
     *     has_npc_station: bool,
     *     npc_station_count: int
     * }>
     */
    private array $baseCostProfiles = [];
    /** @var array<int, array<int, array{to_constellation_id:int,from_system_id:int,to_system_id:int,is_region_boundary:bool}>> */
    private array $constellationEdges = [];
    /** @var array<int, array<int, array{system_id:int,has_region_boundary:bool}>> */
    private array $constellationPortals = [];
    /** @var array<int, array<int, int>> */
    private array $constellationDistances = [];
    /** @var array<int, array<int, array<int, array{to_constellation_id:int,example_from_system_id:int,example_to_system_id:int,min_hop_ly:float}>>> */
    private array $jumpConstellationEdgesByRange = [];
    /** @var array<int, array<int, int[]>> */
    private array $jumpConstellationPortalsByRange = [];
    /** @var array<int, array<int, int[]>> */
    private array $jumpMidpointsByRange = [];
    /** @var array<string, mixed> */
    private array $lastHybridCandidateDebug = [];
    /** @var array<string, mixed> */
    private array $lastJumpNeighborDebug = [];
    /** @var array<string, mixed> */
    private array $lastJumpConnectivityDebug = [];
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
        private Logger $logger,
        private ?ConstellationGraphRepository $constellationGraphRepo = null
    ) {
        $this->riskScorer = new RiskScorer();
        $this->constellationGraphRepo ??= new ConstellationGraphRepository($this->systemsRepo->connection());
        $this->loadData();
    }

    public function refresh(): void
    {
        GraphStore::refresh($this->systemsRepo, $this->stargatesRepo, $this->logger);
        $this->loadData();
    }

    public function compute(array|RouteRequest $request): array
    {
        $routeRequest = $request instanceof RouteRequest ? $request : RouteRequest::fromLegacyOptions($request);
        $options = $routeRequest->toLegacyOptions();

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
            $gateRoute,
            $jumpRoute,
            $options
        );

        $weights = $this->scoringWeights($options);
        $gateRoute = $this->applyRouteScoring($gateRoute, $weights, $options);
        $jumpRoute = $this->applyRouteScoring($jumpRoute, $weights, $options);
        $hybridRoute = $this->applyRouteScoring($hybridRoute, $weights, $options);

        $selection = $this->selectBestWithMetadata($gateRoute, $jumpRoute, $hybridRoute, $options);
        $gateRoute = $this->withRouteDiagnostics($gateRoute, 'gate', $weights, $selection);
        $jumpRoute = $this->withRouteDiagnostics($jumpRoute, 'jump', $weights, $selection);
        $hybridRoute = $this->withRouteDiagnostics($hybridRoute, 'hybrid', $weights, $selection);
        $best = (string) ($selection['best'] ?? 'none');
        $bestSelectionReason = (string) ($selection['reason'] ?? 'no_feasible_routes');
        $explanation = $this->buildExplanation($best, $gateRoute, $jumpRoute, $hybridRoute, $options, $bestSelectionReason);
        $selectedRoute = match ($best) {
            'gate' => $gateRoute,
            'jump' => $jumpRoute,
            'hybrid' => $hybridRoute,
            default => [],
        };

        $payload = [
            'gate_route' => $gateRoute,
            'jump_route' => $jumpRoute,
            'hybrid_route' => $hybridRoute,
            'best' => $best,
            'best_selection_reason' => $bestSelectionReason,
            'dominance_rule_applied' => !empty($selection['dominance_rule_applied']),
            'extra_gate_penalty' => $selection['extra_gate_penalty'] ?? [],
            'fallback_warning' => !empty($selectedRoute['fallback_warning']),
            'fallback_message' => $selectedRoute['fallback_message'] ?? null,
            'chosen_route_lowsec_count' => (int) ($selectedRoute['lowsec_count'] ?? 0),
            'chosen_route_nullsec_count' => (int) ($selectedRoute['nullsec_count'] ?? 0),
            'explanation' => $explanation,
            'effective_range_ly' => $effectiveRange,
            'scoring' => $weights,
            'weights_used' => $weights,
            'fuel_per_ly_factor' => (float) ($options['fuel_per_ly_factor'] ?? 0.0),
            'jump_fuel_weight' => (float) ($options['jump_fuel_weight'] ?? 0.0),
            'preference_profile' => (string) ($options['preference_profile'] ?? PreferenceProfile::BALANCED),
            'profile_coefficients' => PreferenceProfile::coefficients((string) ($options['preference_profile'] ?? PreferenceProfile::BALANCED)),
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
                'fatigue_model_version' => JumpFatigueModel::VERSION,
                'gate_nodes_explored' => $gateRoute['nodes_explored'] ?? 0,
                'jump_nodes_explored' => $jumpRoute['nodes_explored'] ?? 0,
                'hybrid_nodes_explored' => $hybridRoute['nodes_explored'] ?? 0,
                'illegal_systems_filtered' => [
                    'gate' => $gateRoute['illegal_systems_filtered'] ?? 0,
                    'jump' => $jumpRoute['illegal_systems_filtered'] ?? 0,
                    'hybrid' => $hybridRoute['illegal_systems_filtered'] ?? 0,
                ],
                'best_selection_reason' => $bestSelectionReason,
                'dominance_rule_applied' => !empty($selection['dominance_rule_applied']),
                'extra_gate_penalty' => $selection['extra_gate_penalty'] ?? [],
                'scoring' => $weights,
                'weights_used' => $weights,
                'fuel_per_ly_factor' => (float) ($options['fuel_per_ly_factor'] ?? 0.0),
                'jump_fuel_weight' => (float) ($options['jump_fuel_weight'] ?? 0.0),
                'route_dominance_flags' => [
                    'gate' => [
                        'selected_as_best' => $best === 'gate',
                        'dominance_rule_winner' => $best === 'gate' && !empty($selection['dominance_rule_applied']),
                    ],
                    'jump' => [
                        'selected_as_best' => $best === 'jump',
                        'dominance_rule_winner' => $best === 'jump' && !empty($selection['dominance_rule_applied']),
                    ],
                    'hybrid' => [
                        'selected_as_best' => $best === 'hybrid',
                        'dominance_rule_winner' => $best === 'hybrid' && !empty($selection['dominance_rule_applied']),
                    ],
                ],
                'hybrid_launch_candidates' => (int) ($hybridRoute['hybrid_launch_candidates'] ?? 0),
                'hybrid_gate_budget_configured' => (int) ($hybridRoute['hybrid_gate_budget_configured'] ?? 0),
                'hybrid_gate_budget_applied' => (int) ($hybridRoute['hybrid_gate_budget_applied'] ?? 0),
                'hybrid_gate_budget_used_depth' => (int) ($hybridRoute['hybrid_gate_budget_used_depth'] ?? 0),
                'hybrid_top_launch_candidates' => $hybridRoute['hybrid_top_launch_candidates'] ?? [],
                'hybrid_candidate_debug' => $hybridRoute['hybrid_candidate_debug'] ?? $this->lastHybridCandidateDebug,
                'jump_neighbor_debug' => $jumpRoute['jump_neighbor_debug'] ?? $this->lastJumpNeighborDebug,
                'jump_connectivity' => $jumpRoute['jump_connectivity'] ?? $this->lastJumpConnectivityDebug,
                'distance_checks_ly' => $this->distanceCheckMetrics(),
            ];
            if (isset($jumpRoute['debug']) && is_array($jumpRoute['debug'])) {
                $payload['debug']['jump_origin'] = $jumpRoute['debug'];
            }
        }

        return $payload;
    }


    /** @return array<string, mixed> */
    private function distanceCheckMetrics(): array
    {
        $pairs = [
            ['1-SMEB', 'Irmalin'],
            ['Irmalin', 'Rilera'],
            ['Rilera', 'Liparer'],
            ['Liparer', 'Noranim'],
            ['Noranim', 'Ahbazon'],
            ['Ahbazon', 'Fasse'],
            ['Fasse', 'Amamake'],
        ];
        $result = [];
        foreach ($pairs as [$fromName, $toName]) {
            $from = $this->systemLookup->resolveByNameOrId($fromName);
            $to = $this->systemLookup->resolveByNameOrId($toName);
            $key = $fromName . '->' . $toName;
            if ($from === null || $to === null) {
                $result[$key] = ['available' => false, 'distance_ly' => null];
                continue;
            }
            $distance = JumpMath::distanceLy($from, $to);
            $result[$key] = [
                'available' => true,
                'distance_ly' => round($distance, 6),
                'within_7_ly' => $distance <= 7.0,
            ];
        }
        return $result;
    }


    /** @param array<int, int[]> $precomputed @param array<int, array<int, array{to:int,type:string,distance_ly:float}>> $generated */
    private function dotlanWaypointEdgeDiagnostics(array $precomputed, array $generated, string $shipType, array $options, ?array $allowedSystems, float $effectiveRange): array
    {
        $pairs = [
            ['from' => '1-SMEB', 'to' => 'Irmalin'],
            ['from' => 'Irmalin', 'to' => 'Rilera'],
            ['from' => 'Rilera', 'to' => 'Liparer'],
            ['from' => 'Liparer', 'to' => 'Noranim'],
            ['from' => 'Noranim', 'to' => 'Ahbazon'],
            ['from' => 'Ahbazon', 'to' => 'Fasse'],
            ['from' => 'Fasse', 'to' => 'Amamake'],
        ];

        $results = [];
        foreach ($pairs as $pair) {
            $from = $this->systemLookup->resolveByNameOrId($pair['from']);
            $to = $this->systemLookup->resolveByNameOrId($pair['to']);
            $key = $pair['from'] . '->' . $pair['to'];
            if ($from === null || $to === null) {
                $results[$key] = [
                    'distance_ly' => null,
                    'within_range' => null,
                    'was_edge_generated' => false,
                    'reason' => 'system_resolution_failed',
                ];
                continue;
            }

            $fromId = (int) ($from['id'] ?? 0);
            $toId = (int) ($to['id'] ?? 0);
            if ($fromId === 0 || $toId === 0 || !isset($this->systems[$fromId]) || !isset($this->systems[$toId])) {
                $results[$key] = [
                    'distance_ly' => null,
                    'within_range' => null,
                    'was_edge_generated' => false,
                    'reason' => 'system_resolution_failed',
                ];
                continue;
            }
            if (!$this->hasCoordinates($this->systems[$fromId]) || !$this->hasCoordinates($this->systems[$toId])) {
                $results[$key] = [
                    'distance_ly' => null,
                    'within_range' => null,
                    'was_edge_generated' => false,
                    'reason' => 'missing_coordinates',
                ];
                continue;
            }

            $distance = JumpMath::distanceLy($this->systems[$fromId], $this->systems[$toId]);
            $withinRange = $distance <= $effectiveRange;
            $inPrecomputed = in_array($toId, $precomputed[$fromId] ?? [], true);
            $generatedEdge = false;
            foreach ($generated[$fromId] ?? [] as $edge) {
                if ((int) ($edge['to'] ?? 0) === $toId) {
                    $generatedEdge = true;
                    break;
                }
            }

            $reason = 'generated';
            if (!$withinRange) {
                $reason = 'out_of_range';
            } elseif (!$inPrecomputed) {
                $reason = 'bucket_query_miss';
            } elseif ($allowedSystems !== null && (!isset($allowedSystems[$fromId]) || !isset($allowedSystems[$toId]))) {
                $reason = 'bounded_candidate_filter';
            } elseif (!$generatedEdge) {
                $fromReason = $this->jumpPolicyFilterReason($shipType, $this->systems[$fromId], false, $options);
                $toReason = $this->jumpPolicyFilterReason($shipType, $this->systems[$toId], false, $options);
                $reason = $fromReason ?? $toReason ?? 'filtered_internal_error';
            }

            $results[$key] = [
                'distance_ly' => round($distance, 6),
                'within_range' => $withinRange,
                'was_edge_generated' => $generatedEdge,
                'reason' => $reason,
            ];
        }

        return $results;
    }

    private function hasCoordinates(array $system): bool
    {
        return isset($system['x'], $system['y'], $system['z'])
            && is_numeric($system['x'])
            && is_numeric($system['y'])
            && is_numeric($system['z']);
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

    private function withResolvedFuelOptions(array $options, string $shipType): array
    {
        $inputFactor = (array_key_exists('fuel_per_ly_factor', $options) && is_numeric($options['fuel_per_ly_factor']))
            ? (float) $options['fuel_per_ly_factor']
            : null;
        $profileFactor = $shipType !== '' ? $this->jumpRangeCalculator->fuelPerLyFactor($shipType) : null;

        $factor = $inputFactor ?? $profileFactor ?? 0.0;
        $options['fuel_per_ly_factor'] = max(0.0, $factor);

        if (array_key_exists('jump_fuel_weight', $options)) {
            $options['jump_fuel_weight'] = max(0.0, (float) $options['jump_fuel_weight']);
        } else {
            $options['jump_fuel_weight'] = $this->defaultJumpFuelWeight($options);
        }

        return $options;
    }

    private function withResolvedPreferenceProfile(array $options): array
    {
        $safety = max(0, min(100, (int) ($options['safety_vs_speed'] ?? 50)));
        $requested = array_key_exists('preference_profile', $options) ? (string) $options['preference_profile'] : null;
        $options['preference_profile'] = PreferenceProfile::resolve($requested, $safety);

        return $options;
    }

    private function defaultJumpFuelWeight(array $options): float
    {
        $mode = (string) ($options['mode'] ?? 'subcap');

        return $mode === 'capital' ? 1.0 : 0.6;
    }

    private function jumpFuelEdgeCost(float $distanceLy, array $options): float
    {
        $factor = max(0.0, (float) ($options['fuel_per_ly_factor'] ?? 0.0));
        $weight = max(0.0, (float) ($options['jump_fuel_weight'] ?? $this->defaultJumpFuelWeight($options)));

        return $distanceLy * $factor * $weight;
    }

    /** @return array{base_gate_cost: float, base_jump_cost: float, risk_multiplier: float, sec_band_penalty_multiplier: float, fuel_multiplier: float, per_jump_constant: float} */
    private function profileCoefficients(array $options): array
    {
        return PreferenceProfile::coefficients((string) ($options['preference_profile'] ?? PreferenceProfile::BALANCED));
    }

    private function gateEdgeCost(array $profile, string $preference, array $options, bool $includeRisk = false): float
    {
        $coefficients = $this->profileCoefficients($options);
        $cost = $this->gateStepCost($preference, $profile)
            + $this->npcStationStepBonus($profile, $options)
            + $this->avoidPenalty($profile, $options)
            + (float) ($coefficients['base_gate_cost'] ?? 0.0);

        if ($includeRisk) {
            $cost += ((float) ($profile['risk_penalty'] ?? 0.0)) * $this->riskWeight($options);
        }

        return $cost;
    }

    private function jumpEdgeCost(float $distance, string $shipType, array $profile, array $options): float
    {
        $coefficients = $this->profileCoefficients($options);
        $metrics = $this->fatigueModel->lookupHopMetricsForShipType($shipType, $distance);
        $fatigue = (float) ($metrics['jump_fatigue_minutes'] ?? 0.0);
        $cooldown = (float) ($metrics['jump_activation_minutes'] ?? 0.0);
        $riskCost = ((float) ($profile['risk_penalty'] ?? 0.0)) * $this->riskWeight($options);
        $secBandCost = ((float) ($profile['security_penalty'] ?? 0.0) / 100.0) * (float) ($coefficients['sec_band_penalty_multiplier'] ?? 0.0);
        $fuelCost = $this->jumpFuelEdgeCost($distance, $options) * (float) ($coefficients['fuel_multiplier'] ?? 1.0);

        return (float) ($coefficients['base_jump_cost'] ?? 0.0)
            + $distance
            + $fatigue
            + $cooldown
            + $fuelCost
            + $riskCost
            + $secBandCost
            + (float) ($coefficients['per_jump_constant'] ?? 0.0)
            + $this->npcStationStepBonus($profile, $options)
            + $this->avoidPenalty($profile, $options);
    }

    private function computeGateRoute(int $startId, int $endId, string $shipType, array $options): array
    {
        $attemptOptions = $this->optionsForPrimaryAvoidAttempt($options);
        $route = $this->computeGateRouteAttempt($startId, $endId, $shipType, $attemptOptions);
        $fallbackUsed = false;
        if ($this->shouldAttemptFallback($route, $attemptOptions)) {
            $relaxedOptions = $this->relaxAvoidOptions($attemptOptions);
            $route = $this->computeGateRouteAttempt($startId, $endId, $shipType, $relaxedOptions);
            $fallbackUsed = true;
        }
        return $this->withRouteMeta($route, $fallbackUsed, $options, $attemptOptions);
    }

    private function computeGateRouteAttempt(int $startId, int $endId, string $shipType, array $options): array
    {
        $preference = $this->normalizeGatePreference($options);
        $hierarchicalRoute = $this->computeHierarchicalGateRoute($startId, $endId, $shipType, $options, $preference);
        if ($hierarchicalRoute !== null) {
            return $hierarchicalRoute;
        }

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
        $result = $dijkstra->shortestPath(
            $neighbors,
            $startId,
            $endId,
            function (int $from, int $to) use ($useSubcapPolicy, $preference, $options): float {
                $profile = $this->baseCostProfiles[$to] ?? null;
                if ($profile === null) {
                    return INF;
                }
                return $this->gateEdgeCost($profile, $preference, $options, !$useSubcapPolicy);
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
        $summary['total_fuel'] = $this->routeFuelTotal($summary, $options);
        $summary['avoid_flags'] = $this->buildAvoidFlags($options);
        $summary['exception_corridor'] = $policy['exception'];
        return $summary;
    }

    private function computeJumpRoute(
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        ?int $rangeBucket,
        array $options
    ): array {
        $attemptOptions = $this->optionsForPrimaryAvoidAttempt($options);
        $route = $this->computeJumpRouteAttempt($startId, $endId, $shipType, $effectiveRange, $rangeBucket, $attemptOptions);
        $fallbackUsed = false;
        if ($this->shouldAttemptFallback($route, $attemptOptions)) {
            $relaxedOptions = $this->relaxAvoidOptions($attemptOptions);
            $route = $this->computeJumpRouteAttempt($startId, $endId, $shipType, $effectiveRange, $rangeBucket, $relaxedOptions);
            $fallbackUsed = true;
        }
        return $this->withRouteMeta($route, $fallbackUsed, $options, $attemptOptions);
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

        $boundedCandidates = $this->boundedJumpCandidateSet($startId, $endId, $rangeBucket);
        $graph = $this->buildJumpGraph($neighbors, $startId, $endId, $shipType, $options, $debugLogs, $effectiveRange, $boundedCandidates);
        $this->lastJumpNeighborDebug = $graph['debug'] ?? [];
        $dotlanEdgeChecks = $this->dotlanWaypointEdgeDiagnostics($neighbors, $graph['neighbors'], $shipType, $options, $boundedCandidates, $effectiveRange);
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
        $costFn = function (int $from, int $to, mixed $edgeData) use ($options, $shipType): float {
            $profile = $this->baseCostProfiles[$to] ?? null;
            if ($profile === null) {
                return INF;
            }
            $distance = (float) ($edgeData['distance_ly'] ?? 0.0);
            return $this->jumpEdgeCost($distance, $shipType, $profile, $options);
        };
        $result = $this->findJumpPathByDominance($graph['neighbors'], $startId, $endId, $options);

        $connectivity = $this->traceJumpGraphConnectivity($graph['neighbors'], $startId, $endId, 10);
        $connectivity['dotlan_waypoint_edge_checks'] = $dotlanEdgeChecks;
        $this->lastJumpConnectivityDebug = $connectivity;

        if ($result['path'] === [] || ($result['path'][count($result['path']) - 1] ?? null) !== $endId) {
            if ($debugLogs) {
                $this->runJumpDiagnostics($startId, $endId, $shipType, $options, $rangeBucket);
            }
            return [
                'feasible' => false,
                'reason' => 'No jump route found.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $graph['filtered'],
                'min_hops_found' => $result['min_hops_found'] ?? null,
                'debug' => $originDiagnostics,
                'jump_neighbor_debug' => $graph['debug'] ?? [],
                'jump_connectivity' => $connectivity,
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

        $summary = $this->buildJumpRouteSummary($segments, (float) $result['distance'], (int) $result['nodes_explored'], (int) $graph['filtered'], $options, $endId);
        $summary['min_hops_found'] = $result['min_hops_found'] ?? null;

        $npcFallbackDebug = [
            'triggered' => false,
            'reason' => 'not_needed',
            'baseline_npc_coverage' => (float) ($summary['npc_station_ratio'] ?? 0.0),
        ];
        if (!empty($options['prefer_npc'])) {
            $coverage = (float) ($summary['npc_station_ratio'] ?? 0.0);
            $coverageThreshold = $this->npcFallbackCoverageThreshold($options);
            if ($coverage < $coverageThreshold) {
                $budget = $this->npcFallbackBudget($options);
                $npcFallbackDebug = [
                    'triggered' => true,
                    'reason' => 'low_npc_coverage',
                    'baseline_npc_coverage' => round($coverage, 3),
                    'required_npc_coverage' => round($coverageThreshold, 3),
                    'budget' => $budget,
                ];
                $detour = $this->attemptJumpNpcFallbackDetour(
                    $dijkstra,
                    $graph['neighbors'],
                    $startId,
                    $endId,
                    $shipType,
                    $effectiveRange,
                    $summary,
                    $costFn,
                    $budget,
                    $options,
                    $npcFallbackDebug
                );
                if ($detour !== null) {
                    $summary = $detour;
                    $npcFallbackDebug['accepted'] = true;
                }
            }
        }

        $summary['debug'] = $originDiagnostics;
        $summary['debug']['npc_fallback'] = $npcFallbackDebug;
        $summary['jump_neighbor_debug'] = $graph['debug'] ?? [];
        $summary['jump_connectivity'] = $connectivity;
        return $summary;
    }

    private function buildJumpRouteSummary(array $segments, float $distance, int $nodesExplored, int $filtered, array $options, int $endId): array
    {
        $summary = $this->summarizeRoute($segments, $distance);
        $summary['nodes_explored'] = $nodesExplored;
        $summary['illegal_systems_filtered'] = $filtered;
        $summary['total_fuel'] = $this->routeFuelTotal($summary, $options);
        $summary['fatigue'] = $this->fatigueModel->evaluate($this->jumpSegments($segments), $options);
        $summary += $this->buildJumpWaitDetails($segments, $options);
        $summary += $this->jumpMidpointStationDiagnostics($segments, $options, $endId);
        $summary['segments'] = $this->annotateStationViolationsOnSegments($summary['segments'] ?? [], $options, $endId);
        return $summary;
    }


    /** @param array<int, array<int, array{to:int,type:string,distance_ly:float}>> $neighbors */
    private function findJumpPathByDominance(array $neighbors, int $startId, int $endId, array $options): array
    {
        $queue = new \SplPriorityQueue();
        $queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);

        $state = [
            $startId => [
                'hops' => 0,
                'distance' => 0.0,
                'max_hop' => 0.0,
                'risk' => 0.0,
                'station_violations' => 0,
                'soft_cost' => 0.0,
                'prev' => null,
            ],
        ];
        $queue->insert($startId, [0, 0.0, 0.0, 0.0]);

        $nodesExplored = 0;
        while (!$queue->isEmpty()) {
            $node = (int) $queue->extract();
            $current = $state[$node] ?? null;
            if ($current === null) {
                continue;
            }
            $nodesExplored++;

            foreach ($neighbors[$node] ?? [] as $edge) {
                $to = (int) ($edge['to'] ?? 0);
                if ($to === 0) {
                    continue;
                }
                $edgeDistance = (float) ($edge['distance_ly'] ?? 0.0);
                $profile = $this->baseCostProfiles[$to] ?? null;
                $riskPenalty = (float) ($profile['risk_penalty'] ?? 0.0);
                $stationViolation = $this->stationMidpointViolationPenalty($to, $endId, $options);
                $candidate = [
                    'hops' => (int) $current['hops'] + 1,
                    'distance' => (float) $current['distance'] + $edgeDistance + $stationViolation['penalty_hops'],
                    'max_hop' => max((float) $current['max_hop'], $edgeDistance),
                    'risk' => (float) $current['risk'] + $riskPenalty,
                    'station_violations' => (int) $current['station_violations'] + ($stationViolation['is_violation'] ? 1 : 0),
                    'soft_cost' => (float) $current['soft_cost'] + 1.0 + $stationViolation['penalty_hops'] + ($edgeDistance * 0.01) + ($riskPenalty * 0.05),
                    'prev' => $node,
                ];

                $existing = $state[$to] ?? null;
                if ($existing !== null && !$this->isJumpCandidateBetter($candidate, $existing, $options)) {
                    continue;
                }

                $state[$to] = $candidate;
                $queue->insert(
                    $to,
                    [
                        -((int) $candidate['hops']),
                        -((float) $candidate['distance']),
                        -((float) $candidate['max_hop']),
                        -((float) $candidate['risk']),
                        -((int) $candidate['station_violations']),
                    ]
                );
            }
        }

        if (!isset($state[$endId])) {
            return ['path' => [], 'edges' => [], 'distance' => INF, 'nodes_explored' => $nodesExplored, 'min_hops_found' => null];
        }

        $path = [];
        $cursor = $endId;
        while ($cursor !== null) {
            array_unshift($path, $cursor);
            $cursor = $state[$cursor]['prev'];
        }

        $edges = [];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $from = (int) $path[$i];
            $to = (int) $path[$i + 1];
            $distance = 0.0;
            foreach ($neighbors[$from] ?? [] as $edge) {
                if ((int) ($edge['to'] ?? 0) === $to) {
                    $distance = (float) ($edge['distance_ly'] ?? 0.0);
                    break;
                }
            }
            $edges[] = ['to' => $to, 'type' => 'jump', 'distance_ly' => $distance];
        }

        return [
            'path' => $path,
            'edges' => $edges,
            'distance' => (float) ($state[$endId]['distance'] ?? INF),
            'nodes_explored' => $nodesExplored,
            'min_hops_found' => (int) ($state[$endId]['hops'] ?? 0),
            'station_midpoint_violations' => (int) ($state[$endId]['station_violations'] ?? 0),
        ];
    }

    /** @param array{hops:int,distance:float,max_hop:float,risk:float,station_violations:int,soft_cost:float} $candidate @param array{hops:int,distance:float,max_hop:float,risk:float,station_violations:int,soft_cost:float} $existing */
    private function isJumpCandidateBetter(array $candidate, array $existing, array $options): bool
    {
        $tolerance = 1e-9;
        $useStationSoftCost = !empty($options['require_station_midpoints'])
            && strtolower((string) ($options['avoid_strictness'] ?? 'soft')) === 'soft';

        if ($useStationSoftCost) {
            if (abs((float) $candidate['soft_cost'] - (float) $existing['soft_cost']) > $tolerance) {
                return (float) $candidate['soft_cost'] < (float) $existing['soft_cost'];
            }
        }

        if ((int) $candidate['hops'] !== (int) $existing['hops']) {
            return (int) $candidate['hops'] < (int) $existing['hops'];
        }
        if ((int) $candidate['station_violations'] !== (int) $existing['station_violations']) {
            return (int) $candidate['station_violations'] < (int) $existing['station_violations'];
        }
        if (abs((float) $candidate['distance'] - (float) $existing['distance']) > $tolerance) {
            return (float) $candidate['distance'] < (float) $existing['distance'];
        }
        if (abs((float) $candidate['max_hop'] - (float) $existing['max_hop']) > $tolerance) {
            return (float) $candidate['max_hop'] < (float) $existing['max_hop'];
        }

        return (float) $candidate['risk'] < (float) $existing['risk'] - $tolerance;
    }

    private function npcFallbackCoverageThreshold(array $options): float
    {
        return max(0.0, min(1.0, (float) ($options['npc_fallback_min_coverage'] ?? 0.35)));
    }

    /** @return array{max_extra_jumps: int, max_extra_ly: float, max_relative_time_increase: float} */
    private function npcFallbackBudget(array $options): array
    {
        $safety = max(0, min(100, (int) ($options['safety_vs_speed'] ?? 50)));
        $defaultExtraLy = $safety >= 65 ? 8.0 : 0.0;
        $defaultRelative = $safety >= 65 ? 0.18 : 0.0;

        return [
            'max_extra_jumps' => max(0, (int) ($options['npc_fallback_max_extra_jumps'] ?? $this->npcDetourHopBudget($options))),
            'max_extra_ly' => max(0.0, (float) ($options['npc_fallback_max_extra_ly'] ?? $defaultExtraLy)),
            'max_relative_time_increase' => max(0.0, (float) ($options['npc_fallback_max_relative_time_increase'] ?? $defaultRelative)),
        ];
    }

    /**
     * @param array<int, array<int, array{to: int, type: string, distance_ly: float}>> $neighbors
     * @param callable(int,int,mixed):float $costFn
     * @param array{max_extra_jumps: int, max_extra_ly: float, max_relative_time_increase: float} $budget
     * @param array<string, mixed> $debug
     */
    private function attemptJumpNpcFallbackDetour(
        Dijkstra $dijkstra,
        array $neighbors,
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        array $baseline,
        callable $costFn,
        array $budget,
        array $options,
        array &$debug
    ): ?array {
        if (($budget['max_extra_jumps'] ?? 0) < 1) {
            $debug['reason'] = 'budget_disallows_extra_jumps';
            return null;
        }

        $candidateIds = $this->npcFallbackCandidates($baseline, $neighbors, $startId, $endId);
        if ($candidateIds === []) {
            $debug['reason'] = 'no_npc_candidates';
            return null;
        }

        $baselineHops = count((array) ($baseline['segments'] ?? []));
        $baselineLy = (float) ($baseline['total_jump_ly'] ?? 0.0);
        $baselineTime = $this->estimateJumpRouteTime((array) ($baseline['segments'] ?? []), (float) ($baseline['total_wait_minutes'] ?? 0.0));

        foreach ($candidateIds as $candidateId) {
            $first = $dijkstra->shortestPath($neighbors, $startId, $candidateId, $costFn, null, null, 50000);
            $second = $dijkstra->shortestPath($neighbors, $candidateId, $endId, $costFn, null, null, 50000);
            if (($first['path'] ?? []) === [] || ($second['path'] ?? []) === []) {
                continue;
            }

            $pathA = (array) $first['path'];
            $pathB = (array) $second['path'];
            $path = array_merge($pathA, array_slice($pathB, 1));
            $edges = array_merge((array) $first['edges'], (array) $second['edges']);
            $segments = $this->buildSegments($path, $edges, $shipType);
            if (!$this->validateRoute($segments, $shipType, $effectiveRange)) {
                continue;
            }

            $detour = $this->buildJumpRouteSummary(
                $segments,
                (float) $first['distance'] + (float) $second['distance'],
                (int) $first['nodes_explored'] + (int) $second['nodes_explored'],
                (int) ($baseline['illegal_systems_filtered'] ?? 0),
                $options
            );

            $extraHops = count($segments) - $baselineHops;
            $extraLy = (float) ($detour['total_jump_ly'] ?? 0.0) - $baselineLy;
            $detourTime = $this->estimateJumpRouteTime($segments, (float) ($detour['total_wait_minutes'] ?? 0.0));
            $relativeTimeIncrease = $baselineTime > 0.0 ? (($detourTime - $baselineTime) / $baselineTime) : 0.0;

            if ($extraHops > (int) $budget['max_extra_jumps']) {
                continue;
            }
            if ($extraLy > (float) $budget['max_extra_ly'] && $relativeTimeIncrease > (float) $budget['max_relative_time_increase']) {
                continue;
            }

            $debug['accepted_candidate'] = [
                'system_id' => $candidateId,
                'system_name' => $this->systems[$candidateId]['name'] ?? (string) $candidateId,
                'extra_jumps' => $extraHops,
                'extra_ly' => round($extraLy, 2),
                'relative_time_increase' => round($relativeTimeIncrease, 3),
            ];
            $detour['npc_fallback_used'] = true;
            return $detour;
        }

        $debug['reason'] = 'no_candidate_within_budget';
        return null;
    }

    /**
     * @param array<int, array<int, array{to: int, type: string, distance_ly: float}>> $neighbors
     * @return int[]
     */
    private function npcFallbackCandidates(array $baseline, array $neighbors, int $startId, int $endId): array
    {
        $baselineSystems = array_values(array_map(
            static fn (array $system): int => (int) ($system['id'] ?? 0),
            (array) ($baseline['systems'] ?? [])
        ));
        $midpointId = $baselineSystems !== []
            ? (int) $baselineSystems[(int) floor((count($baselineSystems) - 1) / 2)]
            : $startId;
        $midpoint = $this->systems[$midpointId] ?? $this->systems[$startId] ?? null;
        if ($midpoint === null) {
            return [];
        }

        $candidates = [];
        foreach ($this->systems as $systemId => $system) {
            if ($systemId === $startId || $systemId === $endId || $systemId === $midpointId) {
                continue;
            }
            if (empty($system['has_npc_station']) && ((int) ($system['npc_station_count'] ?? 0) < 1)) {
                continue;
            }
            if (!isset($neighbors[$systemId])) {
                continue;
            }
            $candidates[$systemId] = JumpMath::distanceLy($midpoint, $system);
        }
        asort($candidates);
        return array_slice(array_map('intval', array_keys($candidates)), 0, 12);
    }

    private function estimateJumpRouteTime(array $segments, float $waitMinutes): float
    {
        $distanceLy = 0.0;
        foreach ($segments as $segment) {
            $distanceLy += (float) ($segment['distance_ly'] ?? 0.0);
        }

        return $distanceLy + max(0.0, $waitMinutes) + count($segments);
    }

    private function computeHybridRoute(
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        ?int $rangeBucket,
        array $gateBaseline,
        array $jumpBaseline,
        array $options
    ): array {
        $attemptOptions = $this->optionsForPrimaryAvoidAttempt($options);
        $route = $this->computeHybridRouteAttempt(
            $startId,
            $endId,
            $shipType,
            $effectiveRange,
            $rangeBucket,
            $gateBaseline,
            $jumpBaseline,
            $attemptOptions
        );
        $fallbackUsed = false;
        if ($this->shouldAttemptFallback($route, $attemptOptions)) {
            $relaxedOptions = $this->relaxAvoidOptions($attemptOptions);
            $route = $this->computeHybridRouteAttempt(
                $startId,
                $endId,
                $shipType,
                $effectiveRange,
                $rangeBucket,
                $gateBaseline,
                $jumpBaseline,
                $relaxedOptions
            );
            $fallbackUsed = true;
        }
        $route = $this->withRouteMeta($route, $fallbackUsed, $options, $attemptOptions);
        return $this->withHybridFatigueDetails($route, $options);
    }

    private function computeHybridRouteAttempt(
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        ?int $rangeBucket,
        array $gateBaseline,
        array $jumpBaseline,
        array $options
    ): array {
        if ($this->useLegacyHybridPlanner($options)) {
            $route = $this->computeHybridRouteAttemptLegacy(
                $startId,
                $endId,
                $shipType,
                $effectiveRange,
                $rangeBucket,
                $gateBaseline,
                $jumpBaseline,
                $options
            );
            $route['planner'] = 'legacy_two_phase';
            return $route;
        }

        $route = $this->computeHybridRouteAttemptMixed(
            $startId,
            $endId,
            $shipType,
            $effectiveRange,
            $rangeBucket,
            $gateBaseline,
            $jumpBaseline,
            $options
        );
        $route['planner'] = 'mixed_graph';
        return $route;
    }

    private function computeHybridRouteAttemptMixed(
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        ?int $rangeBucket,
        array $gateBaseline,
        array $jumpBaseline,
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

        $jumpAdjacency = $this->jumpNeighborRepo->loadRangeBucket($rangeBucket, count($this->systems));
        if ($jumpAdjacency === null) {
            return [
                'feasible' => false,
                'reason' => 'Missing precomputed jump neighbors.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }

        $boundedCandidates = $this->boundedJumpCandidateSet($startId, $endId, $rangeBucket);
        $jumpGraph = $this->buildJumpGraph(
            $jumpAdjacency,
            $startId,
            $endId,
            $shipType,
            $options,
            false,
            $effectiveRange,
            $boundedCandidates
        );
        $gateGraph = $this->buildGateGraph($startId, $endId, $shipType, $options);
        $neighbors = $this->mergeGraphs($gateGraph['neighbors'], $jumpGraph['neighbors']);
        $filtered = (int) ($gateGraph['filtered'] ?? 0) + (int) ($jumpGraph['filtered'] ?? 0);

        if (!isset($neighbors[$startId]) || !isset($neighbors[$endId])) {
            return [
                'feasible' => false,
                'reason' => 'Start or destination not allowed for mixed travel.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => $filtered,
            ];
        }

        $preference = $this->normalizeGatePreference($options);
        $minHybridSelectionImprovement = max(0.0, (float) ($options['hybrid_min_selection_improvement'] ?? 0.01));
        $dijkstra = new Dijkstra();
        $result = $dijkstra->shortestPath(
            $neighbors,
            $startId,
            $endId,
            function (int $from, int $to, mixed $edgeData) use ($options, $shipType, $preference): float {
                $profile = $this->baseCostProfiles[$to] ?? null;
                if ($profile === null) {
                    return INF;
                }
                $edgeType = (string) ($edgeData['type'] ?? 'gate');
                if ($edgeType === 'jump') {
                    $distance = (float) ($edgeData['distance_ly'] ?? 0.0);
                    return $this->jumpEdgeCost($distance, $shipType, $profile, $options);
                }

                return $this->gateEdgeCost($profile, $preference, $options);
            },
            null,
            null,
            50000
        );

        if ($result['path'] === [] || ($result['path'][count($result['path']) - 1] ?? null) !== $endId) {
            return [
                'feasible' => false,
                'reason' => 'No hybrid route found.',
                'nodes_explored' => (int) ($result['nodes_explored'] ?? 0),
                'illegal_systems_filtered' => $filtered,
            ];
        }

        $segments = $this->buildSegments($result['path'], $result['edges'], $shipType);
        if (!$this->validateRoute($segments, $shipType, $effectiveRange)) {
            return [
                'feasible' => false,
                'reason' => 'Hybrid route failed validation.',
                'nodes_explored' => (int) ($result['nodes_explored'] ?? 0),
                'illegal_systems_filtered' => $filtered,
            ];
        }

        $summary = $this->summarizeRoute($segments, (float) ($result['distance'] ?? 0.0));
        $summary['nodes_explored'] = (int) ($result['nodes_explored'] ?? 0);
        $summary['illegal_systems_filtered'] = $filtered;
        $summary['fatigue'] = $this->fatigueModel->evaluate($this->jumpSegments($segments), $options);
        $summary['total_fuel'] = $this->routeFuelTotal($summary, $options);

        $hybridTimeCost = $this->routeTimeCost($summary);
        $gateTimeCost = !empty($gateBaseline['feasible']) ? $this->routeTimeCost($gateBaseline) : INF;
        $jumpTimeCost = !empty($jumpBaseline['feasible']) ? $this->routeTimeCost($jumpBaseline) : INF;
        $summary['baseline_time_costs'] = [
            'hybrid' => $hybridTimeCost,
            'gate' => $gateTimeCost,
            'jump' => $jumpTimeCost,
            'required_min_improvement' => $minHybridSelectionImprovement,
        ];

        if (is_finite($gateTimeCost) && ($gateTimeCost - $hybridTimeCost) < $minHybridSelectionImprovement) {
            return [
                'feasible' => false,
                'reason' => sprintf(
                    'Hybrid rejected: insufficient gain vs gate baseline (min %.3f time_cost).',
                    $minHybridSelectionImprovement
                ),
                'nodes_explored' => (int) ($result['nodes_explored'] ?? 0),
                'illegal_systems_filtered' => $filtered,
            ];
        }
        if (is_finite($jumpTimeCost) && ($jumpTimeCost - $hybridTimeCost) < $minHybridSelectionImprovement) {
            return [
                'feasible' => false,
                'reason' => sprintf(
                    'Hybrid rejected: insufficient gain vs jump baseline (min %.3f time_cost).',
                    $minHybridSelectionImprovement
                ),
                'nodes_explored' => (int) ($result['nodes_explored'] ?? 0),
                'illegal_systems_filtered' => $filtered,
            ];
        }

        return $summary;
    }

    private function computeHybridRouteAttemptLegacy(
        int $startId,
        int $endId,
        string $shipType,
        ?float $effectiveRange,
        ?int $rangeBucket,
        array $gateBaseline,
        array $jumpBaseline,
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
        if ($rangeBucket === null) {
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

        $allowGateReposition = !array_key_exists('allow_gate_reposition', $options) || !empty($options['allow_gate_reposition']);
        $hybridGateBudgetMax = max(0, min(12, (int) ($options['hybrid_gate_budget_max'] ?? 8)));
        $launchHopLimit = $allowGateReposition ? $hybridGateBudgetMax : 0;
        $landingHopLimit = max(0, min(10, (int) ($options['hybrid_landing_hops'] ?? 4)));
        $launchCandidateLimit = max(1, min(200, (int) ($options['hybrid_launch_candidate_limit'] ?? 50)));
        $landingCandidateLimit = max(1, min(200, (int) ($options['hybrid_landing_candidate_limit'] ?? 25)));
        $maxJumpHops = max(1, min(20, (int) ($options['hybrid_max_jump_hops'] ?? 6)));
        $maxCandidateExpansions = max(1, min(5000, (int) ($options['hybrid_max_candidate_expansions'] ?? 200)));
        $minSegmentBenefitHops = max(0, (int) ($options['hybrid_min_segment_benefit_hops'] ?? 1));
        $minHybridSelectionImprovement = max(0.0, (float) ($options['hybrid_min_selection_improvement'] ?? 0.01));

        $hopsToEnd = $this->buildGateHopMap($endId, $shipType, $options, $this->reverseAdjacency);
        $hopsFromStart = $this->buildGateHopMap($startId, $shipType, $options, $this->adjacency);
        $baselineGateHops = $hopsToEnd[$startId] ?? null;
        $boundedCandidates = $this->boundedJumpCandidateSet($startId, $endId, $rangeBucket);
        $jumpNeighbors = $this->buildHybridJumpNeighbors($neighbors, $boundedCandidates, $effectiveRange);

        $launchCandidates = $this->buildHybridLaunchCandidates(
            $startId,
            $endId,
            $shipType,
            $options,
            $launchHopLimit,
            $launchCandidateLimit,
            $hopsToEnd,
            $baselineGateHops,
            $minSegmentBenefitHops,
            $jumpNeighbors
        );
        if ($launchCandidates === []) {
            return [
                'feasible' => false,
                'reason' => 'No launch candidates within gate hop limit.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
                'hybrid_launch_candidates' => 0,
                'hybrid_gate_budget_configured' => $hybridGateBudgetMax,
                'hybrid_gate_budget_applied' => $launchHopLimit,
                'hybrid_gate_budget_used_depth' => (int) ($this->lastHybridCandidateDebug['bfs_depth_reached'] ?? 0),
                'hybrid_candidate_debug' => $this->lastHybridCandidateDebug,
            ];
        }

        $landingCandidates = $this->buildHybridLandingCandidates(
            $endId,
            $shipType,
            $options,
            $landingHopLimit,
            $hopsFromStart,
            $baselineGateHops,
            $minSegmentBenefitHops,
            $landingCandidateLimit
        );
        if ($landingCandidates === []) {
            return [
                'feasible' => false,
                'reason' => 'No landing candidates within gate hop limit.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }

        $dijkstra = new Dijkstra();
        $bestPlan = null;
        $bestCost = INF;
        $nodesExplored = 0;
        $candidateExpansions = 0;
        $stopReason = '';

        foreach ($launchCandidates as $launch) {
            $launchId = $launch['system_id'];
            $launchSystem = $this->systems[$launchId] ?? null;
            if ($launchSystem === null
                || !$this->isSystemAllowedForJumpChain($shipType, $launchSystem, false, $options)
            ) {
                continue;
            }
            if (!isset($jumpNeighbors[$launchId])) {
                $jumpNeighbors[$launchId] = [];
            }

            foreach ($landingCandidates as $landing) {
                $candidateExpansions++;
                if ($candidateExpansions > $maxCandidateExpansions) {
                    $stopReason = sprintf(
                        'Hybrid candidate expansion capped at %d combinations.',
                        $maxCandidateExpansions
                    );
                    break 2;
                }
                $landingId = $landing['system_id'];
                $landingSystem = $this->systems[$landingId] ?? null;
                if ($landingSystem === null
                    || !$this->isSystemAllowedForJumpChain($shipType, $landingSystem, false, $options)
                ) {
                    continue;
                }
                if (!isset($jumpNeighbors[$landingId])) {
                    $jumpNeighbors[$landingId] = [];
                }

                $result = $dijkstra->shortestPath(
                    $jumpNeighbors,
                    $launchId,
                    $landingId,
                    function (int $from, int $to, mixed $edgeData) use ($options, $shipType): float {
                        $profile = $this->baseCostProfiles[$to] ?? null;
                        if ($profile === null) {
                            return INF;
                        }
                        $distance = (float) ($edgeData['distance_ly'] ?? 0.0);

                        return $this->jumpEdgeCost($distance, $shipType, $profile, $options);
                    },
                    function (int $node) use ($shipType, $options): bool {
                        $system = $this->systems[$node] ?? null;
                        if ($system === null) {
                            return false;
                        }
                        return $this->isSystemAllowedForJumpChain($shipType, $system, true, $options);
                    },
                    null,
                    60000
                );
                $nodesExplored += $result['nodes_explored'] ?? 0;

                if ($result['path'] === [] || ($result['path'][count($result['path']) - 1] ?? null) !== $landingId) {
                    continue;
                }

                $jumpSegments = $this->buildSegments($result['path'], $result['edges'], $shipType);
                if (!$this->validateRoute($jumpSegments, $shipType, $effectiveRange)) {
                    continue;
                }
                if (count($jumpSegments) > $maxJumpHops) {
                    continue;
                }

                $launchSegments = $this->buildGateSegmentsFromPath($launch['gate_path']);
                $landingSegments = $this->buildGateSegmentsFromPath($landing['gate_path']);
                $segments = array_merge($launchSegments, $jumpSegments, $landingSegments);

                $totalGateHops = $launch['gate_hops'] + $landing['gate_hops'];
                $waitDetails = $this->buildJumpWaitDetails($jumpSegments, $options);
                $jumpTravelMinutes = $this->jumpTravelMinutes($jumpSegments);
                $cooldownPenalty = $this->cooldownCapPenaltyMinutes($jumpSegments, $options);
                $totalCost = round(
                    $totalGateHops + $jumpTravelMinutes + $waitDetails['total_wait_minutes'] + $cooldownPenalty,
                    2
                );
                if ($totalCost >= $bestCost) {
                    continue;
                }

                $summary = $this->summarizeRoute($segments, $totalCost);
                $summary['nodes_explored'] = $nodesExplored;
                $summary['illegal_systems_filtered'] = 0;
                $summary['fatigue'] = $this->fatigueModel->evaluate($this->jumpSegments($jumpSegments), $options);
                $summary['total_fuel'] = $this->routeFuelTotal($summary, $options);
                $summary += $waitDetails;
                $summary['jump_travel_minutes'] = $jumpTravelMinutes;
                $summary['cooldown_cap_penalty_minutes'] = $cooldownPenalty;
                $summary['launch_system'] = $this->systemSummary($launchId);
                $summary['landing_system'] = $this->systemSummary($landingId);
                $summary['launch_gate_hops'] = $launch['gate_hops'];
                $summary['landing_gate_hops'] = $landing['gate_hops'];
                $summary['jump_hops'] = count($jumpSegments);
                $summary['jump_chain_ly'] = round((float) $summary['total_jump_ly'], 2);
                $summary['launch_choice'] = $launch['choice_details'];
                $summary['launch_reason'] = $launch['reason'];
                $summary['landing_choice'] = [
                    'gate_hops' => $landing['gate_hops'],
                    'score' => round((float) ($landing['score'] ?? 0.0), 3),
                ];
                $summary['landing_reason'] = (string) ($landing['reason'] ?? '');
                $summary['segment_timing'] = [
                    'launch_gate_minutes' => (float) $launch['gate_hops'],
                    'jump_travel_minutes' => $jumpTravelMinutes,
                    'jump_wait_minutes' => (float) ($waitDetails['total_wait_minutes'] ?? 0.0),
                    'cooldown_cap_penalty_minutes' => $cooldownPenalty,
                    'landing_gate_minutes' => (float) $landing['gate_hops'],
                    'hybrid_total_minutes' => $totalCost,
                ];
                $summary['hybrid_explainability'] = [
                    'launch' => [
                        'system' => $summary['launch_system'],
                        'rationale' => $summary['launch_reason'],
                        'gate_hops' => $launch['gate_hops'],
                        'projected_gate_progress_hops' => $launch['choice_details']['projected_gate_progress_hops'] ?? null,
                    ],
                    'landing' => [
                        'system' => $summary['landing_system'],
                        'rationale' => $summary['landing_reason'],
                        'gate_hops' => $landing['gate_hops'],
                        'projected_gate_progress_hops' => $landing['choice_details']['projected_gate_progress_hops'] ?? null,
                    ],
                    'jump_chain' => [
                        'jump_hops' => $summary['jump_hops'],
                        'jump_chain_ly' => $summary['jump_chain_ly'],
                    ],
                    'segment_timing' => $summary['segment_timing'],
                ];

                $bestPlan = $summary;
                $bestCost = $totalCost;
            }
        }

        if ($bestPlan === null) {
            return [
                'feasible' => false,
                'reason' => $stopReason !== '' ? $stopReason : 'No hybrid route found.',
                'nodes_explored' => $nodesExplored,
                'illegal_systems_filtered' => 0,
                'hybrid_candidate_debug' => $this->lastHybridCandidateDebug,
            ];
        }

        $hybridTimeCost = $this->routeTimeCost($bestPlan);
        $gateTimeCost = !empty($gateBaseline['feasible']) ? $this->routeTimeCost($gateBaseline) : INF;
        $jumpTimeCost = !empty($jumpBaseline['feasible']) ? $this->routeTimeCost($jumpBaseline) : INF;
        $bestPlan['baseline_time_costs'] = [
            'hybrid' => $hybridTimeCost,
            'gate' => $gateTimeCost,
            'jump' => $jumpTimeCost,
            'required_min_improvement' => $minHybridSelectionImprovement,
        ];
        $bestPlan['hybrid_launch_candidates'] = count($launchCandidates);
        $bestPlan['hybrid_gate_budget_configured'] = $hybridGateBudgetMax;
        $bestPlan['hybrid_gate_budget_applied'] = $launchHopLimit;
        $bestPlan['hybrid_gate_budget_used_depth'] = (int) ($this->lastHybridCandidateDebug['bfs_depth_reached'] ?? 0);
        $topLaunch = array_slice(array_map(static function (array $candidate): array {
            return [
                'system_id' => (int) ($candidate['system_id'] ?? 0),
                'system_name' => (string) ($candidate['system_name'] ?? ''),
                'gate_hops' => (int) ($candidate['gate_hops'] ?? 0),
                'benefit_hops' => (int) ($candidate['benefit_hops'] ?? 0),
            ];
        }, $launchCandidates), 0, 5);
        $bestPlan['hybrid_top_launch_candidates'] = $topLaunch;
        $bestPlan['hybrid_candidate_debug'] = $this->lastHybridCandidateDebug;
        $bestPlan['hybrid_explainability']['baseline_comparison'] = $bestPlan['baseline_time_costs'];

        if (is_finite($gateTimeCost) && ($gateTimeCost - $hybridTimeCost) < $minHybridSelectionImprovement) {
            return [
                'feasible' => false,
                'reason' => sprintf(
                    'Hybrid rejected: insufficient gain vs gate baseline (min %.3f time_cost).',
                    $minHybridSelectionImprovement
                ),
                'nodes_explored' => $nodesExplored,
                'illegal_systems_filtered' => 0,
            ];
        }
        if (is_finite($jumpTimeCost) && ($jumpTimeCost - $hybridTimeCost) < $minHybridSelectionImprovement) {
            return [
                'feasible' => false,
                'reason' => sprintf(
                    'Hybrid rejected: insufficient gain vs jump baseline (min %.3f time_cost).',
                    $minHybridSelectionImprovement
                ),
                'nodes_explored' => $nodesExplored,
                'illegal_systems_filtered' => 0,
            ];
        }

        return $bestPlan;
    }

    /**
     * @param array<int, array<int, array{to: int, is_regional_gate: bool}>> $graph
     * @return array<int, int>
     */
    private function buildGateHopMap(int $originId, string $shipType, array $options, array $graph): array
    {
        $queue = new \SplQueue();
        $queue->enqueue($originId);
        $hops = [$originId => 0];

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            $currentHops = $hops[$current] ?? 0;
            foreach ($graph[$current] ?? [] as $edge) {
                $neighbor = (int) ($edge['to'] ?? 0);
                if ($neighbor === 0 || isset($hops[$neighbor])) {
                    continue;
                }
                $system = $this->systems[$neighbor] ?? null;
                if ($system === null) {
                    continue;
                }
                if (!$this->isSystemAllowedForRoute($shipType, $system, true, $options)
                    && !$this->isSystemAllowedForRoute($shipType, $system, false, $options)
                ) {
                    continue;
                }
                $hops[$neighbor] = $currentHops + 1;
                $queue->enqueue($neighbor);
            }
        }

        return $hops;
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
                $neighbors[$from][] = $this->buildGateEdgeData((int) $from, (int) $to);
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

    private function computeHierarchicalGateRoute(int $startId, int $endId, string $shipType, array $options, string $preference): ?array
    {
        $startConstellationId = (int) ($this->systems[$startId]['constellation_id'] ?? 0);
        $endConstellationId = (int) ($this->systems[$endId]['constellation_id'] ?? 0);
        if ($startConstellationId === 0 || $endConstellationId === 0) {
            return null;
        }

        $graph = $this->buildGateGraph($startId, $endId, $shipType, $options);
        $neighbors = $graph['neighbors'];
        $filtered = (int) ($graph['filtered'] ?? 0);
        if (!isset($neighbors[$startId]) || !isset($neighbors[$endId])) {
            return null;
        }

        if ($startConstellationId === $endConstellationId) {
            $allowed = [];
            foreach ($this->systems as $systemId => $system) {
                if ((int) ($system['constellation_id'] ?? 0) === $startConstellationId) {
                    $allowed[(int) $systemId] = true;
                }
            }
            return $this->runGateDijkstra($neighbors, $startId, $endId, $shipType, $options, $preference, $filtered, $allowed, ['kind' => 'local']);
        }

        if ($this->constellationEdges === []) {
            return null;
        }
        $constellationPath = $this->constellationPath($startConstellationId, $endConstellationId);
        if (count($constellationPath) < 2) {
            return null;
        }

        $stitched = [$startId];
        $nodesExplored = 0;
        $currentSystem = $startId;
        for ($i = 0; $i < count($constellationPath) - 1; $i++) {
            $fromConstellation = $constellationPath[$i];
            $toConstellation = $constellationPath[$i + 1];
            $edge = $this->pickConstellationEdge($fromConstellation, $toConstellation, $currentSystem);
            if ($edge === null) {
                return null;
            }

            $allowedFrom = $this->allowedConstellationSet($fromConstellation);
            $segmentResult = $this->runGateDijkstraRaw($neighbors, $currentSystem, $edge['from_system_id'], $options, $preference, $allowedFrom);
            $nodesExplored += $segmentResult['nodes_explored'];
            if ($segmentResult['path'] === [] || ($segmentResult['path'][count($segmentResult['path']) - 1] ?? null) !== $edge['from_system_id']) {
                return null;
            }
            $stitched = array_merge($stitched, array_slice($segmentResult['path'], 1));
            $stitched[] = $edge['to_system_id'];
            $currentSystem = $edge['to_system_id'];
        }

        $allowedEnd = $this->allowedConstellationSet($endConstellationId);
        $finalResult = $this->runGateDijkstraRaw($neighbors, $currentSystem, $endId, $options, $preference, $allowedEnd);
        $nodesExplored += $finalResult['nodes_explored'];
        if ($finalResult['path'] === [] || ($finalResult['path'][count($finalResult['path']) - 1] ?? null) !== $endId) {
            return null;
        }
        $stitched = array_merge($stitched, array_slice($finalResult['path'], 1));

        $edges = array_fill(0, max(0, count($stitched) - 1), ['type' => 'gate']);
        $segments = $this->buildSegments($stitched, $edges, $shipType);
        if (!$this->validateRoute($segments, $shipType, null)) {
            return [
                'feasible' => false,
                'reason' => 'Gate route failed validation.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $filtered,
                'preference' => $preference,
                'penalty' => 0.0,
                'avoid_flags' => $this->buildAvoidFlags($options),
                'exception_corridor' => ['start' => ['required' => false, 'hops' => 0], 'destination' => ['required' => false, 'hops' => 0]],
            ];
        }
        $distance = 0.0;
        for ($i = 1; $i < count($stitched); $i++) {
            $distance += $this->gateStepWeight($stitched[$i - 1], $stitched[$i], $options, $preference);
        }

        $summary = $this->summarizeRoute($segments, $distance);
        $summary['nodes_explored'] = $nodesExplored;
        $summary['illegal_systems_filtered'] = $filtered;
        $summary['preference'] = $preference;
        $summary['penalty'] = $this->routeSecurityPenalty($summary['systems'] ?? []);
        $summary['total_fuel'] = $this->routeFuelTotal($summary, $options);
        $summary['avoid_flags'] = $this->buildAvoidFlags($options);
        $summary['exception_corridor'] = [
            'start' => ['required' => false, 'hops' => 0],
            'destination' => ['required' => false, 'hops' => 0],
        ];
        $summary['hierarchy'] = ['kind' => 'constellation', 'constellation_path' => $constellationPath];

        return $summary;
    }

    private function gateStepWeight(int $from, int $to, array $options, string $preference): float
    {
        $profile = $this->baseCostProfiles[$to] ?? null;
        if ($profile === null) {
            return INF;
        }
        return $this->gateEdgeCost($profile, $preference, $options);
    }

    private function runGateDijkstraRaw(array $neighbors, int $startId, int $endId, array $options, string $preference, ?array $allowed = null): array
    {
        $dijkstra = new Dijkstra();
        return $dijkstra->shortestPath(
            $neighbors,
            $startId,
            $endId,
            fn (int $from, int $to): float => $this->gateStepWeight($from, $to, $options, $preference),
            null,
            $allowed,
            50000
        );
    }

    private function runGateDijkstra(array $neighbors, int $startId, int $endId, string $shipType, array $options, string $preference, int $filtered, ?array $allowed, array $hierarchy): array
    {
        $result = $this->runGateDijkstraRaw($neighbors, $startId, $endId, $options, $preference, $allowed);
        if ($result['path'] === [] || ($result['path'][count($result['path']) - 1] ?? null) !== $endId) {
            return [
                'feasible' => false,
                'reason' => 'No gate route found.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $filtered,
                'preference' => $preference,
                'penalty' => 0.0,
                'avoid_flags' => $this->buildAvoidFlags($options),
                'exception_corridor' => ['start' => ['required' => false, 'hops' => 0], 'destination' => ['required' => false, 'hops' => 0]],
            ];
        }
        $segments = $this->buildSegments($result['path'], $result['edges'], $shipType);
        if (!$this->validateRoute($segments, $shipType, null)) {
            return null;
        }
        $summary = $this->summarizeRoute($segments, (float) $result['distance']);
        $summary['nodes_explored'] = $result['nodes_explored'];
        $summary['illegal_systems_filtered'] = $filtered;
        $summary['preference'] = $preference;
        $summary['penalty'] = $this->routeSecurityPenalty($summary['systems'] ?? []);
        $summary['total_fuel'] = $this->routeFuelTotal($summary, $options);
        $summary['avoid_flags'] = $this->buildAvoidFlags($options);
        $summary['exception_corridor'] = ['start' => ['required' => false, 'hops' => 0], 'destination' => ['required' => false, 'hops' => 0]];
        $summary['hierarchy'] = $hierarchy;
        return $summary;
    }

    /** @return int[] */
    private function constellationPath(int $startConstellationId, int $endConstellationId): array
    {
        $queue = new \SplQueue();
        $queue->enqueue($startConstellationId);
        $seen = [$startConstellationId => true];
        $prev = [];
        while (!$queue->isEmpty()) {
            $current = (int) $queue->dequeue();
            if ($current === $endConstellationId) {
                break;
            }
            foreach ($this->constellationEdges[$current] ?? [] as $edge) {
                $next = (int) $edge['to_constellation_id'];
                if (isset($seen[$next])) {
                    continue;
                }
                $seen[$next] = true;
                $prev[$next] = $current;
                $queue->enqueue($next);
            }
        }
        if (!isset($seen[$endConstellationId])) {
            return [];
        }
        $path = [$endConstellationId];
        $cursor = $endConstellationId;
        while (isset($prev[$cursor])) {
            $cursor = $prev[$cursor];
            array_unshift($path, $cursor);
        }
        return $path;
    }

    private function pickConstellationEdge(int $fromConstellationId, int $toConstellationId, int $fromSystemId): ?array
    {
        $best = null;
        $bestDist = PHP_INT_MAX;
        $distances = $this->constellationDistances[$fromConstellationId] ?? [];
        foreach ($this->constellationEdges[$fromConstellationId] ?? [] as $edge) {
            if ((int) $edge['to_constellation_id'] !== $toConstellationId) {
                continue;
            }
            $candidateFromSystem = (int) $edge['from_system_id'];
            $distance = $distances[$candidateFromSystem][$fromSystemId] ?? PHP_INT_MAX;
            if ($distance < $bestDist) {
                $bestDist = $distance;
                $best = $edge;
            }
        }
        return $best;
    }

    /** @return array<int, bool>|null */
    private function boundedJumpCandidateSet(int $startId, int $endId, int $rangeBucket): ?array
    {
        $startConstellationId = (int) ($this->systems[$startId]['constellation_id'] ?? 0);
        $endConstellationId = (int) ($this->systems[$endId]['constellation_id'] ?? 0);
        if ($startConstellationId === 0 || $endConstellationId === 0) {
            return null;
        }
        $edges = $this->jumpConstellationEdgesByRange[$rangeBucket] ?? [];
        if ($edges === []) {
            return null;
        }

        $constellationPath = $this->constellationPathForJumpRange($startConstellationId, $endConstellationId, $rangeBucket);
        if ($constellationPath === []) {
            return null;
        }

        $allowed = [
            $startId => true,
            $endId => true,
        ];
        $portalsByConstellation = $this->jumpConstellationPortalsByRange[$rangeBucket] ?? [];
        $midpointsByConstellation = $this->jumpMidpointsByRange[$rangeBucket] ?? [];
        foreach ($constellationPath as $constellationId) {
            foreach (array_slice($portalsByConstellation[$constellationId] ?? [], 0, 10) as $systemId) {
                $allowed[(int) $systemId] = true;
            }
            foreach (array_slice($midpointsByConstellation[$constellationId] ?? [], 0, 8) as $systemId) {
                $allowed[(int) $systemId] = true;
            }
            foreach ($this->systems as $systemId => $system) {
                if ((int) ($system['constellation_id'] ?? 0) !== (int) $constellationId) {
                    continue;
                }
                $allowed[(int) $systemId] = true;
            }
        }

        foreach (array_keys($allowed) as $systemId) {
            foreach ($this->gateNeighbors[(int) $systemId] ?? [] as $neighborId) {
                $allowed[(int) $neighborId] = true;
            }
        }

        return $allowed;
    }

    /** @return int[] */
    private function constellationPathForJumpRange(int $startConstellationId, int $endConstellationId, int $rangeBucket): array
    {
        if ($startConstellationId === $endConstellationId) {
            return [$startConstellationId];
        }
        $edges = $this->jumpConstellationEdgesByRange[$rangeBucket] ?? [];
        if ($edges === []) {
            return [];
        }

        $queue = new \SplQueue();
        $queue->enqueue($startConstellationId);
        $seen = [$startConstellationId => true];
        $prev = [];

        while (!$queue->isEmpty()) {
            $current = (int) $queue->dequeue();
            if ($current === $endConstellationId) {
                break;
            }
            foreach ($edges[$current] ?? [] as $edge) {
                $next = (int) ($edge['to_constellation_id'] ?? 0);
                if ($next === 0 || isset($seen[$next])) {
                    continue;
                }
                $seen[$next] = true;
                $prev[$next] = $current;
                $queue->enqueue($next);
            }
        }

        if (!isset($seen[$endConstellationId])) {
            return [];
        }

        $path = [$endConstellationId];
        $cursor = $endConstellationId;
        while (isset($prev[$cursor])) {
            $cursor = $prev[$cursor];
            array_unshift($path, $cursor);
        }

        return $path;
    }

    /** @return array<int, bool> */
    private function allowedConstellationSet(int $constellationId): array
    {
        $allowed = [];
        foreach ($this->systems as $systemId => $system) {
            if ((int) ($system['constellation_id'] ?? 0) === $constellationId) {
                $allowed[(int) $systemId] = true;
            }
        }
        return $allowed;
    }

    /** @param array<int, int[]> $precomputed */
    private function buildJumpGraph(
        array $precomputed,
        int $startId,
        int $endId,
        string $shipType,
        array $options,
        bool $debugLogs,
        float $effectiveRange,
        ?array $allowedSystems = null
    ): array
    {
        $neighbors = [];
        $filtered = 0;
        $debugSample = [];
        $sampleLimit = $debugLogs ? 6 : 0;
        $sampled = 0;
        $policyReasons = [
            'filtered_illegal_security' => 0,
            'filtered_avoid_list' => 0,
            'filtered_other' => 0,
        ];
        $rawCount = 0;
        $policyCount = 0;
        $rangeCount = 0;
        $cappedCount = 0;

        foreach ($precomputed as $from => $toList) {
            if (!isset($this->systems[$from])) {
                continue;
            }
            if ($allowedSystems !== null && !isset($allowedSystems[(int) $from])) {
                continue;
            }

            $fromIsMidpoint = $from !== $startId && $from !== $endId;
            $fromReason = $this->jumpPolicyFilterReason($shipType, $this->systems[$from], $fromIsMidpoint, $options);
            if ($fromReason !== null) {
                $filtered++;
                if (str_starts_with($fromReason, 'filtered_illegal_security')) {
                    $policyReasons['filtered_illegal_security'] = ($policyReasons['filtered_illegal_security'] ?? 0) + 1;
                }
                $policyReasons[$fromReason] = ($policyReasons[$fromReason] ?? 0) + 1;
                continue;
            }

            $filteredForNode = 0;
            foreach ($toList as $to) {
                $rawCount++;
                if (!isset($this->systems[$to])) {
                    continue;
                }
                if ($allowedSystems !== null && !isset($allowedSystems[(int) $to])) {
                    continue;
                }

                $distance = JumpMath::distanceLy($this->systems[$from], $this->systems[$to]);
                if ($distance > $effectiveRange) {
                    continue;
                }
                $rangeCount++;

                $toIsMidpoint = $to !== $startId && $to !== $endId;
                $reason = $this->jumpPolicyFilterReason($shipType, $this->systems[$to], $toIsMidpoint, $options);
                if ($reason !== null) {
                    $filtered++;
                    $filteredForNode++;
                    if (str_starts_with($reason, 'filtered_illegal_security')) {
                        $policyReasons['filtered_illegal_security'] = ($policyReasons['filtered_illegal_security'] ?? 0) + 1;
                    }
                    $policyReasons[$reason] = ($policyReasons[$reason] ?? 0) + 1;
                    if ($debugLogs) {
                        $fromSec = SecurityNav::debugComparison($this->systems[$from]);
                        $toSec = SecurityNav::debugComparison($this->systems[$to]);
                        $this->logger->debug('Jump edge filtered for legality', [
                            'from' => $this->systems[$from]['name'] ?? (string) $from,
                            'to' => $this->systems[$to]['name'] ?? (string) $to,
                            'from_raw' => $fromSec['security_raw'],
                            'from_sec_routing' => $fromSec['sec_routing'],
                            'to_raw' => $toSec['security_raw'],
                            'to_sec_routing' => $toSec['sec_routing'],
                            'threshold' => SecurityNav::HIGH_SEC_MIN,
                            'reason' => $reason,
                        ]);
                    }
                    continue;
                }
                $policyCount++;

                $neighbors[$from][] = [
                    'to' => $to,
                    'type' => 'jump',
                    'distance_ly' => $distance,
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
                && $this->isSystemAllowedForJumpChain($shipType, $this->systems[$endpointId], false, $options)
                && !isset($neighbors[$endpointId])
            ) {
                $neighbors[$endpointId] = [];
            }
        }

        return [
            'neighbors' => $neighbors,
            'filtered' => $filtered,
            'debug_sample' => $debugSample,
            'debug' => [
                'jump_neighbors_raw_count' => $rawCount,
                'jump_neighbors_after_range_count' => $rangeCount,
                'jump_neighbors_after_policy_count' => $policyCount,
                'jump_neighbors_capped_by_limit_count' => $cappedCount,
                'policy_filter_reasons_count' => $policyReasons,
            ],
        ];
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
        if ($this->jumpPolicyFilterReason($shipType, $system, $isMidpoint, $options) !== null) {
            return false;
        }

        return true;
    }

    private function normalizeGatePreference(array $options): string
    {
        $preference = strtolower((string) ($options['preference'] ?? 'shorter'));
        return in_array($preference, ['shorter', 'safer', 'less_secure'], true) ? $preference : 'shorter';
    }

    private function gateStepCost(string $preference, array $profile): float
    {
        if ($preference === 'shorter') {
            return 1.0;
        }
        $security = $profile['security'];
        $penalty = exp(0.15 * $profile['security_penalty']);

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

    private function npcSafetyWeight(array $options): float
    {
        $safety = max(0, min(100, (int) ($options['safety_vs_speed'] ?? 50)));
        $s = $safety / 100.0;

        return 0.6 + (0.7 * $s);
    }

    private function npcDetourHopBudget(array $options): int
    {
        if (empty($options['prefer_npc'])) {
            return 0;
        }

        $safety = max(0, min(100, (int) ($options['safety_vs_speed'] ?? 50)));
        return $safety >= 65 ? 1 : 0;
    }

    private function npcDetourBonusMultiplier(array $options): float
    {
        if (empty($options['prefer_npc'])) {
            return 1.0;
        }

        return $this->npcDetourHopBudget($options) > 0 ? 1.55 : 1.0;
    }

    private function npcStationStepBonus(array $profile, array $options): float
    {
        if (empty($options['prefer_npc'])) {
            return 0.0;
        }

        $npcCount = (int) ($profile['npc_station_count'] ?? 0);
        $hasNpcStation = !empty($profile['has_npc_station']) || $npcCount > 0;
        if (!$hasNpcStation) {
            return 0.0;
        }

        $count = max(1, $npcCount);
        $weightedMagnitude = min(0.28, 0.06 * $count) * $this->npcSafetyWeight($options);
        $stepCap = 0.18;

        return -min($stepCap, $weightedMagnitude);
    }

    private function routeNpcBonus(array $route, array $options): float
    {
        if (empty($options['prefer_npc'])) {
            return 0.0;
        }

        $npcCount = max(0, (int) ($route['npc_stations_in_route'] ?? 0));
        $systemCount = max(1, count((array) ($route['systems'] ?? [])));
        $coverage = min(1.0, $npcCount / $systemCount);

        $multiplier = $this->npcDetourBonusMultiplier($options);
        $weightedMagnitude = ((0.22 * $coverage) + (0.02 * min(10, $npcCount)))
            * $this->npcSafetyWeight($options)
            * $multiplier;
        $totalCap = $this->npcDetourHopBudget($options) > 0 ? 0.8 : 0.55;

        return -min($totalCap, $weightedMagnitude);
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
            $security = SecurityNav::value($system);
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

    private function optionsForPrimaryAvoidAttempt(array $options): array
    {
        if (empty($options['avoid_lowsec']) && empty($options['avoid_nullsec'])) {
            $options['avoid_strictness'] = 'soft';
            return $options;
        }

        $requested = strtolower((string) ($options['avoid_strictness'] ?? 'strict'));
        $options['avoid_strictness'] = $requested === 'soft' ? 'soft' : 'strict';
        return $options;
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

    private function withRouteMeta(array $route, bool $fallbackUsed, array $requestedOptions, array $attemptOptions): array
    {
        $route['fallback_used'] = $fallbackUsed;
        $requestedStrictness = strtolower((string) ($requestedOptions['avoid_strictness']
            ?? ((empty($requestedOptions['avoid_lowsec']) && empty($requestedOptions['avoid_nullsec'])) ? 'soft' : 'strict')));
        $route['requested_avoid_strictness'] = $requestedStrictness === 'soft' ? 'soft' : 'strict';
        $route['applied_avoid_strictness'] = strtolower((string) ($attemptOptions['avoid_strictness'] ?? 'soft'));
        if ($fallbackUsed) {
            $route['applied_avoid_strictness'] = 'soft';
            $route['fallback_warning'] = true;
            $route['fallback_message'] = 'Strict avoid filters produced no feasible route; returned best effort route using soft avoid penalties.';
        } else {
            $route['fallback_warning'] = false;
            $route['fallback_message'] = null;
        }
        $systems = is_array($route['systems'] ?? null) ? $route['systems'] : [];
        $route['space_types'] = $this->spaceTypesUsed($systems);
        $route += $this->routeNpcMetrics($systems);
        return $route;
    }

    private function withHybridFatigueDetails(array $route, array $options): array
    {
        if (!array_key_exists('fatigue', $route)) {
            $route['fatigue'] = $this->fatigueModel->evaluate([], $options);
        }
        $waitDefaults = $this->buildJumpWaitDetails([], $options);
        foreach ($waitDefaults as $key => $value) {
            if (!array_key_exists($key, $route)) {
                $route[$key] = $value;
            }
        }
        return $route;
    }

    /** @param array<int, array{security: float}> $systems */
    private function spaceTypesUsed(array $systems): array
    {
        $types = [];
        foreach ($systems as $system) {
            $types[SecurityNav::spaceType($system)] = true;
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

    private function avoidPenalty(array $profile, array $options): float
    {
        $penalty = 0.0;
        $securityClass = $profile['security_class'];
        if (!empty($options['avoid_nullsec']) && $securityClass === 'null') {
            $penalty += 2.5;
        }
        if (!empty($options['avoid_lowsec']) && $securityClass === 'low') {
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

    private function useLegacyHybridPlanner(array $options): bool
    {
        if (array_key_exists('hybrid_mixed_graph', $options)) {
            return empty($options['hybrid_mixed_graph']);
        }

        return !Env::bool('HYBRID_MIXED_GRAPH_ENABLED', false);
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
            $graph = $this->buildJumpGraph($neighbors, $startId, $endId, $shipType, $options, false, (float) $bucket, null);
            if (!isset($graph['neighbors'][$startId]) || !isset($graph['neighbors'][$endId])) {
                $this->logger->debug('Jump diagnostic filtered endpoints', [
                    'bucket' => $bucket,
                    'filtered' => $graph['filtered'],
                ]);
                continue;
            }

            $dijkstra = new Dijkstra();
            $result = $dijkstra->shortestPath(
                $graph['neighbors'],
                $startId,
                $endId,
                function (int $from, int $to, mixed $edgeData) use ($options, $shipType): float {
                    $profile = $this->baseCostProfiles[$to] ?? null;
                    if ($profile === null) {
                        return INF;
                    }
                    $distance = (float) ($edgeData['distance_ly'] ?? 0.0);
                    return $this->jumpEdgeCost($distance, $shipType, $profile, $options);
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
        if ($this->isPochvenSystem($system)) {
            return 'pochven';
        }

        $reason = $this->jumpPolicyFilterReason($shipType, $system, $isMidpoint, $options);
        if ($reason === null) {
            return null;
        }

        return match ($reason) {
            'filtered_avoid_list' => 'avoid_systems',
            'filtered_illegal_security_highsec_forbidden' => 'highsec',
            'filtered_illegal_security_avoid_lowsec_strict' => 'avoid_lowsec',
            'filtered_illegal_security_avoid_nullsec_strict' => 'avoid_nullsec',
            default => 'ship_rules',
        };
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
            $security = SecurityNav::value($system);
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
        $isHighsec = $security >= SecurityNav::HIGH_SEC_MIN;
        $isLowsec = $security >= SecurityNav::LOW_SEC_MIN && $security < SecurityNav::HIGH_SEC_MIN;
        $isNullsec = $security < SecurityNav::LOW_SEC_MIN;

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
        foreach ($this->adjacency as $from => $edges) {
            if (!isset($this->systems[$from])) {
                continue;
            }
            foreach ($edges as $edge) {
                $to = (int) ($edge['to'] ?? 0);
                if (!isset($this->systems[$to])) {
                    continue;
                }
                $neighbors[$from][] = $this->buildGateEdgeData((int) $from, $to, $edge);
            }
            if (!isset($neighbors[$from])) {
                $neighbors[$from] = [];
            }
        }
        return $neighbors;
    }

    /**
     * @param array<int, int[]> $precomputed
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function buildHybridJumpNeighbors(array $precomputed, ?array $allowedSystems = null, ?float $effectiveRange = null): array
    {
        $neighbors = [];
        foreach ($precomputed as $from => $toList) {
            if (!isset($this->systems[$from])) {
                continue;
            }
            if ($allowedSystems !== null && !isset($allowedSystems[(int) $from])) {
                continue;
            }
            foreach ($toList as $to) {
                if (!isset($this->systems[$to])) {
                    continue;
                }
                if ($allowedSystems !== null && !isset($allowedSystems[(int) $to])) {
                    continue;
                }
                $distance = JumpMath::distanceLy($this->systems[$from], $this->systems[$to]);
                if ($effectiveRange !== null && $distance > $effectiveRange) {
                    continue;
                }
                $neighbors[$from][] = [
                    'to' => $to,
                    'type' => 'jump',
                    'distance_ly' => $distance,
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
        $profile = $this->baseCostProfiles[$systemId] ?? null;
        if ($profile !== null) {
            return $profile['risk_penalty'];
        }
        return $this->riskScorer->penalty($risk);
    }

    private function riskWeight(array $options): float
    {
        $coefficients = $this->profileCoefficients($options);

        return max(0.0, (float) ($coefficients['risk_multiplier'] ?? 0.0));
    }

    private function isCapitalShipType(string $shipType): bool
    {
        $normalized = $this->shipRules->normalizeShipType($shipType);
        return in_array($normalized, JumpShipType::CAPITALS, true);
    }

    private function systemSecurityForNav(array $system, bool $useNav): float
    {
        return SecurityNav::value($system);
    }

    private function isSystemAllowedForShip(array $system, array $policy): string
    {
        $security = SecurityNav::value($system);
        $strictness = strtolower((string) ($policy['strictness'] ?? 'soft'));
        $isStrict = $strictness === 'strict';

        if (SecurityNav::isIllegalHighsecForCapital($system, $policy)) {
            return self::SYSTEM_ILLEGAL_HARD;
        }

        if ($security >= 0.0) {
            if (!empty($policy['avoid_lowsec'])) {
                return $isStrict ? self::SYSTEM_ILLEGAL_HARD : self::SYSTEM_ILLEGAL_SOFT;
            }

            return self::SYSTEM_LEGAL;
        }

        if (!empty($policy['avoid_nullsec'])) {
            return $isStrict ? self::SYSTEM_ILLEGAL_HARD : self::SYSTEM_ILLEGAL_SOFT;
        }

        return self::SYSTEM_LEGAL;
    }

    private function isSystemAllowedForJumpChain(string $shipType, array $system, bool $isMidpoint, array $options): bool
    {
        return $this->jumpPolicyFilterReason($shipType, $system, $isMidpoint, $options) === null;
    }

    private function jumpPolicyFilterReason(string $shipType, array $system, bool $isMidpoint, array $options): ?string
    {
        if ($this->isPochvenSystem($system)) {
            return 'filtered_illegal_security_pochven';
        }

        $useCapitalLegality = $this->isCapitalShipType($shipType) || $shipType === JumpShipType::JUMP_FREIGHTER;

        if ($useCapitalLegality) {
            $legality = $this->isSystemAllowedForShip($system, [
                'ship_class' => 'capital',
                'avoid_lowsec' => !empty($options['avoid_lowsec']) && $isMidpoint,
                'avoid_nullsec' => !empty($options['avoid_nullsec']) && $isMidpoint,
                'strictness' => $this->shouldFilterAvoidedSpace($options) ? 'strict' : 'soft',
            ]);

            if ($legality === self::SYSTEM_ILLEGAL_HARD) {
                $security = SecurityNav::getSecurityForRouting($system);
                if (SecurityNav::isIllegalHighsecForCapital($system, $options)) {
                    $comparison = SecurityNav::debugComparison($system);
                    $this->logger->debug('Rejected system: highsec forbidden for capital routing', [
                        'system' => $system['name'] ?? (string) ($system['id'] ?? 'unknown'),
                        'security_raw' => $comparison['security_raw'],
                        'sec_routing' => $comparison['sec_routing'],
                        'threshold' => SecurityNav::HIGH_SEC_MIN,
                        'security_nav' => $comparison['sec_nav'],
                        'strategy' => $comparison['strategy'],
                    ]);

                    return 'filtered_illegal_security_highsec_forbidden';
                }
                if ($security >= 0.0 && !empty($options['avoid_lowsec']) && $isMidpoint) {
                    return 'filtered_illegal_security_avoid_lowsec_strict';
                }
                if ($security < 0.0 && !empty($options['avoid_nullsec']) && $isMidpoint) {
                    return 'filtered_illegal_security_avoid_nullsec_strict';
                }
                return 'filtered_illegal_security';
            }

        } elseif (!$this->shipRules->isSystemAllowed($shipType, $system, $isMidpoint)) {
            return 'filtered_illegal_security';
        }

        if ($isMidpoint && !empty($options['require_station_midpoints']) && strtolower((string) ($options['avoid_strictness'] ?? 'soft')) === 'strict') {
            if (empty($system['has_npc_station'])) {
                return 'filtered_station_midpoint_required';
            }
        }

        if ($isMidpoint && !empty($options['avoid_systems']) && in_array($system['name'], (array) $options['avoid_systems'], true)) {
            return 'filtered_avoid_list';
        }

        return null;
    }

    /** @return array{is_violation:bool,penalty_hops:float} */
    private function stationMidpointViolationPenalty(int $systemId, int $endId, array $options): array
    {
        if (empty($options['require_station_midpoints'])) {
            return ['is_violation' => false, 'penalty_hops' => 0.0];
        }

        if ($systemId === $endId) {
            return ['is_violation' => false, 'penalty_hops' => 0.0];
        }

        $system = $this->systems[$systemId] ?? null;
        if ($system === null || !empty($system['has_npc_station'])) {
            return ['is_violation' => false, 'penalty_hops' => 0.0];
        }

        $strictness = strtolower((string) ($options['avoid_strictness'] ?? 'soft'));
        if ($strictness === 'strict') {
            return ['is_violation' => true, 'penalty_hops' => INF];
        }

        return ['is_violation' => true, 'penalty_hops' => 2.0];
    }

    /** @param array<int, array<string, mixed>> $segments
     * @return array<int, array<string, mixed>>
     */
    private function annotateStationViolationsOnSegments(array $segments, array $options, int $endId): array
    {
        if (empty($options['require_station_midpoints']) || strtolower((string) ($options['avoid_strictness'] ?? 'soft')) === 'strict') {
            return $segments;
        }

        foreach ($segments as $idx => $segment) {
            if (($segment['type'] ?? 'gate') !== 'jump') {
                continue;
            }
            $toId = (int) ($segment['to_id'] ?? 0);
            if ($toId === $endId) {
                continue;
            }
            $system = $this->systems[$toId] ?? null;
            $segments[$idx]['station_violation'] = $system === null || empty($system['has_npc_station']);
        }

        return $segments;
    }

    /** @return array{require_station_midpoints:bool,station_type:string,midpoints_with_station:string,station_midpoint_violations:array<int,string>} */
    private function jumpMidpointStationDiagnostics(array $segments, array $options, int $endId): array
    {
        $totalMidpoints = 0;
        $stationMidpoints = 0;
        $violations = [];

        foreach ($segments as $segment) {
            if (($segment['type'] ?? 'gate') !== 'jump') {
                continue;
            }
            $toId = (int) ($segment['to_id'] ?? 0);
            if ($toId === $endId) {
                continue;
            }
            $totalMidpoints++;
            $system = $this->systems[$toId] ?? null;
            if ($system !== null && !empty($system['has_npc_station'])) {
                $stationMidpoints++;
                continue;
            }
            $violations[] = (string) ($segment['to'] ?? (string) $toId);
        }

        return [
            'require_station_midpoints' => !empty($options['require_station_midpoints']),
            'station_type' => (string) ($options['station_type'] ?? 'npc'),
            'midpoints_with_station' => sprintf('%d/%d', $stationMidpoints, $totalMidpoints),
            'station_midpoint_violations' => $violations,
        ];
    }

    /** @param array<int, array<int, array{to:int,type:string,distance_ly:float}>> $neighbors */
    private function traceJumpGraphConnectivity(array $neighbors, int $startId, int $endId, int $maxHops = 10): array
    {
        $queue = new \SplQueue();
        $queue->enqueue([$startId, 0]);
        $seen = [$startId => true];
        $minHopsFound = null;

        while (!$queue->isEmpty()) {
            [$node, $depth] = $queue->dequeue();
            if ($depth > $maxHops) {
                continue;
            }
            if ($node === $endId) {
                $minHopsFound = $depth;
                break;
            }
            foreach ($neighbors[$node] ?? [] as $edge) {
                $to = (int) ($edge['to'] ?? 0);
                if ($to === 0 || isset($seen[$to])) {
                    continue;
                }
                $seen[$to] = true;
                $queue->enqueue([$to, $depth + 1]);
            }
        }

        return [
            'reachable_within_max_hops' => $minHopsFound !== null,
            'min_hops_found' => $minHopsFound,
            'number_of_nodes_seen' => count($seen),
            'max_hops_evaluated' => $maxHops,
        ];
    }

    private function isPochvenSystem(array $system): bool
    {
        $spaceType = strtolower((string) ($system['space_type'] ?? $system['space'] ?? $system['region_type'] ?? ''));
        if ($spaceType === 'pochven') {
            return true;
        }

        if (!empty($system['is_pochven'])) {
            return true;
        }

        return (int) ($system['region_id'] ?? 0) === 10000070;
    }

    /** @param array{to?: int, is_regional_gate?: bool}|null $adjacencyEdge */
    private function buildGateEdgeData(int $from, int $to, ?array $adjacencyEdge = null): array
    {
        $isRegionalGate = false;
        if ($adjacencyEdge !== null) {
            $isRegionalGate = !empty($adjacencyEdge['is_regional_gate']);
        } else {
            foreach ($this->adjacency[$from] ?? [] as $edge) {
                if ((int) ($edge['to'] ?? 0) !== $to) {
                    continue;
                }
                $isRegionalGate = !empty($edge['is_regional_gate']);
                break;
            }
        }

        return [
            'to' => $to,
            'type' => 'gate',
            'distance_ly' => null,
            'is_regional_gate' => $isRegionalGate,
        ];
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

    /** @param int[] $path */
    private function buildGateSegmentsFromPath(array $path): array
    {
        if (count($path) < 2) {
            return [];
        }
        $edges = [];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $edges[] = ['type' => 'gate'];
        }
        return $this->buildSegments($path, $edges, '');
    }

    /**
     * @return array<int, array{
     *   system_id: int,
     *   system_name: string,
     *   gate_hops: int,
     *   gate_path: int[],
     *   gate_path_cost: float,
     *   score: float,
     *   distance_ly: float,
     *   risk_score: float,
     *   regional_gate_count: int,
     *   npc_bonus: float,
     *   has_npc_station: bool,
     *   strict_legal: bool,
     *   reason: string,
     *   choice_details: array<string, mixed>
     * }>
     */
    private function buildHybridLaunchCandidates(
        int $startId,
        int $endId,
        string $shipType,
        array $options,
        int $maxHops,
        int $limit,
        array $hopsToEnd,
        ?int $baselineGateHops,
        int $minSegmentBenefitHops,
        ?array $jumpNeighbors = null
    ): array {
        $startSystem = $this->systems[$startId] ?? null;
        $endSystem = $this->systems[$endId] ?? null;
        if ($startSystem === null || $endSystem === null) {
            $this->lastHybridCandidateDebug = [];
            return [];
        }

        $queue = new \SplQueue();
        $queue->enqueue($startId);
        $hops = [$startId => 0];
        $prev = [$startId => null];
        $frontierPerDepth = [1];
        $debug = [
            'bfs_visited_total' => 0,
            'bfs_depth_reached' => 0,
            'bfs_frontier_count_per_depth' => $frontierPerDepth,
            'candidates_considered_total' => 0,
            'candidates_returned_total' => 0,
            'filter_reasons_count' => [
                'filtered_illegal_security' => 0,
                'filtered_avoid_list' => 0,
                'filtered_missing_coordinates' => 0,
                'filtered_no_jump_neighbors' => 0,
                'filtered_no_jump_connectivity_to_destination' => 0,
                'filtered_exceeds_gate_budget' => 0,
                'filtered_candidate_disabled_by_setting' => 0,
                'filtered_internal_error' => 0,
            ],
            'strict_pass_count' => 0,
            'soft_pass_count' => 0,
            'top_candidates' => [],
        ];

        while (!$queue->isEmpty()) {
            $current = (int) $queue->dequeue();
            $depth = (int) ($hops[$current] ?? 0);
            $debug['bfs_visited_total']++;
            if ($depth >= $maxHops) {
                continue;
            }
            foreach ($this->adjacency[$current] ?? [] as $edge) {
                $neighbor = (int) ($edge['to'] ?? 0);
                if ($neighbor === 0 || isset($hops[$neighbor])) {
                    continue;
                }
                $neighborHops = $depth + 1;
                $hops[$neighbor] = $neighborHops;
                $prev[$neighbor] = $current;
                $frontierPerDepth[$neighborHops] = ($frontierPerDepth[$neighborHops] ?? 0) + 1;
                $debug['bfs_depth_reached'] = max((int) $debug['bfs_depth_reached'], $neighborHops);
                $queue->enqueue($neighbor);
            }
        }

        $debug['bfs_frontier_count_per_depth'] = array_values($frontierPerDepth);

        $candidates = [];
        $riskWeight = $this->riskWeight($options);
        foreach ($hops as $systemId => $gateHops) {
            if (!isset($this->systems[$systemId])) {
                continue;
            }
            $debug['candidates_considered_total']++;
            $system = $this->systems[$systemId];
            $profile = $this->baseCostProfiles[$systemId] ?? null;
            if ($profile === null) {
                $debug['filter_reasons_count']['filtered_internal_error']++;
                continue;
            }

            $strictReason = $this->jumpPolicyFilterReason($shipType, $system, false, $options);
            $strictLegal = $strictReason === null;
            if (!$strictLegal && $strictReason === 'filtered_avoid_list') {
                $debug['filter_reasons_count']['filtered_avoid_list']++;
                continue;
            }
            if (!$this->hasCoordinates($system)) {
                $debug['filter_reasons_count']['filtered_missing_coordinates']++;
                continue;
            }
            if ($strictLegal) {
                $debug['strict_pass_count']++;
            } else {
                $debug['soft_pass_count']++;
            }

            if ($jumpNeighbors !== null && count($jumpNeighbors[$systemId] ?? []) < 1) {
                $debug['filter_reasons_count']['filtered_no_jump_neighbors']++;
                continue;
            }
            if ($gateHops > $maxHops) {
                $debug['filter_reasons_count']['filtered_exceeds_gate_budget']++;
                continue;
            }

            if (!array_key_exists($systemId, $hopsToEnd)) {
                $debug['filter_reasons_count']['filtered_no_jump_connectivity_to_destination']++;
                continue;
            }

            $distance = JumpMath::distanceLy($system, $endSystem);
            $riskScore = (float) $profile['risk_penalty'];
            $regionalGateCount = 0;
            foreach ($this->adjacency[$systemId] ?? [] as $edge) {
                if (!empty($edge['is_regional_gate'])) {
                    $regionalGateCount++;
                }
            }
            $npcBonus = $this->npcStationStepBonus($system, $options);
            $estimatedJumpCooldown = $this->estimateJumpCooldownMinutes($shipType, $distance);
            $legalPenalty = $strictLegal ? 0.0 : 1000.0;
            $score = $gateHops
                + $distance
                + $estimatedJumpCooldown
                + ($riskScore * $riskWeight)
                - ($regionalGateCount * 0.3)
                + $npcBonus
                + $legalPenalty;

            $gatePath = $this->buildGatePathFromPrev($prev, (int) $systemId);
            $remainingGateHops = $hopsToEnd[$systemId] ?? null;
            $benefitHops = null;
            if ($baselineGateHops !== null && $remainingGateHops !== null) {
                $benefitHops = $baselineGateHops - ((int) $gateHops + (int) $remainingGateHops);
                if ($benefitHops < $minSegmentBenefitHops) {
                    $debug['filter_reasons_count']['filtered_candidate_disabled_by_setting']++;
                    continue;
                }
            }

            $choiceDetails = [
                'score' => round($score, 3),
                'distance_to_destination_ly' => round($distance, 2),
                'risk_score' => round($riskScore, 2),
                'gate_hops' => (int) $gateHops,
                'strict_legal' => $strictLegal,
                'has_npc_station' => !empty($system['has_npc_station']) || (int) ($system['npc_station_count'] ?? 0) > 0,
                'projected_gate_progress_hops' => $benefitHops,
            ];

            $candidates[] = [
                'system_id' => (int) $systemId,
                'system_name' => (string) ($system['name'] ?? (string) $systemId),
                'gate_hops' => (int) $gateHops,
                'gate_path' => $gatePath,
                'gate_path_cost' => (float) $gateHops,
                'score' => (float) $score,
                'distance_ly' => (float) $distance,
                'risk_score' => (float) $riskScore,
                'regional_gate_count' => (int) $regionalGateCount,
                'npc_bonus' => (float) $npcBonus,
                'has_npc_station' => (bool) $choiceDetails['has_npc_station'],
                'strict_legal' => $strictLegal,
                'reason' => $strictLegal ? 'strict_pass' : 'soft_pass_with_penalty',
                'choice_details' => $choiceDetails,
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            if ((int) $a['gate_hops'] !== (int) $b['gate_hops']) {
                return (int) $a['gate_hops'] <=> (int) $b['gate_hops'];
            }
            if (abs((float) $a['risk_score'] - (float) $b['risk_score']) > 1e-9) {
                return (float) $a['risk_score'] <=> (float) $b['risk_score'];
            }
            return (float) $a['score'] <=> (float) $b['score'];
        });

        $selected = array_slice($candidates, 0, $limit);
        $debug['candidates_returned_total'] = count($selected);
        $debug['top_candidates'] = array_slice(array_map(static function (array $candidate): array {
            return [
                'system_id' => (int) $candidate['system_id'],
                'system_name' => (string) $candidate['system_name'],
                'gate_depth' => (int) $candidate['gate_hops'],
                'gate_path_cost' => (float) $candidate['gate_path_cost'],
                'risk_score' => round((float) $candidate['risk_score'], 2),
                'strict_legal' => (bool) $candidate['strict_legal'],
                'has_npc_station' => (bool) $candidate['has_npc_station'],
            ];
        }, $selected), 0, 10);

        $this->lastHybridCandidateDebug = $debug;

        return $selected;
    }

    /**
     * @return array<int, array{system_id: int, gate_hops: int, gate_path: int[]}>
     */
    private function buildHybridLandingCandidates(
        int $endId,
        string $shipType,
        array $options,
        int $maxHops,
        array $hopsFromStart,
        ?int $baselineGateHops,
        int $minSegmentBenefitHops,
        int $limit
    ): array {
        $endSystem = $this->systems[$endId] ?? null;
        if ($endSystem === null) {
            return [];
        }

        $paths = $this->gatePathsWithinHops(
            $endId,
            $maxHops,
            function (int $systemId, bool $asMidpoint) use ($shipType, $options): bool {
                $system = $this->systems[$systemId] ?? null;
                if ($system === null) {
                    return false;
                }
                return $this->isSystemAllowedForRoute($shipType, $system, $asMidpoint, $options);
            },
            $this->reverseAdjacency
        );

        $candidates = [];
        foreach ($paths['hops'] as $systemId => $hops) {
            if (!isset($this->systems[$systemId])) {
                continue;
            }
            $system = $this->systems[$systemId];
            if (!$this->isSystemAllowedForRoute($shipType, $system, false, $options)) {
                continue;
            }
            $gatePath = $this->buildGatePathFromPrev($paths['prev'], $systemId);
            if ($gatePath !== [] && $gatePath[0] === $endId) {
                $gatePath = array_reverse($gatePath);
            }
            $gateProgress = null;
            if ($baselineGateHops !== null && isset($hopsFromStart[$systemId])) {
                $gateProgress = max(0, $baselineGateHops - (int) $hops);
                if ($gateProgress < $minSegmentBenefitHops) {
                    continue;
                }
            }
            $candidates[] = [
                'system_id' => (int) $systemId,
                'gate_hops' => (int) $hops,
                'gate_path' => $gatePath,
                'score' => (float) $hops,
                'reason' => sprintf('Landing candidate requires %d gate hops to destination.', (int) $hops),
                'choice_details' => [
                    'projected_gate_progress_hops' => $gateProgress,
                ],
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            return $a['gate_hops'] <=> $b['gate_hops'];
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param array<int, array<int, array{to: int, is_regional_gate: bool}>> $graph
     * @return array{hops: array<int, int>, prev: array<int, int|null>}
     */
    private function gatePathsWithinHops(int $startId, int $maxHops, callable $allowFn, array $graph): array
    {
        $queue = new \SplQueue();
        $queue->enqueue($startId);
        $hops = [$startId => 0];
        $prev = [$startId => null];

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            $currentHops = $hops[$current] ?? 0;
            if ($currentHops >= $maxHops) {
                continue;
            }
            foreach ($graph[$current] ?? [] as $edge) {
                $neighbor = (int) ($edge['to'] ?? 0);
                if ($neighbor === 0 || isset($hops[$neighbor])) {
                    continue;
                }
                if (!$allowFn($neighbor, false)) {
                    continue;
                }
                $hops[$neighbor] = $currentHops + 1;
                $prev[$neighbor] = $current;
                if ($allowFn($neighbor, true)) {
                    $queue->enqueue($neighbor);
                }
            }
        }

        return ['hops' => $hops, 'prev' => $prev];
    }

    /**
     * @param array<int, int|null> $prev
     * @return int[]
     */
    private function buildGatePathFromPrev(array $prev, int $targetId): array
    {
        if (!isset($prev[$targetId])) {
            return [];
        }
        $path = [$targetId];
        $current = $targetId;
        while (isset($prev[$current]) && $prev[$current] !== null) {
            $current = $prev[$current];
            $path[] = $current;
        }
        return array_reverse($path);
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
            $segmentType = (string) ($segment['type'] ?? 'gate');
            if ($segmentType === 'jump') {
                if (!$this->isSystemAllowedForJumpChain($shipType, $system, $isMidpoint, [])) {
                    return false;
                }
            } elseif (!$this->shipRules->isSystemAllowed($shipType, $system, $isMidpoint)) {
                return false;
            }
            if ($segmentType === 'jump' && $effectiveRange !== null) {
                $distance = (float) ($segment['distance_ly'] ?? 0.0);
                if ($distance > $effectiveRange + 0.0001) {
                    return false;
                }
            }
        }
        return true;
    }

    private function routeFuelTotal(array $summary, array $options): float
    {
        $jumpLy = max(0.0, (float) ($summary['total_jump_ly'] ?? 0.0));
        $factor = max(0.0, (float) ($options['fuel_per_ly_factor'] ?? 0.0));

        return round($jumpLy * $factor, 2);
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
        $totalGateLy = 0.0;
        $gateHops = 0;
        $jumpCount = 0;
        foreach ($segments as $segment) {
            $fromId = (int) ($segment['from_id'] ?? 0);
            $toId = (int) ($segment['to_id'] ?? 0);
            $system = $this->systems[$toId] ?? null;
            if ($system) {
                $systems[] = $this->systemSummary($toId);
            }
            $segmentType = (string) ($segment['type'] ?? 'gate');
            if ($segmentType === 'jump') {
                $totalJumpLy += (float) ($segment['distance_ly'] ?? 0.0);
                $jumpCount++;
                continue;
            }

            $gateHops++;
            if (isset($this->systems[$fromId], $this->systems[$toId])) {
                $totalGateLy += JumpMath::distanceLy($this->systems[$fromId], $this->systems[$toId]);
            }
        }
        $lowsecCount = 0;
        $nullsecCount = 0;
        foreach ($systems as $system) {
            $security = SecurityNav::value($system);
            if ($security >= SecurityNav::LOW_SEC_MIN && $security < SecurityNav::HIGH_SEC_MIN) {
                $lowsecCount++;
            } elseif ($security < SecurityNav::LOW_SEC_MIN) {
                $nullsecCount++;
            }
        }
        $npcMetrics = $this->routeNpcMetrics($systems);
        $totalLy = $totalJumpLy + $totalGateLy;

        return [
            'feasible' => true,
            'legacy_total_cost' => round($distance, 2),
            'total_cost' => round($distance, 2),
            'total_gates' => $gateHops,
            'gate_count' => $gateHops,
            'jump_count' => $jumpCount,
            'total_jump_ly' => round($totalJumpLy, 2),
            'total_gate_ly' => round($totalGateLy, 2),
            'total_ly' => round($totalLy, 2),
            'total_fuel' => 0.0,
            'segments' => $segments,
            'systems' => $systems,
            'lowsec_count' => $lowsecCount,
            'nullsec_count' => $nullsecCount,
            'npc_stations_in_route' => $npcMetrics['npc_stations_in_route'],
            'npc_station_ratio' => $npcMetrics['npc_station_ratio'],
        ];
    }

    /** @param array<int, array{has_npc_station?: bool|null}> $systems */
    private function routeNpcMetrics(array $systems): array
    {
        $npcStationsInRoute = 0;
        foreach ($systems as $system) {
            if (!empty($system['has_npc_station'])) {
                $npcStationsInRoute++;
            }
        }
        $systemCount = count($systems);
        $ratio = $systemCount > 0 ? ($npcStationsInRoute / $systemCount) : 0.0;

        return [
            'npc_stations_in_route' => $npcStationsInRoute,
            'npc_station_ratio' => round(max(0.0, min(1.0, $ratio)), 3),
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

    private function jumpTravelMinutes(array $jumpSegments): float
    {
        $total = 0.0;
        foreach ($jumpSegments as $segment) {
            $total += (float) ($segment['distance_ly'] ?? 0.0);
        }
        return round($total, 2);
    }

    private function estimateJumpCooldownMinutes(string $shipType, float $distanceLy): float
    {
        $metrics = $this->fatigueModel->lookupHopMetricsForShipType($shipType, $distanceLy);
        return $metrics['jump_activation_minutes'];
    }

    private function cooldownCapPenaltyMinutes(array $jumpSegments, array $options): float
    {
        if ($jumpSegments === []) {
            return 0.0;
        }

        $fatigue = $this->fatigueModel->evaluateWithWaits($this->jumpSegments($jumpSegments), $options);
        $cooldowns = $fatigue['cooldowns_minutes'];
        $cap = (float) ($fatigue['caps']['max_cooldown_minutes'] ?? 30.0);
        $lastIndex = count($jumpSegments) - 1;
        $firstCapIndex = null;

        foreach ($cooldowns as $index => $cooldown) {
            if ($index >= $lastIndex) {
                continue;
            }
            if ((float) $cooldown >= $cap - 0.01) {
                $firstCapIndex = $index;
                break;
            }
        }

        if ($firstCapIndex === null) {
            return 0.0;
        }

        $capSegment = $jumpSegments[$firstCapIndex] ?? null;
        if ($capSegment === null) {
            return 0.0;
        }

        $toId = (int) ($capSegment['to_id'] ?? 0);
        $system = $toId > 0 ? ($this->systems[$toId] ?? null) : null;
        if ($system !== null && $this->isSafeWaitSystem($system)) {
            return 0.0;
        }

        $remainingJumps = max(0, $lastIndex - $firstCapIndex);
        return round($remainingJumps * 5.0, 2);
    }

    private function isSafeWaitSystem(array $system): bool
    {
        $npcCount = (int) ($system['npc_station_count'] ?? 0);
        $hasNpcStation = !empty($system['has_npc_station']) || $npcCount > 0;
        return $hasNpcStation || SecurityNav::isHighsec($system);
    }

    private function buildJumpWaitDetails(array $jumpSegments, array $options): array
    {
        if ($jumpSegments === []) {
            return [
                'jump_waits' => [],
                'total_wait_minutes' => 0.0,
                'wait_systems' => [],
                'wait_explanations' => [],
            ];
        }

        $fatigue = $this->fatigueModel->evaluateWithWaits($this->jumpSegments($jumpSegments), $options);
        $waits = $fatigue['waits_minutes'];
        $waitSystems = [];
        $waitExplanations = [];
        foreach ($jumpSegments as $index => $segment) {
            $wait = $waits[$index] ?? 0.0;
            if ($wait <= 0.0) {
                continue;
            }
            $toId = (int) ($segment['to_id'] ?? 0);
            if ($toId <= 0) {
                continue;
            }
            $systemName = (string) ($segment['to'] ?? $toId);
            $waitSystems[] = [
                'id' => $toId,
                'name' => $systemName,
            ];
            $waitExplanations[] = sprintf(
                'Wait %.1f min at %s due to jump activation cooldown.',
                $wait,
                $systemName
            );
        }

        return [
            'jump_waits' => $waits,
            'total_wait_minutes' => $fatigue['total_wait_minutes'],
            'wait_systems' => $waitSystems,
            'wait_explanations' => $waitExplanations,
        ];
    }

    /** @return array{slider_scalar: float, w_time: float, w_risk: float, w_pref: float} */
    private function scoringWeights(array $options): array
    {
        $safety = max(0, min(100, (int) ($options['safety_vs_speed'] ?? 50)));
        $s = $safety / 100.0;

        return [
            'slider_scalar' => round($s, 4),
            'w_time' => round(1.2 - (0.7 * $s), 4),
            'w_risk' => round(0.2 + (1.2 * $s), 4),
            'w_pref' => round(0.15 + (0.1 * $s), 4),
        ];
    }

    private function applyRouteScoring(array $route, array $weights, array $options): array
    {
        $route['time_cost'] = $this->routeTimeCost($route);
        $route['risk_cost'] = $this->routeRiskCost($route);
        $route['preference_cost'] = $this->routePreferenceCost($route, $options);
        $route['npc_bonus'] = $this->routeNpcBonus($route, $options);

        if (empty($route['feasible'])) {
            $route['total_cost'] = INF;
            return $route;
        }

        $weightedTotal = ($route['time_cost'] * (float) $weights['w_time'])
            + ($route['risk_cost'] * (float) $weights['w_risk'])
            + ($route['preference_cost'] * (float) $weights['w_pref'])
            + (float) $route['npc_bonus'];
        $route['total_cost'] = round($weightedTotal, 4);

        return $route;
    }

    private function withRouteDiagnostics(array $route, string $routeKey, array $weights, array $selection): array
    {
        $segments = $route['segments'] ?? [];
        $jumpHops = 0;
        if (is_array($segments)) {
            foreach ($segments as $segment) {
                if (($segment['type'] ?? 'gate') === 'jump') {
                    $jumpHops++;
                }
            }
        }

        $gateTimeMinutes = (float) max(0, (int) ($route['total_gates'] ?? 0));
        $jumpHandlingMinutes = (float) $jumpHops;
        $mandatoryWaitMinutes = max(0.0, (float) ($route['total_wait_minutes'] ?? 0.0));

        $extraGatePenalty = 0.0;
        $selectionPenalty = max(0.0, (float) ($route['selection_penalty'] ?? 0.0));
        $extraPenaltyRoutes = $selection['extra_gate_penalty']['penalty_routes'][$routeKey] ?? [];
        if (is_array($extraPenaltyRoutes)) {
            foreach ($extraPenaltyRoutes as $penaltyRow) {
                $extraGatePenalty += max(0.0, (float) ($penaltyRow['penalty'] ?? 0.0));
            }
        }

        $route['weights_used'] = [
            'w_time' => (float) ($weights['w_time'] ?? 0.0),
            'w_risk' => (float) ($weights['w_risk'] ?? 0.0),
            'w_pref' => (float) ($weights['w_pref'] ?? 0.0),
        ];
        $route['gate_time_minutes'] = round($gateTimeMinutes, 2);
        $route['jump_handling_minutes'] = round($jumpHandlingMinutes, 2);
        $route['mandatory_wait_minutes'] = round($mandatoryWaitMinutes, 2);
        $route['penalties_bonuses'] = [
            'npc_bonus' => round((float) ($route['npc_bonus'] ?? 0.0), 4),
            'selection_penalty' => round($selectionPenalty, 4),
            'extra_gate_penalty' => round($extraGatePenalty, 4),
            'cooldown_cap_penalty_minutes' => round((float) ($route['cooldown_cap_penalty_minutes'] ?? 0.0), 4),
        ];
        $route['dominance_flags'] = [
            'selected_as_best' => (string) ($selection['best'] ?? 'none') === $routeKey,
            'dominance_rule_applied' => !empty($selection['dominance_rule_applied']),
            'dominance_rule_winner' => (string) ($selection['best'] ?? 'none') === $routeKey
                && !empty($selection['dominance_rule_applied']),
            'extra_gate_penalty_applied' => $extraGatePenalty > 0.0,
        ];

        return $route;
    }

    private function routeTimeCost(array $route): float
    {
        $gates = max(0, (int) ($route['total_gates'] ?? 0));
        $jumpLy = max(0.0, (float) ($route['total_jump_ly'] ?? 0.0));
        $travelTime = $gates + $jumpLy;

        return round(min(1.0, $travelTime / 50.0), 4);
    }

    private function routeRiskCost(array $route): float
    {
        $systems = $route['systems'] ?? [];
        if (!is_array($systems) || $systems === []) {
            return 1.0;
        }

        return round(min(1.0, $this->routeSecurityPenalty($systems) / 100.0), 4);
    }

    private function routePreferenceCost(array $route, array $options): float
    {
        if (empty($options['prefer_npc'])) {
            return 0.0;
        }

        $ratio = (float) ($route['npc_station_ratio'] ?? 0.0);
        return round(max(0.0, 1.0 - $ratio), 4);
    }

    /**
     * @param array<string, array<string, mixed>> $feasibleRoutes
     * @return array<string, array<string, mixed>>
     */

    private function routeDominates(array $candidate, array $other, array $options, bool $preferJumpHopsPrimary): bool
    {
        $candidateJumpHops = (int) ($candidate['jump_hops'] ?? 0);
        $otherJumpHops = (int) ($other['jump_hops'] ?? 0);
        if ($preferJumpHopsPrimary && $candidateJumpHops !== $otherJumpHops) {
            return $candidateJumpHops < $otherJumpHops;
        }

        $candidateActions = (int) ($candidate['total_gates'] ?? 0) + $candidateJumpHops;
        $otherActions = (int) ($other['total_gates'] ?? 0) + (int) ($other['jump_hops'] ?? 0);
        if ($candidateActions !== $otherActions) {
            return $candidateActions < $otherActions;
        }

        $candidateLy = (float) ($candidate['total_jump_ly'] ?? 0.0);
        $otherLy = (float) ($other['total_jump_ly'] ?? 0.0);
        if (abs($candidateLy - $otherLy) > 1e-9) {
            return $candidateLy < $otherLy;
        }

        $candidateMaxHop = (float) ($candidate['max_jump_hop_ly'] ?? 0.0);
        $otherMaxHop = (float) ($other['max_jump_hop_ly'] ?? 0.0);
        if (abs($candidateMaxHop - $otherMaxHop) > 1e-9) {
            return $candidateMaxHop < $otherMaxHop;
        }

        if (!empty($options['fatigue_aware_routing'])) {
            $candidateFatigue = (float) (($candidate['fatigue']['jump_fatigue_minutes'] ?? 0.0));
            $otherFatigue = (float) (($other['fatigue']['jump_fatigue_minutes'] ?? 0.0));
            if (abs($candidateFatigue - $otherFatigue) > 1e-9) {
                return $candidateFatigue < $otherFatigue;
            }
        }

        return (float) ($candidate['time_cost'] ?? INF) <= (float) ($other['time_cost'] ?? INF);
    }

    private function normalizeRouteTotals(array $feasibleRoutes): array
    {
        $min = INF;
        $max = -INF;
        foreach ($feasibleRoutes as $route) {
            $total = (float) ($route['total_cost'] ?? INF);
            $min = min($min, $total);
            $max = max($max, $total);
        }

        if (!is_finite($min) || !is_finite($max)) {
            return $feasibleRoutes;
        }

        foreach ($feasibleRoutes as $key => $route) {
            $total = (float) ($route['total_cost'] ?? INF);
            $normalized = 0.0;
            if ($max > $min) {
                $normalized = ($total - $min) / ($max - $min);
            }
            $route['normalized_total_cost'] = round($normalized, 6);
            $feasibleRoutes[$key] = $route;
        }

        return $feasibleRoutes;
    }

    /**
     * @return array{
     *     best: string,
     *     reason: string,
     *     dominance_rule_applied: bool,
     *     extra_gate_penalty: array<string, mixed>
     * }
     */
    private function selectBestWithMetadata(array $gate, array $jump, array $hybrid, array $options): array
    {
        $routes = ['gate' => $gate, 'jump' => $jump, 'hybrid' => $hybrid];
        $feasible = [];
        foreach ($routes as $key => $route) {
            if (!empty($route['feasible'])) {
                $feasible[$key] = $route;
            }
        }

        if ($feasible === []) {
            return [
                'best' => 'none',
                'reason' => 'no_feasible_routes',
                'dominance_rule_applied' => false,
                'extra_gate_penalty' => [],
            ];
        }

        $safetyVsSpeed = max(0, min(100, (int) ($options['safety_vs_speed'] ?? 50)));
        $profile = strtolower((string) ($options['preference_profile'] ?? 'balanced'));
        $dominanceEnabled = !array_key_exists('dominance_rule_enabled', $options) || !empty($options['dominance_rule_enabled']);
        if ($dominanceEnabled && ($profile === 'speed' || $profile === 'balanced' || $safetyVsSpeed <= 50)) {
            $jumpRoute = $feasible['jump'] ?? null;
            if (is_array($jumpRoute)) {
                $hybridRoute = $feasible['hybrid'] ?? null;
                $dominatesHybrid = !is_array($hybridRoute)
                    || $this->routeDominates($jumpRoute, $hybridRoute, $options, true);
                if ($dominatesHybrid) {
                    return [
                        'best' => 'jump',
                        'reason' => 'jump_dominates_by_lexicographic_hops',
                        'dominance_rule_applied' => true,
                        'extra_gate_penalty' => [],
                    ];
                }
            }
        }

        $extraGatePenalty = [
            'applied' => false,
            'speed_leaning' => $safetyVsSpeed <= 25,
            'penalty_routes' => [],
            'similar_time_threshold' => 0.1,
            'min_extra_gates' => 2,
        ];

        if (!empty($options['prefer_npc']) && count($feasible) > 1) {
            $detourBudget = $this->npcDetourHopBudget($options);
            $minGates = INF;
            foreach ($feasible as $route) {
                $minGates = min($minGates, max(0, (int) ($route['total_gates'] ?? 0)));
            }
            foreach ($feasible as $routeKey => $route) {
                $routeGates = max(0, (int) ($route['total_gates'] ?? 0));
                $gateDelta = $routeGates - (int) $minGates;
                if ($gateDelta <= $detourBudget) {
                    continue;
                }

                $overBudget = $gateDelta - $detourBudget;
                $penalty = round(min(0.6, 0.5 * $overBudget), 4);
                if ($penalty <= 0.0) {
                    continue;
                }

                $existingPenalty = (float) ($feasible[$routeKey]['selection_penalty'] ?? 0.0);
                $feasible[$routeKey]['selection_penalty'] = round($existingPenalty + $penalty, 4);
                $feasible[$routeKey]['total_cost'] = round(((float) ($feasible[$routeKey]['total_cost'] ?? INF)) + $penalty, 4);
                $extraGatePenalty['applied'] = true;
                $extraGatePenalty['penalty_routes'][$routeKey][] = [
                    'vs_route' => 'detour_budget',
                    'gate_delta' => $gateDelta,
                    'time_delta' => 0.0,
                    'penalty' => $penalty,
                    'detour_budget' => $detourBudget,
                ];
            }
        }

        if ($safetyVsSpeed <= 25 && count($feasible) > 1) {
            $timeSimilarityThreshold = (float) $extraGatePenalty['similar_time_threshold'];
            $minExtraGates = (int) $extraGatePenalty['min_extra_gates'];
            foreach ($feasible as $routeKey => $route) {
                $routeTime = (float) ($route['time_cost'] ?? INF);
                $routeGates = max(0, (int) ($route['total_gates'] ?? 0));
                foreach ($feasible as $otherKey => $otherRoute) {
                    if ($routeKey === $otherKey) {
                        continue;
                    }
                    $otherTime = (float) ($otherRoute['time_cost'] ?? INF);
                    $otherGates = max(0, (int) ($otherRoute['total_gates'] ?? 0));
                    if (($routeGates - $otherGates) < $minExtraGates) {
                        continue;
                    }
                    if (($routeTime - $otherTime) > $timeSimilarityThreshold) {
                        continue;
                    }

                    $gateDelta = $routeGates - $otherGates;
                    $timeDelta = max(0.0, $routeTime - $otherTime);
                    $penalty = round(min(0.2, (0.03 * $gateDelta) + (0.02 * (1.0 - $timeDelta))), 4);
                    if ($penalty <= 0.0) {
                        continue;
                    }

                    $existingPenalty = (float) ($feasible[$routeKey]['selection_penalty'] ?? 0.0);
                    $feasible[$routeKey]['selection_penalty'] = round($existingPenalty + $penalty, 4);
                    $feasible[$routeKey]['total_cost'] = round(((float) ($feasible[$routeKey]['total_cost'] ?? INF)) + $penalty, 4);
                    $extraGatePenalty['applied'] = true;
                    $extraGatePenalty['penalty_routes'][$routeKey][] = [
                        'vs_route' => $otherKey,
                        'gate_delta' => $gateDelta,
                        'time_delta' => round($timeDelta, 4),
                        'penalty' => $penalty,
                    ];
                    break;
                }
            }
        }

        $normalized = $this->normalizeRouteTotals($feasible);
        $bestRoute = 'none';
        $bestCost = INF;
        foreach ($normalized as $key => $route) {
            $cost = (float) ($route['normalized_total_cost'] ?? INF);
            if ($cost < $bestCost) {
                $bestCost = $cost;
                $bestRoute = (string) $key;
            }
        }

        return [
            'best' => $bestRoute,
            'reason' => $extraGatePenalty['applied'] ? 'normalized_total_cost_with_extra_gate_penalty' : 'normalized_total_cost',
            'dominance_rule_applied' => false,
            'extra_gate_penalty' => $extraGatePenalty,
        ];
    }

    private function buildExplanation(string $best, array $gate, array $jump, array $hybrid, array $options, string $selectionReason): array
    {
        if ($best === 'none') {
            return ['No feasible routes found.'];
        }
        $reasons = [];
        if (in_array($selectionReason, ['jump_dominates_hybrid_time_threshold', 'jump_dominates_by_lexicographic_hops'], true)) {
            $reasons[] = 'Selected jump due to speed-leaning dominance rule over hybrid.';
        } elseif ($selectionReason === 'normalized_total_cost_with_extra_gate_penalty') {
            $reasons[] = sprintf('Selected %s with lowest normalized total cost after extra-gate penalty.', $best);
        } else {
            $reasons[] = sprintf('Selected %s with lowest normalized total cost.', $best);
        }
        if ($best === 'hybrid') {
            $reasons[] = 'Hybrid combines gates and jumps while respecting ship restrictions.';
        }
        if ($best === 'jump') {
            $reasons[] = 'Jump-only route minimizes gate usage for capital movement.';
        }
        if ($best === 'gate') {
            $reasons[] = 'Gate-only route avoids jump fatigue considerations.';
        }
        $selected = match ($best) {
            'gate' => $gate,
            'jump' => $jump,
            'hybrid' => $hybrid,
            default => [],
        };
        if (!empty($selected['fallback_used'])) {
            $reasons[] = 'Best effort route: strict avoid filters found no feasible path, so soft avoid penalties were used.';
        }
        if (!empty($options['prefer_npc'])) {
            $npcCount = (int) ($selected['npc_stations_in_route'] ?? 0);
            $reasons[] = sprintf('Selected %d systems with NPC stations (toggle enabled).', $npcCount);
        }
        return $reasons;
    }

    private function loadData(): void
    {
        GraphStore::load($this->systemsRepo, $this->stargatesRepo, $this->logger);
        $this->systems = GraphStore::systems();
        $this->gateNeighbors = GraphStore::gateNeighbors();
        $this->adjacency = GraphStore::adjacency();
        $this->reverseAdjacency = GraphStore::reverseAdjacency();

        $riskRows = $this->riskRepo->getHeatmap();
        $this->risk = [];
        foreach ($riskRows as $row) {
            $this->risk[(int) $row['system_id']] = $row;
        }

        $this->buildBaseCostProfiles();

        if ($this->constellationGraphRepo !== null) {
            try {
                $this->constellationEdges = $this->constellationGraphRepo->edgeMap();
                $this->constellationPortals = $this->constellationGraphRepo->portalsByConstellation();
                $this->constellationDistances = [];
                foreach (array_keys($this->constellationPortals) as $constellationId) {
                    $this->constellationDistances[(int) $constellationId] = $this->constellationGraphRepo->portalDistancesForConstellation((int) $constellationId);
                }
                $this->jumpConstellationEdgesByRange = [];
                $this->jumpConstellationPortalsByRange = [];
                $this->jumpMidpointsByRange = [];
                for ($range = 1; $range <= 10; $range++) {
                    $this->jumpConstellationEdgesByRange[$range] = $this->constellationGraphRepo->jumpEdgeMap($range);
                    $this->jumpConstellationPortalsByRange[$range] = $this->constellationGraphRepo->jumpPortalsByConstellation($range);
                    $this->jumpMidpointsByRange[$range] = $this->constellationGraphRepo->jumpMidpointsByConstellation($range);
                }
            } catch (\Throwable) {
                $this->constellationEdges = [];
                $this->constellationPortals = [];
                $this->constellationDistances = [];
                $this->jumpConstellationEdgesByRange = [];
                $this->jumpConstellationPortalsByRange = [];
                $this->jumpMidpointsByRange = [];
            }
        }
    }

    private function buildBaseCostProfiles(): void
    {
        $this->baseCostProfiles = [];
        foreach ($this->systems as $id => $system) {
            $security = SecurityNav::value($system);
            $npcCount = (int) ($system['npc_station_count'] ?? 0);
            $hasNpcStation = !empty($system['has_npc_station']) || $npcCount > 0;
            $this->baseCostProfiles[(int) $id] = [
                'risk_penalty' => $this->riskScorer->penalty($this->risk[(int) $id] ?? []),
                'security' => $security,
                'security_penalty' => $this->securityPenalty($security),
                'security_class' => $this->securityClass($security),
                'has_npc_station' => $hasNpcStation,
                'npc_station_count' => $npcCount,
            ];
        }
    }

    private function securityClass(float $security): string
    {
        if ($security < SecurityNav::LOW_SEC_MIN) {
            return 'null';
        }
        if ($security < SecurityNav::HIGH_SEC_MIN) {
            return 'low';
        }
        return 'high';
    }
}
