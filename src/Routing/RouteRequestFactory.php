<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Security\Validator;

final class RouteRequestFactory
{
    public function __construct(private Validator $validator)
    {
    }

    /**
     * @return array{request: RouteRequest, payload: array<string,mixed>}
     */
    public function fromBody(array $body): array
    {
        $from = $this->validator->string($body['from_id'] ?? $body['from'] ?? null);
        $to = $this->validator->string($body['to_id'] ?? $body['to'] ?? null);
        if ($from === null || $to === null) {
            throw new \InvalidArgumentException('Invalid from/to');
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
        $requireStationMidpoints = $this->validator->bool($body['require_station_midpoints'] ?? ($body['use_stations'] ?? null), false);
        $stationConstraintMode = $this->validator->enum(
            strtolower((string) ($body['station_constraint_mode'] ?? ($body['avoid_strictness'] ?? ''))),
            ['soft', 'strict'],
            ''
        );
        $stationType = $this->validator->enum(strtolower((string) ($body['station_type'] ?? 'npc')), ['npc'], 'npc');
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

        $avoidStrictness = $this->validator->enum(
            strtolower((string) ($body['avoid_strictness'] ?? '')),
            ['soft', 'strict'],
            ''
        );
        $avoidSpecificSystems = isset($body['avoid_specific_systems']) ? $this->validator->list((string) $body['avoid_specific_systems']) : [];

        $request = RouteRequest::create(
            $from,
            $to,
            $shipProfile,
            PreferenceProfile::create($requestedProfile !== '' ? $requestedProfile : null, $safety),
            $preference,
            $avoidLowsec,
            $avoidNullsec,
            $avoidStrictness,
            $avoidSpecificSystems,
            $preferNpc,
            $requireStationMidpoints,
            $stationConstraintMode,
            $stationType,
            $allowGateReposition,
            $hybridGateBudgetMax
        );

        return [
            'request' => $request,
            'payload' => [
                'from' => $from,
                'to' => $to,
                'mode' => $mode,
                'ship_class' => $shipClass,
                'jump_ship_type' => $jumpShipType,
                'jump_skill_level' => $jumpSkillLevel,
                'safety_vs_speed' => $safety,
                'preference_profile' => $requestedProfile,
                'preference' => $preference,
                'avoid_lowsec' => $avoidLowsec,
                'avoid_nullsec' => $avoidNullsec,
                'avoid_strictness' => $avoidStrictness,
                'avoid_specific_systems' => $avoidSpecificSystems,
                'prefer_npc_stations' => $preferNpc,
                'require_station_midpoints' => $requireStationMidpoints,
                'station_constraint_mode' => $stationConstraintMode,
                'station_type' => $stationType,
                'allow_gate_reposition' => $allowGateReposition,
                'hybrid_gate_budget_max' => $hybridGateBudgetMax,
                'fuel_per_ly_factor' => $fuelPerLyFactor,
            ],
        ];
    }
}
