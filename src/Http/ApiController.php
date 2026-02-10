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

use Everoute\Config\Env;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpMath;
use Everoute\Routing\PreferenceProfile;
use Everoute\Routing\RouteRequest;
use Everoute\Routing\RouteService;
use Everoute\Routing\ShipProfile;
use Everoute\Security\RateLimiter;
use Everoute\Security\Validator;
use Everoute\Universe\SystemRepository;

final class ApiController
{
    public function __construct(
        private RouteService $routes,
        private RiskRepository $risk,
        private SystemRepository $systems,
        private Validator $validator,
        private RateLimiter $rateLimiter
    ) {
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

        $body = $request->body;
        $from = $this->validator->string($body['from_id'] ?? $body['from'] ?? null);
        $to = $this->validator->string($body['to_id'] ?? $body['to'] ?? null);
        if ($from === null || $to === null) {
            return new JsonResponse(['error' => 'Invalid from/to'], 422);
        }

        $mode = $this->validator->enum((string) ($body['mode'] ?? ShipProfile::DEFAULT_MODE), ['hauling', 'subcap', 'capital'], ShipProfile::DEFAULT_MODE);
        $shipClass = $this->validator->enum((string) ($body['ship_class'] ?? 'subcap'), [
            'interceptor',
            'subcap',
            'dst',
            'freighter',
            'capital',
            'jump_freighter',
            'super',
            'titan',
        ], 'subcap');
        $jumpShipType = $this->validator->string($body['jump_ship_type'] ?? null);
        $jumpSkillLevel = $this->validator->int($body['jump_skill_level'] ?? null, 0, 5, 5);

        $isCapitalRequest = in_array($shipClass, ['capital', 'jump_freighter', 'super', 'titan'], true);
        $safety = $this->validator->int($body['safety_vs_speed'] ?? null, 0, 100, $isCapitalRequest ? 70 : 50);
        $requestedProfile = $this->validator->enum(
            strtolower((string) ($body['preference_profile'] ?? $body['profile'] ?? '')),
            [PreferenceProfile::SPEED, PreferenceProfile::BALANCED, PreferenceProfile::SAFETY],
            ''
        );
        $preference = $this->validator->enum((string) ($body['preference'] ?? 'shorter'), ['shorter', 'safer', 'less_secure'], 'shorter');

        $avoidLowsec = $this->validator->bool($body['avoid_lowsec'] ?? null, false);
        $avoidNullsec = $this->validator->bool($body['avoid_nullsec'] ?? null, false);
        $preferNpc = $this->validator->bool($body['prefer_npc_stations'] ?? null, $isCapitalRequest);
        $allowGateReposition = $this->validator->bool($body['allow_gate_reposition'] ?? null, true);
        $hybridGateBudgetMax = $this->validator->int($body['hybrid_gate_budget_max'] ?? null, 2, 12, 8);

        $fuelPerLyFactorRaw = $body['fuel_per_ly_factor'] ?? (($body['ship_profile']['fuel_per_ly_factor'] ?? null));
        $fuelPerLyFactor = is_numeric($fuelPerLyFactorRaw) ? (float) $fuelPerLyFactorRaw : null;

        $shipProfile = ShipProfile::create(
            $mode,
            $shipClass,
            $jumpShipType,
            $jumpSkillLevel,
            $fuelPerLyFactor,
            null
        );

        $routeRequest = RouteRequest::create(
            $from,
            $to,
            $shipProfile,
            PreferenceProfile::create($requestedProfile !== '' ? $requestedProfile : null, $safety),
            $preference,
            $avoidLowsec,
            $avoidNullsec,
            $this->validator->enum(
                strtolower((string) ($body['avoid_strictness'] ?? '')),
                ['soft', 'strict'],
                ''
            ),
            isset($body['avoid_specific_systems']) ? $this->validator->list((string) $body['avoid_specific_systems']) : [],
            $preferNpc,
            $allowGateReposition,
            $hybridGateBudgetMax
        );

        $result = $this->routes->computeRoutes($routeRequest);
        if (isset($result['error'])) {
            $status = $result['error'] === 'not_feasible' ? 422 : 404;
            return new JsonResponse($result, $status);
        }

        $selectedPolicy = $routeRequest->selectedPolicy();
        $result['risk_updated_at'] = $this->risk->getLatestUpdate(Env::get('RISK_PROVIDER', 'esi_system_kills'));
        $result['selected_policy'] = $selectedPolicy;
        $result['explanation'][] = sprintf(
            'NPC detour policy: %s side at %d%% (%s).',
            $selectedPolicy['slider_side'],
            $routeRequest->preferenceProfile->safetyVsSpeed,
            $selectedPolicy['npc_detour_note']
        );
        if (isset($result['debug']) && is_array($result['debug'])) {
            $result['debug']['selected_policy'] = $selectedPolicy;
        }

        return new JsonResponse($result);
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
