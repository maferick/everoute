<?php

declare(strict_types=1);

namespace Everoute\Http;

use Everoute\Risk\RiskRepository;
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
        return new JsonResponse(['status' => 'ok', 'time' => gmdate('c')]);
    }

    public function route(Request $request): Response
    {
        if (!$this->rateLimiter->allow('route:' . $request->ip)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        $body = $request->body;
        $from = $this->validator->string($body['from'] ?? null);
        $to = $this->validator->string($body['to'] ?? null);
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
        ], 'subcap');

        $safety = $this->validator->int($body['safety_vs_speed'] ?? null, 0, 100, $mode === 'capital' ? 70 : 50);

        $options = [
            'from' => $from,
            'to' => $to,
            'mode' => $mode,
            'ship_class' => $shipClass,
            'safety_vs_speed' => $safety,
            'avoid_lowsec' => $this->validator->bool($body['avoid_lowsec'] ?? null, false),
            'avoid_nullsec' => $this->validator->bool($body['avoid_nullsec'] ?? null, false),
            'avoid_systems' => isset($body['avoid_specific_systems']) ? $this->validator->list((string) $body['avoid_specific_systems']) : [],
            'prefer_npc' => $this->validator->bool($body['prefer_npc_stations'] ?? null, $mode === 'capital'),
            'ship_modifier' => $this->shipModifier($shipClass),
        ];

        $result = $this->routes->computeRoutes($options);
        if (isset($result['error'])) {
            return new JsonResponse($result, 404);
        }

        return new JsonResponse($result);
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
