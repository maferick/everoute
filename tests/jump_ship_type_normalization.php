<?php

declare(strict_types=1);

use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpMath;
use Everoute\Routing\JumpPlanner;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\JumpShipType;
use Everoute\Routing\MovementRules;
use Everoute\Routing\WeightCalculator;
use Everoute\Security\Logger;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/JumpMath.php';
    require_once __DIR__ . '/../src/Routing/JumpNeighborGraphBuilder.php';
    require_once __DIR__ . '/../src/Routing/JumpPlanner.php';
    require_once __DIR__ . '/../src/Routing/JumpRangeCalculator.php';
    require_once __DIR__ . '/../src/Routing/JumpShipType.php';
    require_once __DIR__ . '/../src/Routing/MovementRules.php';
    require_once __DIR__ . '/../src/Routing/WeightCalculator.php';
    require_once __DIR__ . '/../src/Security/Logger.php';
}

if (JumpShipType::normalizeJumpShipType('carrier') !== JumpShipType::CARRIER) {
    throw new RuntimeException('Expected carrier to normalize to carrier.');
}

if (JumpShipType::normalizeJumpShipType('Carriers') !== JumpShipType::CARRIER) {
    throw new RuntimeException('Expected Carriers to normalize to carrier.');
}

if (JumpShipType::normalizeJumpShipType('JF') !== JumpShipType::JUMP_FREIGHTER) {
    throw new RuntimeException('Expected JF to normalize to jump_freighter.');
}

$metersPerLy = JumpMath::METERS_PER_LY;
$systems = [
    1 => ['id' => 1, 'name' => 'Start', 'security' => 0.2, 'x' => 0.0, 'y' => 0.0, 'z' => 0.0],
    2 => ['id' => 2, 'name' => 'End', 'security' => 0.2, 'x' => $metersPerLy * 4, 'y' => 0.0, 'z' => 0.0],
];

$options = [
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'mystery_ship',
    'jump_skill_level' => 4,
    'avoid_lowsec' => false,
    'avoid_nullsec' => false,
];

$planner = new JumpPlanner(
    new JumpRangeCalculator(__DIR__ . '/../config/ships.php', __DIR__ . '/../config/jump_ranges.php'),
    new WeightCalculator(),
    new MovementRules(),
    new JumpFatigueModel(),
    new Logger()
);

$plan = $planner->plan(1, 2, $systems, [], $options, [], [1, 2]);
if (!empty($plan['feasible'])) {
    throw new RuntimeException('Expected jump plan to be infeasible for unknown ship type.');
}

$expectedReason = sprintf(
    "Unsupported jump ship type '%s' (normalized '%s'). Allowed: %s",
    'mystery_ship',
    JumpShipType::normalizeJumpShipType('mystery_ship'),
    JumpShipType::allowedList()
);
if (($plan['reason'] ?? '') !== $expectedReason) {
    throw new RuntimeException('Expected unknown ship type to return a structured reason.');
}

echo "Jump ship type normalization test passed.\n";
