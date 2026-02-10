<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class RouteRequest
{
    /** @param string[] $avoidSystems */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ShipProfile $ship,
        public readonly PreferenceProfile $preferenceProfile,
        public readonly string $preference,
        public readonly bool $avoidLowsec,
        public readonly bool $avoidNullsec,
        public readonly string $avoidStrictness,
        public readonly array $avoidSystems,
        public readonly bool $preferNpc,
        public readonly int $npcFallbackMaxExtraJumps,
        public readonly bool $allowGateReposition,
        public readonly int $hybridGateBudgetMax,
        public readonly bool $debug = false
    ) {
    }

    /** @param string[] $avoidSystems */
    public static function create(
        string $from,
        string $to,
        ShipProfile $ship,
        PreferenceProfile $preferenceProfile,
        string $preference,
        bool $avoidLowsec,
        bool $avoidNullsec,
        ?string $avoidStrictness,
        array $avoidSystems,
        bool $preferNpc,
        ?bool $allowGateReposition = null,
        ?int $hybridGateBudgetMax = null,
        bool $debug = false
    ): self {
        $resolvedStrictness = self::resolveAvoidStrictness($avoidStrictness, $avoidLowsec, $avoidNullsec);
        $npcPolicy = self::deriveNpcDetourPolicy($preferenceProfile->safetyVsSpeed, $preferNpc);

        return new self(
            trim($from),
            trim($to),
            $ship,
            $preferenceProfile,
            self::normalizePreference($preference),
            $avoidLowsec,
            $avoidNullsec,
            $resolvedStrictness,
            self::normalizeAvoidSystems($avoidSystems),
            $preferNpc,
            $npcPolicy['npc_detour_max_extra_jumps'],
            $allowGateReposition ?? true,
            max(2, min(12, (int) ($hybridGateBudgetMax ?? 8))),
            $debug
        );
    }

    /** @param array<string,mixed> $options */
    public static function fromLegacyOptions(array $options): self
    {
        $ship = ShipProfile::create(
            (string) ($options['mode'] ?? ShipProfile::DEFAULT_MODE),
            (string) ($options['ship_class'] ?? 'subcap'),
            isset($options['jump_ship_type']) ? (string) $options['jump_ship_type'] : null,
            (int) ($options['jump_skill_level'] ?? 5),
            isset($options['fuel_per_ly_factor']) && is_numeric($options['fuel_per_ly_factor']) ? (float) $options['fuel_per_ly_factor'] : null,
            isset($options['jump_fuel_weight']) && is_numeric($options['jump_fuel_weight']) ? (float) $options['jump_fuel_weight'] : null,
        );

        $safety = max(0, min(100, (int) ($options['safety_vs_speed'] ?? ($ship->mode === 'capital' ? 70 : 50))));
        $requestedProfile = isset($options['preference_profile']) ? (string) $options['preference_profile'] : null;
        $profile = PreferenceProfile::create($requestedProfile, $safety);

        return self::create(
            (string) ($options['from'] ?? ''),
            (string) ($options['to'] ?? ''),
            $ship,
            $profile,
            (string) ($options['preference'] ?? 'shorter'),
            !empty($options['avoid_lowsec']),
            !empty($options['avoid_nullsec']),
            isset($options['avoid_strictness']) ? (string) $options['avoid_strictness'] : null,
            isset($options['avoid_systems']) && is_array($options['avoid_systems']) ? $options['avoid_systems'] : [],
            (bool) ($options['prefer_npc'] ?? ($ship->mode === 'capital')),
            array_key_exists('allow_gate_reposition', $options) ? (bool) $options['allow_gate_reposition'] : true,
            array_key_exists('hybrid_gate_budget_max', $options) && is_numeric($options['hybrid_gate_budget_max']) ? (int) $options['hybrid_gate_budget_max'] : 8,
            !empty($options['debug'])
        );
    }

    /** @return array<string,mixed> */
    public function toLegacyOptions(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'mode' => $this->ship->mode,
            'ship_class' => $this->ship->shipClass,
            'jump_ship_type' => $this->ship->jumpShipType,
            'jump_skill_level' => $this->ship->jumpSkillLevel,
            'safety_vs_speed' => $this->preferenceProfile->safetyVsSpeed,
            'preference' => $this->preference,
            'avoid_lowsec' => $this->avoidLowsec,
            'avoid_nullsec' => $this->avoidNullsec,
            'avoid_strictness' => $this->avoidStrictness,
            'avoid_systems' => $this->avoidSystems,
            'prefer_npc' => $this->preferNpc,
            'npc_fallback_max_extra_jumps' => $this->npcFallbackMaxExtraJumps,
            'allow_gate_reposition' => $this->allowGateReposition,
            'hybrid_gate_budget_max' => $this->hybridGateBudgetMax,
            'ship_modifier' => $this->ship->shipModifier,
            'fuel_per_ly_factor' => $this->ship->fuelPerLyFactor,
            'jump_fuel_weight' => $this->ship->jumpFuelWeight,
            'preference_profile' => $this->preferenceProfile->name,
            'debug' => $this->debug,
        ];
    }

    /** @return array{slider_side:string,safety_vs_speed:int,prefer_npc_stations:bool,npc_detour_max_extra_jumps:int,npc_detour_note:string} */
    public function selectedPolicy(): array
    {
        return self::deriveNpcDetourPolicy($this->preferenceProfile->safetyVsSpeed, $this->preferNpc);
    }

    /** @return array{slider_side:string,safety_vs_speed:int,prefer_npc_stations:bool,npc_detour_max_extra_jumps:int,npc_detour_note:string} */
    public static function deriveNpcDetourPolicy(int $safetyVsSpeed, bool $preferNpcStations): array
    {
        $sliderSide = $safetyVsSpeed <= 50 ? 'speed' : 'safety';
        $extraJumps = ($preferNpcStations && $sliderSide === 'safety') ? 1 : 0;
        $note = $extraJumps > 0
            ? 'may accept +1 jump detour for NPC coverage'
            : 'no extra jump allowed for NPC coverage';

        return [
            'slider_side' => $sliderSide,
            'safety_vs_speed' => $safetyVsSpeed,
            'prefer_npc_stations' => $preferNpcStations,
            'npc_detour_max_extra_jumps' => $extraJumps,
            'npc_detour_note' => $note,
        ];
    }

    private static function normalizePreference(string $preference): string
    {
        $normalized = strtolower(trim($preference));

        return in_array($normalized, ['shorter', 'safer', 'less_secure'], true)
            ? $normalized
            : 'shorter';
    }

    private static function resolveAvoidStrictness(?string $strictness, bool $avoidLowsec, bool $avoidNullsec): string
    {
        $default = ($avoidLowsec || $avoidNullsec) ? 'strict' : 'soft';
        $normalized = strtolower(trim((string) $strictness));

        return in_array($normalized, ['soft', 'strict'], true) ? $normalized : $default;
    }

    /** @param array<int,mixed> $avoidSystems */
    private static function normalizeAvoidSystems(array $avoidSystems): array
    {
        $normalized = [];
        foreach ($avoidSystems as $system) {
            $value = trim((string) $system);
            if ($value === '') {
                continue;
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }
}
