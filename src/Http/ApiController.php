<?php

declare(strict_types=1);

namespace Everoute\Http;

use Everoute\Config\Env;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpShipType;
use Everoute\Routing\RouteService;
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

        $mode = $this->validator->enum((string) ($body['mode'] ?? 'subcap'), ['hauling', 'subcap', 'capital'], 'subcap');
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
        if ($jumpShipType === null || $jumpShipType === '') {
            $jumpShipType = $shipClass === 'jump_freighter' ? JumpShipType::JUMP_FREIGHTER : JumpShipType::CARRIER;
        }
        if (in_array($shipClass, ['capital', 'jump_freighter', 'super', 'titan'], true)) {
            $mode = 'capital';
        }
        $safety = $this->validator->int($body['safety_vs_speed'] ?? null, 0, 100, $mode === 'capital' ? 70 : 50);
        $preference = $this->validator->enum((string) ($body['preference'] ?? 'shorter'), ['shorter', 'safer', 'less_secure'], 'shorter');
        $jumpSkillLevel = $this->validator->int($body['jump_skill_level'] ?? null, 0, 5, 5);

        $options = [
            'from' => $from,
            'to' => $to,
            'mode' => $mode,
            'ship_class' => $shipClass,
            'jump_ship_type' => $jumpShipType,
            'jump_skill_level' => $jumpSkillLevel,
            'safety_vs_speed' => $safety,
            'preference' => $preference,
            'avoid_lowsec' => $this->validator->bool($body['avoid_lowsec'] ?? null, false),
            'avoid_nullsec' => $this->validator->bool($body['avoid_nullsec'] ?? null, false),
            'avoid_systems' => isset($body['avoid_specific_systems']) ? $this->validator->list((string) $body['avoid_specific_systems']) : [],
            'prefer_npc' => $this->validator->bool($body['prefer_npc_stations'] ?? null, $mode === 'capital'),
            'ship_modifier' => $this->shipModifier($shipClass),
        ];

        $result = $this->routes->computeRoutes($options);
        if (isset($result['error'])) {
            $status = $result['error'] === 'not_feasible' ? 422 : 404;
            return new JsonResponse($result, $status);
        }

        $result['risk_updated_at'] = $this->risk->getLatestUpdate(Env::get('RISK_PROVIDER', 'esi_system_kills'));
        return new JsonResponse($result);
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

    private function shipModifier(string $shipClass): float
    {
        return match ($shipClass) {
            'interceptor' => 0.4,
            'dst' => 1.4,
            'freighter' => 1.8,
            'capital' => 2.2,
            'jump_freighter' => 2.0,
            default => 1.0,
        };
    }
}
