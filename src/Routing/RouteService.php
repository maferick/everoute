<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Risk\RiskRepository;
use Everoute\Security\Logger;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\StationRepository;
use Everoute\Universe\SystemRepository;

final class RouteService
{
    private array $systems = [];
    private array $risk = [];
    private array $chokepoints = [];
    private Graph $graph;

    public function __construct(
        private SystemRepository $systemsRepo,
        private StargateRepository $stargatesRepo,
        private StationRepository $stationsRepo,
        private RiskRepository $riskRepo,
        private WeightCalculator $calculator,
        private Logger $logger
    ) {
        $this->loadData();
    }

    public function refresh(): void
    {
        $this->loadData();
    }

    public function computeRoutes(array $options): array
    {
        $start = $this->systemsRepo->findByNameOrId($options['from']);
        $end = $this->systemsRepo->findByNameOrId($options['to']);

        if ($start === null || $end === null) {
            return ['error' => 'Unknown system'];
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

        return [
            'from' => $start,
            'to' => $end,
            'routes' => $results,
            'risk_updated_at' => $this->riskRepo->getLatestUpdate(),
        ];
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
        $dijkstra = new Dijkstra();
        $avoidLow = $options['avoid_lowsec'] ?? false;
        $avoidNull = $options['avoid_nullsec'] ?? false;
        $avoidSystems = $options['avoid_systems'] ?? [];
        $avoidSet = array_fill_keys($avoidSystems, true);

        $costFn = function (int $from, int $to) use ($options, $weights, $avoidLow, $avoidNull, $avoidSet): float {
            $system = $this->systems[$to] ?? null;
            if ($system === null) {
                return INF;
            }

            $security = (float) ($system['security'] ?? 0);
            if ($avoidNull && $security < 0.1) {
                return INF;
            }
            if ($avoidLow && $security >= 0.1 && $security < 0.5) {
                return INF;
            }
            if (isset($avoidSet[$system['name']])) {
                return INF;
            }

            $risk = $this->risk[$to] ?? [];
            $isChokepoint = isset($this->chokepoints[$to]);
            $hasNpc = $this->stationsRepo->hasNpcStation($to);
            $costs = $this->calculator->cost($system, $risk, $isChokepoint, $hasNpc, $options);

            return $costs['travel'] * $weights['travel_weight']
                + $costs['risk'] * $weights['risk_weight']
                + $costs['exposure'] * $weights['exposure_weight']
                + $costs['infrastructure'];
        };

        $pathResult = $dijkstra->shortestPath($this->graph, $startId, $endId, $costFn);

        if (empty($pathResult['path'])) {
            return ['error' => 'No route found'];
        }

        $summary = $this->summarizeRoute($pathResult['path'], $options);
        $why = $this->explainRoute($pathResult['path'], $startId, $endId, $options);

        return array_merge($summary, [
            'why' => $why,
            'midpoints' => $this->suggestMidpoints($pathResult['path']),
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
            $hasNpc = $this->stationsRepo->hasNpcStation($systemId);
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
        $dijkstra = new Dijkstra();
        $result = $dijkstra->shortestPath($this->graph, $startId, $endId, static fn () => 1.0);
        return $result['path'] ?? [];
    }

    private function suggestMidpoints(array $path): array
    {
        if (count($path) < 3) {
            return [];
        }
        $midIndex = (int) floor(count($path) / 2);
        $candidateIds = array_slice($path, max(1, $midIndex - 5), 10);
        $stations = $this->stationsRepo->listNpcStationsBySystems($candidateIds);
        $candidates = [];
        foreach ($candidateIds as $systemId) {
            if (!isset($stations[$systemId])) {
                continue;
            }
            $risk = $this->risk[$systemId] ?? [];
            $riskScore = ($risk['kills_last_24h'] ?? 0) + ($risk['pod_kills_last_24h'] ?? 0);
            $candidates[] = [
                'system_id' => $systemId,
                'system_name' => $this->systems[$systemId]['name'],
                'npc_stations' => $stations[$systemId],
                'risk_score' => $riskScore,
            ];
        }

        usort($candidates, static fn ($a, $b) => $a['risk_score'] <=> $b['risk_score']);
        return array_slice($candidates, 0, 5);
    }

    private function loadData(): void
    {
        $this->systems = [];
        foreach ($this->systemsRepo->listAll() as $system) {
            $this->systems[(int) $system['id']] = $system;
        }

        $this->risk = [];
        foreach ($this->riskRepo->getHeatmap() as $row) {
            $this->risk[(int) $row['system_id']] = $row;
        }

        $this->chokepoints = array_fill_keys($this->riskRepo->listChokepoints(), true);

        $this->graph = new Graph();
        foreach ($this->stargatesRepo->allEdges() as $edge) {
            $this->graph->addEdge((int) $edge['from_system_id'], (int) $edge['to_system_id']);
        }

        $this->logger->info('Route data loaded', ['systems' => count($this->systems)]);
    }
}
