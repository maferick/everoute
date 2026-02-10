<?php

declare(strict_types=1);

namespace Everoute\Http;

if (!class_exists(\Everoute\Routing\PreferenceProfile::class)) {
    require_once __DIR__ . '/../Routing/PreferenceProfile.php';
}
if (!class_exists(\Everoute\Routing\ShipProfile::class)) {
    require_once __DIR__ . '/../Routing/ShipProfile.php';
}
if (!class_exists(\Everoute\Routing\RouteRequest::class)) {
    require_once __DIR__ . '/../Routing/RouteRequest.php';
}
if (!class_exists(\Everoute\Routing\RoutePlanner::class)) {
    require_once __DIR__ . '/../Routing/RoutePlanner.php';
}
if (!class_exists(\Everoute\Routing\RouteRequestFactory::class)) {
    require_once __DIR__ . '/../Routing/RouteRequestFactory.php';
}

use Everoute\Config\Env;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpMath;
use Everoute\Routing\RouteJobRepository;
use Everoute\Routing\RoutePlanner;
use Everoute\Routing\RouteRequestFactory;
use Everoute\Security\RateLimiter;
use Everoute\Security\Validator;
use Everoute\Universe\SystemRepository;

final class ApiController
{
    public function __construct(
        private RoutePlanner|\Everoute\Routing\RouteService $planner,
        private RiskRepository $risk,
        private SystemRepository $systems,
        private Validator $validator,
        private RateLimiter $rateLimiter,
        private ?RouteRequestFactory $requestFactory = null,
        private ?RouteJobRepository $routeJobs = null
    ) {
        if ($this->planner instanceof \Everoute\Routing\RouteService) {
            $this->planner = new RoutePlanner($this->planner, $this->risk);
        }
        $this->requestFactory ??= new RouteRequestFactory($this->validator);
    }

    public function health(Request $request): Response
    {
        $provider = Env::get('RISK_PROVIDER', 'esi_system_kills');
        $riskUpdated = $this->risk->getLatestUpdate($provider);
        $ingestLastSeen = $this->risk->getIngestLastSeen();
        $ingestRunning = null;
        if ($ingestLastSeen !== null) {
            try {
                $lastSeen = new \DateTimeImmutable($ingestLastSeen);
                $ingestRunning = (time() - $lastSeen->getTimestamp()) <= 120;
            } catch (\Exception) {
                $ingestRunning = null;
            }
        }

        return new JsonResponse([
            'status' => 'ok',
            'time' => gmdate('c'),
            'risk_provider' => $provider,
            'risk_updated_at' => $riskUpdated,
            'risk_ingest_last_seen' => $ingestLastSeen,
            'risk_ingest_running' => $ingestRunning,
        ]);
    }

    public function route(Request $request): Response
    {
        if (!$this->rateLimiter->allow('route:' . $request->ip)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        try {
            ['request' => $routeRequest] = $this->requestFactory->fromBody($request->body);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid from/to'], 422);
        }

        $result = $this->planner->compute($routeRequest);
        if (isset($result['error'])) {
            $status = $result['error'] === 'not_feasible' ? 422 : 404;
            return new JsonResponse($result, $status);
        }

        return new JsonResponse($result);
    }

    public function createRouteJob(Request $request): Response
    {
        if ($this->routeJobs === null) {
            return new JsonResponse(['error' => 'route_jobs_unavailable'], 503);
        }
        if (!$this->rateLimiter->allow('route_jobs:' . $request->ip)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        try {
            ['payload' => $payload] = $this->requestFactory->fromBody($request->body);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid from/to'], 422);
        }

        $jobId = $this->routeJobs->create($payload, Env::int('ROUTE_JOB_TTL_MINUTES', 15));

        return new JsonResponse([
            'job_id' => $jobId,
            'status' => 'queued',
            'poll_url' => '/api/v1/route-jobs/' . $jobId,
        ], 202);
    }

    public function getRouteJob(Request $request): Response
    {
        if ($this->routeJobs === null) {
            return new JsonResponse(['error' => 'route_jobs_unavailable'], 503);
        }

        $jobId = (string) ($request->params['job_id'] ?? '');
        $job = $this->routeJobs->get($jobId);
        if ($job === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        return new JsonResponse([
            'job_id' => $job['id'],
            'status' => $job['status'],
            'progress' => !empty($job['progress_json']) ? json_decode((string) $job['progress_json'], true) : null,
            'result' => $job['status'] === 'done' && !empty($job['result_json']) ? json_decode((string) $job['result_json'], true) : null,
            'error' => $job['status'] === 'failed' ? $job['error_text'] : null,
        ]);
    }

    public function cancelRouteJob(Request $request): Response
    {
        if ($this->routeJobs === null) {
            return new JsonResponse(['error' => 'route_jobs_unavailable'], 503);
        }

        $jobId = (string) ($request->params['job_id'] ?? '');
        return new JsonResponse(['job_id' => $jobId, 'canceled' => $this->routeJobs->cancel($jobId)]);
    }


    public function jumpDistanceDiagnostics(Request $request): Response
    {
        $pairs = [
            ['from' => '1-SMEB', 'to' => 'Irmalin'],
            ['from' => '1-SMEB', 'to' => 'RCI-VL'],
            ['from' => '1-SMEB', 'to' => 'Sakht'],
        ];

        $payload = [];
        foreach ($pairs as $pair) {
            $from = $this->systems->findByNameOrId($pair['from']);
            $to = $this->systems->findByNameOrId($pair['to']);
            $key = $pair['from'] . ' -> ' . $pair['to'];
            if ($from === null || $to === null) {
                $payload[$key] = ['available' => false, 'distance_ly' => null];
                continue;
            }
            $payload[$key] = [
                'available' => true,
                'distance_ly' => round(JumpMath::distanceLy($from, $to), 6),
                'within_7_ly' => JumpMath::distanceLy($from, $to) <= 7.0,
            ];
        }

        return new JsonResponse(['distances' => $payload]);
    }


    public function jumpGraphDiagnostics(Request $request): Response
    {
        $from = $this->validator->string($request->query['from'] ?? '1-SMEB') ?? '1-SMEB';
        $to = $this->validator->string($request->query['to'] ?? 'Amamake') ?? 'Amamake';
        $jumpShipType = $this->validator->string($request->query['jump_ship_type'] ?? 'carrier') ?? 'carrier';
        $jumpSkillLevel = $this->validator->int($request->query['jump_skill_level'] ?? null, 0, 5, 5);
        $gateBudget = $this->validator->int($request->query['hybrid_gate_budget_max'] ?? null, 0, 12, 8);

        ['request' => $routeRequest] = $this->requestFactory->fromBody([
            'from' => $from,
            'to' => $to,
            'mode' => 'capital',
            'ship_class' => 'capital',
            'jump_ship_type' => $jumpShipType,
            'jump_skill_level' => $jumpSkillLevel,
            'allow_gate_reposition' => true,
            'hybrid_gate_budget_max' => $gateBudget,
            'prefer_npc_stations' => false,
        ]);

        $result = $this->planner->compute($routeRequest);

        $debug = is_array($result['debug'] ?? null) ? $result['debug'] : [];
        $jumpRoute = is_array($result['jump_route'] ?? null) ? $result['jump_route'] : [];
        $hybridRoute = is_array($result['hybrid_route'] ?? null) ? $result['hybrid_route'] : [];

        return new JsonResponse([
            'from' => $from,
            'to' => $to,
            'jump_ship_type' => $jumpShipType,
            'jump_skill_level' => $jumpSkillLevel,
            'hybrid_gate_budget_max' => $gateBudget,
            'min_hops_found' => $jumpRoute['min_hops_found'] ?? ($debug['jump_connectivity']['min_hops_found'] ?? null),
            'jump_connectivity' => $debug['jump_connectivity'] ?? ($jumpRoute['jump_connectivity'] ?? []),
            'jump_neighbor_debug' => $debug['jump_neighbor_debug'] ?? ($jumpRoute['jump_neighbor_debug'] ?? []),
            'hybrid_candidate_debug' => $debug['hybrid_candidate_debug'] ?? ($hybridRoute['hybrid_candidate_debug'] ?? []),
            'hybrid_launch_candidates' => $debug['hybrid_launch_candidates'] ?? ($hybridRoute['hybrid_launch_candidates'] ?? 0),
        ]);
    }

    public function systemSearch(Request $request): Response
    {
        if (!$this->rateLimiter->allow('systems:' . $request->ip)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        $query = $this->validator->string($request->query['q'] ?? null) ?? '';
        $limit = $this->validator->int($request->query['limit'] ?? null, 1, 25, 10);
        $rows = $this->systems->searchByName($query, $limit);

        $payload = array_map(static fn (array $row) => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'sec_nav' => isset($row['security_nav']) ? (float) $row['security_nav'] : (float) $row['security'],
            'sec_raw' => isset($row['security_raw']) ? (float) $row['security_raw'] : null,
            'region' => $row['region_id'] ?? null,
        ], $rows);

        return new JsonResponse($payload);
    }

    public function systemRisk(Request $request): Response
    {
        if (!$this->rateLimiter->allow('risk:' . $request->ip)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        $systemValue = $request->query['system'] ?? null;
        $systemValue = $this->validator->string($systemValue);
        if ($systemValue === null) {
            return new JsonResponse(['error' => 'Invalid system'], 422);
        }

        $system = $this->systems->findByNameOrId($systemValue);
        if ($system === null) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $risk = $this->risk->getSystemRisk((int) $system['id']);
        return new JsonResponse([
            'system' => $system,
            'risk' => $risk,
        ]);
    }

    public function heatmap(Request $request): Response
    {
        if (!$this->rateLimiter->allow('heatmap:' . $request->ip)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        return new JsonResponse([
            'updated_at' => $this->risk->getLatestUpdate(),
            'systems' => $this->risk->getHeatmap(),
        ]);
    }
}
