<?php

declare(strict_types=1);

use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpPlanner;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\MovementRules;
use Everoute\Routing\WeightCalculator;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/JumpPlanner.php';
    require_once __DIR__ . '/../src/Routing/JumpRangeCalculator.php';
    require_once __DIR__ . '/../src/Routing/MovementRules.php';
    require_once __DIR__ . '/../src/Routing/WeightCalculator.php';
}

$metersPerLy = 9.4607e15;

$systems = [
    1 => ['id' => 1, 'name' => 'Start', 'security' => 0.2, 'x' => 0.0, 'y' => 0.0, 'z' => 0.0],
    2 => ['id' => 2, 'name' => 'End', 'security' => 0.2, 'x' => $metersPerLy * 4, 'y' => 0.0, 'z' => 0.0],
];

$risk = [];

$options = [
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 4,
    'avoid_lowsec' => false,
    'avoid_nullsec' => false,
];

$planner = new JumpPlanner(
    new JumpRangeCalculator(__DIR__ . '/../config/jump_ranges.php'),
    new WeightCalculator(),
    new MovementRules(),
    new JumpFatigueModel()
);

$plan = $planner->plan(1, 2, $systems, $risk, $options, [], [1, 2]);

if (empty($plan['feasible'])) {
    throw new RuntimeException('Expected a feasible jump plan.');
}

$cooldown = $plan['jump_cooldown_total_minutes'] ?? null;
if (!is_numeric($cooldown) || $cooldown < 0) {
    throw new RuntimeException('Expected numeric, non-negative cooldown total.');
}

$riskLabel = $plan['jump_fatigue_risk_label'] ?? '';
if (!in_array($riskLabel, ['low', 'medium', 'high'], true)) {
    throw new RuntimeException('Unexpected fatigue risk label.');
}

$json = json_encode($plan);
if ($json === false || str_contains($json, '"n/a"')) {
    throw new RuntimeException('Jump plan JSON contains invalid "n/a" values.');
}

echo "Jump planner smoke test passed.\n";
