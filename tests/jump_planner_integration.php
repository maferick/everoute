<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpMath;
use Everoute\Routing\JumpPlanner;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\MovementRules;
use Everoute\Routing\WeightCalculator;
use Everoute\Security\Logger;
use Everoute\Universe\SystemRepository;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/DB/Connection.php';
    require_once __DIR__ . '/../src/Config/Env.php';
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/JumpMath.php';
    require_once __DIR__ . '/../src/Routing/JumpPlanner.php';
    require_once __DIR__ . '/../src/Routing/JumpRangeCalculator.php';
    require_once __DIR__ . '/../src/Routing/MovementRules.php';
    require_once __DIR__ . '/../src/Routing/WeightCalculator.php';
    require_once __DIR__ . '/../src/Security/Logger.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, region_id INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');

$metersPerLy = JumpMath::METERS_PER_LY;
$systemsData = [
    [1, 'Start', 0.2, 1, 1, 1, 1.0, 0.0, 0.0, 0.0],
    [2, 'Mid-1', 0.2, 1, 0, 0, 1.0, 8 * $metersPerLy, 0.0, 0.0],
    [3, 'Mid-2', 0.2, 1, 0, 0, 1.0, 16 * $metersPerLy, 0.0, 0.0],
    [4, 'End', 0.2, 1, 1, 1, 1.0, 24 * $metersPerLy, 0.0, 0.0],
];
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systemsData as $row) {
    $stmt->execute($row);
}

$systemsRepo = new SystemRepository($connection);
$systems = [];
foreach ($systemsRepo->listForRouting() as $system) {
    $systems[(int) $system['id']] = $system;
}

$planner = new JumpPlanner(
    new JumpRangeCalculator(__DIR__ . '/../config/jump_ranges.php'),
    new WeightCalculator(),
    new MovementRules(),
    new JumpFatigueModel(),
    new Logger()
);

$options = [
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 4,
    'avoid_lowsec' => false,
    'avoid_nullsec' => false,
    'prefer_npc' => false,
];

$plan = $planner->plan(1, 4, $systems, [], $options, [], [1, 2, 3, 4]);
if (empty($plan['feasible'])) {
    throw new RuntimeException('Expected a feasible jump chain.');
}

if (($plan['jump_hops_count'] ?? 0) !== 3) {
    throw new RuntimeException('Expected three jump hops for the midpoint chain.');
}

if (!isset($plan['jump_cooldown_total_minutes'])) {
    throw new RuntimeException('Expected cooldown estimate for jump plan.');
}

echo "Jump planner integration test passed.\n";
