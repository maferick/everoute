<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Routing/MovementRules.php';
require __DIR__ . '/../../src/Routing/JumpRangeCalculator.php';
require __DIR__ . '/../../src/Routing/JumpPlanner.php';
require __DIR__ . '/../../src/Routing/WeightCalculator.php';

use Everoute\Routing\JumpPlanner;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\MovementRules;
use Everoute\Routing\WeightCalculator;

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$rules = new MovementRules();
$options = ['ship_class' => 'capital', 'jump_ship_type' => 'carrier', 'mode' => 'capital'];

$highSec = ['security' => 0.8];
$lowSec = ['security' => 0.2];

ensure(!$rules->isSystemAllowed($highSec, $options), 'Capital ships should not enter high-sec.');
ensure($rules->validateEndpoints($highSec, $lowSec, $options) !== null, 'High-sec endpoints must be rejected.');

$rangeCalculator = new JumpRangeCalculator(__DIR__ . '/../../config/jump_ranges.php');
$planner = new JumpPlanner($rangeCalculator, new WeightCalculator(), $rules);

$ly = 9.4607e15;
$systems = [
    1 => ['id' => 1, 'name' => 'Start', 'security' => 0.2, 'x' => 0, 'y' => 0, 'z' => 0, 'system_size_au' => 1.0],
    2 => ['id' => 2, 'name' => 'Mid', 'security' => 0.2, 'x' => 4 * $ly, 'y' => 0, 'z' => 0, 'system_size_au' => 1.0],
    3 => ['id' => 3, 'name' => 'End', 'security' => 0.2, 'x' => 8 * $ly, 'y' => 0, 'z' => 0, 'system_size_au' => 1.0],
];
$risk = [
    1 => [],
    2 => [],
    3 => [],
];

$options['jump_ship_type'] = 'carrier';
$options['jump_skill_level'] = 0;

$plan = $planner->plan(1, 3, $systems, $risk, $options, [2], [1, 2, 3]);
ensure(($plan['feasible'] ?? false) === true, 'Jump plan should be feasible with midpoint.');
ensure(isset($plan['cooldown_minutes_estimate'], $plan['fatigue_hours_estimate']), 'Jump plan must include cooldown and fatigue estimates.');
foreach ($plan['segments'] as $segment) {
    ensure($segment['distance_ly'] <= $plan['effective_jump_range_ly'], 'Segment exceeds effective jump range.');
}

$planImpossible = $planner->plan(1, 3, $systems, $risk, $options, [], [1, 3]);
ensure(($planImpossible['feasible'] ?? true) === false, 'Jump plan should be infeasible without midpoints.');
ensure(!empty($planImpossible['reason']), 'Infeasible jump plan should explain why.');

echo "OK\n";
