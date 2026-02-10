<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Config\Env;
use Everoute\Risk\RiskRepository;

final class RoutePlanner
{
    public function __construct(private RouteService $routes, private RiskRepository $risk)
    {
    }

    /** @return array<string,mixed> */
    public function compute(RouteRequest $request): array
    {
        $result = $this->routes->computeRoutes($request);
        if (isset($result['error'])) {
            return $result;
        }

        $selectedPolicy = $request->selectedPolicy();
        $result['risk_updated_at'] = $this->risk->getLatestUpdate(Env::get('RISK_PROVIDER', 'esi_system_kills'));
        $result['selected_policy'] = $selectedPolicy;
        $result['explanation'][] = sprintf(
            'NPC detour policy: %s side at %d%% (%s).',
            $selectedPolicy['slider_side'],
            $request->preferenceProfile->safetyVsSpeed,
            $selectedPolicy['npc_detour_note']
        );
        if (isset($result['debug']) && is_array($result['debug'])) {
            $result['debug']['selected_policy'] = $selectedPolicy;
        }

        return $result;
    }
}
