<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Config\Env;
use Everoute\Risk\RiskRepository;
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

    public function __construct(
        private SystemRepository $systemsRepo,
        private StargateRepository $stargatesRepo,
        private JumpNeighborRepository $jumpNeighborRepo,
        private RiskRepository $riskRepo,
        private JumpRangeCalculator $jumpRangeCalculator,
        private JumpFatigueModel $fatigueModel,
        private ShipRules $shipRules,
        private Logger $logger
    ) {
        $this->loadData();
    }

    public function refresh(): void
    {
        GraphStore::refresh($this->systemsRepo, $this->stargatesRepo, $this->logger);
        $this->loadData();
    }

    public function compute(array $options): array
    {
        $start = GraphStore::systemByNameOrId($options['from']);
        $end = GraphStore::systemByNameOrId($options['to']);

        if ($start === null || $end === null) {
            return ['error' => 'Unknown system'];
        }

        $shipType = $this->shipRules->normalizeShipType((string) ($options['jump_ship_type'] ?? ''));
        $jumpSkillLevel = (int) ($options['jump_skill_level'] ?? 0);
        $effectiveRange = $this->jumpRangeCalculator->effectiveRange($shipType, $jumpSkillLevel);
        $rangeBucket = $effectiveRange !== null ? (int) floor($effectiveRange) : null;
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
        $explanation = $this->buildExplanation($best, $gateRoute, $jumpRoute, $hybridRoute);
        $payload = [
            'gate_route' => $gateRoute,
            'jump_route' => $jumpRoute,
            'hybrid_route' => $hybridRoute,
            'best' => $best,
            'explanation' => $explanation,
        ];

        if ($debugEnabled) {
            $payload['debug'] = [
                'effective_range_ly' => $effectiveRange,
                'range_bucket' => $rangeBucket,
                'gate_nodes_explored' => $gateRoute['nodes_explored'] ?? 0,
                'jump_nodes_explored' => $jumpRoute['nodes_explored'] ?? 0,
                'hybrid_nodes_explored' => $hybridRoute['nodes_explored'] ?? 0,
                'illegal_systems_filtered' => [
                    'gate' => $gateRoute['illegal_systems_filtered'] ?? 0,
                    'jump' => $jumpRoute['illegal_systems_filtered'] ?? 0,
                    'hybrid' => $hybridRoute['illegal_systems_filtered'] ?? 0,
                ],
            ];
        }

        return $payload;
    }

    private function computeGateRoute(int $startId, int $endId, string $shipType, array $options): array
    {
        if ($startId === $endId) {
            return [
                'feasible' => true,
                'total_cost' => 0.0,
                'total_gates' => 0,
                'total_jump_ly' => 0.0,
                'segments' => [],
                'systems' => [[
                    'id' => $startId,
                    'name' => $this->systems[$startId]['name'] ?? (string) $startId,
                    'security' => (float) ($this->systems[$startId]['security'] ?? 0.0),
                ]],
                'nodes_explored' => 0,
                'illegal_systems_filtered' => 0,
            ];
        }
        $graph = $this->buildGateGraph($shipType, $options);
        if (!isset($graph['neighbors'][$startId]) || !isset($graph['neighbors'][$endId])) {
            return [
                'feasible' => false,
                'reason' => 'Start or destination not allowed for gate travel.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => $graph['filtered'],
            ];
        }

        $dijkstra = new Dijkstra();
        $this->riskWeightCache = $this->riskWeight($options);
        $result = $dijkstra->shortestPath(
            $graph['neighbors'],
            $startId,
            $endId,
            function (int $from, int $to): float {
                $riskScore = $this->riskScore($to);
                return 1.0 + ($riskScore * $this->riskWeightCache);
            },
            null,
            null,
            50000
        );

        if ($result['path'] === [] || ($result['path'][count($result['path']) - 1] ?? null) !== $endId) {
            return [
                'feasible' => false,
                'reason' => 'No gate route found.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $graph['filtered'],
            ];
        }

        $segments = $this->buildSegments($result['path'], $result['edges'], $shipType);
        if (!$this->validateRoute($segments, $shipType, null)) {
            return [
                'feasible' => false,
                'reason' => 'Gate route failed validation.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $graph['filtered'],
            ];
        }

        $summary = $this->summarizeRoute($segments, $result['distance']);
        $summary['nodes_explored'] = $result['nodes_explored'];
        $summary['illegal_systems_filtered'] = $graph['filtered'];
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
        if ($startId === $endId) {
            return [
                'feasible' => true,
                'total_cost' => 0.0,
                'total_gates' => 0,
                'total_jump_ly' => 0.0,
                'segments' => [],
                'systems' => [[
                    'id' => $startId,
                    'name' => $this->systems[$startId]['name'] ?? (string) $startId,
                    'security' => (float) ($this->systems[$startId]['security'] ?? 0.0),
                ]],
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

        $graph = $this->buildJumpGraph($neighbors, $startId, $endId, $shipType, $options);
        if (!isset($graph['neighbors'][$startId]) || !isset($graph['neighbors'][$endId])) {
            return [
                'feasible' => false,
                'reason' => 'Start or destination not allowed for jumping.',
                'nodes_explored' => 0,
                'illegal_systems_filtered' => $graph['filtered'],
            ];
        }

        $dijkstra = new Dijkstra();
        $this->riskWeightCache = $this->riskWeight($options);
        $result = $dijkstra->shortestPath(
            $graph['neighbors'],
            $startId,
            $endId,
            function (int $from, int $to, mixed $edgeData): float {
                $distance = (float) ($edgeData['distance_ly'] ?? 0.0);
                $fatigue = 5.0 + ($distance * 6.0);
                $cooldown = max(1.0, $distance * 1.0);
                $riskScore = $this->riskScore($to);
                return $distance + $fatigue + $cooldown + ($riskScore * $this->riskWeightCache);
            },
            null,
            null,
            50000
        );

        if ($result['path'] === [] || ($result['path'][count($result['path']) - 1] ?? null) !== $endId) {
            return [
                'feasible' => false,
                'reason' => 'No jump route found.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $graph['filtered'],
            ];
        }

        $segments = $this->buildSegments($result['path'], $result['edges'], $shipType);
        if (!$this->validateRoute($segments, $shipType, $effectiveRange)) {
            return [
                'feasible' => false,
                'reason' => 'Jump route failed validation.',
                'nodes_explored' => $result['nodes_explored'],
                'illegal_systems_filtered' => $graph['filtered'],
            ];
        }

        $summary = $this->summarizeRoute($segments, $result['distance']);
        $summary['nodes_explored'] = $result['nodes_explored'];
        $summary['illegal_systems_filtered'] = $graph['filtered'];
        $summary['fatigue'] = $this->fatigueModel->evaluate($this->jumpSegments($segments));
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
        if ($startId === $endId) {
            return [
                'feasible' => true,
                'total_cost' => 0.0,
                'total_gates' => 0,
                'total_jump_ly' => 0.0,
                'segments' => [],
                'systems' => [[
                    'id' => $startId,
                    'name' => $this->systems[$startId]['name'] ?? (string) $startId,
                    'security' => (float) ($this->systems[$startId]['security'] ?? 0.0),
                ]],
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

        $gateGraph = $this->buildGateGraph($shipType, $options);
        $jumpGraph = $this->buildJumpGraph($neighbors, $startId, $endId, $shipType, $options);
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
            function (int $from, int $to, mixed $edgeData): float {
                $edgeType = $edgeData['type'] ?? 'gate';
                if ($edgeType === 'jump') {
                    $distance = (float) ($edgeData['distance_ly'] ?? 0.0);
                    $fatigue = 5.0 + ($distance * 6.0);
                    $cooldown = max(1.0, $distance * 1.0);
                    $riskScore = $this->riskScore($to);
                    return $distance + $fatigue + $cooldown + ($riskScore * $this->riskWeightCache);
                }

                $riskScore = $this->riskScore($to);
                return 1.0 + ($riskScore * $this->riskWeightCache);
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
    private function buildGateGraph(string $shipType, array $options): array
    {
        $neighbors = [];
        $filtered = 0;
        foreach ($this->gateNeighbors as $from => $toList) {
            if (!$this->isSystemAllowedForRoute($shipType, $this->systems[$from], false, $options)) {
                $filtered++;
                continue;
            }
            foreach ($toList as $to) {
                if (!$this->isSystemAllowedForRoute($shipType, $this->systems[$to], false, $options)) {
                    $filtered++;
                    continue;
                }
                $neighbors[$from][] = [
                    'to' => $to,
                    'type' => 'gate',
                ];
            }
        }

        return ['neighbors' => $neighbors, 'filtered' => $filtered];
    }

    /** @param array<int, int[]> $precomputed */
    private function buildJumpGraph(array $precomputed, int $startId, int $endId, string $shipType, array $options): array
    {
        $neighbors = [];
        $filtered = 0;
        foreach ($precomputed as $from => $toList) {
            if (!isset($this->systems[$from])) {
                continue;
            }
            if (!$this->isSystemAllowedForRoute($shipType, $this->systems[$from], false, $options)) {
                $filtered++;
                continue;
            }
            foreach ($toList as $to) {
                if (!isset($this->systems[$to])) {
                    continue;
                }
                $isEndpoint = $to === $endId;
                if (!$this->isSystemAllowedForRoute($shipType, $this->systems[$to], !$isEndpoint, $options)) {
                    $filtered++;
                    continue;
                }
                $neighbors[$from][] = [
                    'to' => $to,
                    'type' => 'jump',
                    'distance_ly' => JumpMath::distanceLy($this->systems[$from], $this->systems[$to]),
                ];
            }
        }

        return ['neighbors' => $neighbors, 'filtered' => $filtered];
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
        if (!empty($options['avoid_nullsec']) && $security < 0.1) {
            return false;
        }
        if (!empty($options['avoid_lowsec']) && $security >= 0.1 && $security < 0.5) {
            return false;
        }
        if (!empty($options['avoid_systems']) && in_array($system['name'], (array) $options['avoid_systems'], true)) {
            return false;
        }
        return true;
    }

    private function riskScore(int $systemId): float
    {
        $risk = $this->risk[$systemId] ?? [];
        return (float) (($risk['kills_last_24h'] ?? 0) + ($risk['pod_kills_last_24h'] ?? 0));
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
                $startSystem = $this->systems[$startId];
                $systems[] = [
                    'id' => $startId,
                    'name' => $startSystem['name'],
                    'security' => (float) $startSystem['security'],
                ];
            }
        }
        $totalJumpLy = 0.0;
        $gateHops = 0;
        foreach ($segments as $segment) {
            $toId = $segment['to_id'];
            $system = $this->systems[$toId] ?? null;
            if ($system) {
                $systems[] = [
                    'id' => $toId,
                    'name' => $system['name'],
                    'security' => (float) $system['security'],
                ];
            }
            if (($segment['type'] ?? 'gate') === 'jump') {
                $totalJumpLy += (float) ($segment['distance_ly'] ?? 0.0);
            } else {
                $gateHops++;
            }
        }
        return [
            'feasible' => true,
            'total_cost' => round($distance, 2),
            'total_gates' => $gateHops,
            'total_jump_ly' => round($totalJumpLy, 2),
            'segments' => $segments,
            'systems' => $systems,
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

    private function buildExplanation(string $best, array $gate, array $jump, array $hybrid): array
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
