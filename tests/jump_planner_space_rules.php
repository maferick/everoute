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
    require_once __DIR__ . '/../src/Routing/AStar.php';
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/JumpMath.php';
    require_once __DIR__ . '/../src/Routing/JumpNeighborGraphBuilder.php';
    require_once __DIR__ . '/../src/Routing/JumpPlanner.php';
    require_once __DIR__ . '/../src/Routing/JumpRangeCalculator.php';
    require_once __DIR__ . '/../src/Routing/MovementRules.php';
    require_once __DIR__ . '/../src/Routing/WeightCalculator.php';
    require_once __DIR__ . '/../src/Security/Logger.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping jump planner space rules test.\n";
    exit(0);
}

/**
 * @param array<int, array<int, mixed>> $systemsData
 * @return array{0:JumpPlanner,1:array<int,array<string,mixed>>}
 */
function buildPlannerAndSystems(array $systemsData): array
{
    $connection = new Connection('sqlite::memory:', '', '');
    $pdo = $connection->pdo();
    $pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, region_id INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');

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
        new JumpRangeCalculator(__DIR__ . '/../config/ships.php', __DIR__ . '/../config/jump_ranges.php'),
        new WeightCalculator(),
        new MovementRules(),
        new JumpFatigueModel(),
        new Logger()
    );

    return [$planner, $systems];
}

$metersPerLy = JumpMath::METERS_PER_LY;

$carrierOptions = [
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 4,
    'avoid_lowsec' => false,
    'avoid_nullsec' => false,
    'prefer_npc' => false,
];

$jumpFreighterOptions = [
    'mode' => 'capital',
    'ship_class' => 'freighter',
    'jump_ship_type' => 'jump_freighter',
    'jump_skill_level' => 4,
    'avoid_lowsec' => false,
    'avoid_nullsec' => false,
    'prefer_npc' => false,
];

[$planner, $systems] = buildPlannerAndSystems([
    [1, 'Start', 0.2, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'MidSafe', 0.2, 1, 0, 0, 1.0, 6.9 * $metersPerLy, 0.0, 0.0],
    [3, 'End', 0.2, 1, 0, 0, 1.0, 13.8 * $metersPerLy, 0.0, 0.0],
]);

$plan = $planner->plan(1, 3, $systems, [], $carrierOptions, [], []);
if (empty($plan['feasible'])) {
    throw new RuntimeException('Expected carrier jump chain to be feasible.');
}
foreach ($plan['segments'] ?? [] as $segment) {
    if (($segment['distance_ly'] ?? 0.0) > 7.0) {
        throw new RuntimeException('Expected carrier jump segments to stay within 7 LY.');
    }
}

[$planner, $systems] = buildPlannerAndSystems([
    [1, 'Start', 0.2, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'MidHigh', 0.6, 1, 0, 0, 1.0, 6.5 * $metersPerLy, 0.0, 0.0],
    [3, 'MidLow', 0.2, 1, 0, 0, 1.0, 6.8 * $metersPerLy, 0.0, 0.0],
    [4, 'End', 0.2, 1, 0, 0, 1.0, 13.6 * $metersPerLy, 0.0, 0.0],
]);

$plan = $planner->plan(1, 4, $systems, [], $carrierOptions, [], []);
if (empty($plan['feasible'])) {
    throw new RuntimeException('Expected carrier jump chain to be feasible.');
}
if (in_array('MidHigh', $plan['midpoints'] ?? [], true)) {
    throw new RuntimeException('Carrier plan should not include high-sec midpoint systems.');
}

[$planner, $systems] = buildPlannerAndSystems([
    [1, 'Start', 0.2, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'MidLow', 0.2, 1, 0, 0, 1.0, 8 * $metersPerLy, 0.0, 0.0],
    [3, 'HighEnd', 0.6, 1, 0, 0, 1.0, 16 * $metersPerLy, 0.0, 0.0],
]);

$plan = $planner->plan(1, 3, $systems, [], $jumpFreighterOptions, [], []);
if (empty($plan['feasible'])) {
    throw new RuntimeException('Expected jump freighter to land in high-sec when allowed.');
}
if (in_array('HighEnd', $plan['midpoints'] ?? [], true)) {
    throw new RuntimeException('Jump freighter plan should not use high-sec as a midpoint.');
}

[$planner, $systems] = buildPlannerAndSystems([
    [1, 'Start', 0.2, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'MidHigh', 0.6, 1, 0, 0, 1.0, 7 * $metersPerLy, 0.0, 0.0],
    [3, 'End', 0.2, 1, 0, 0, 1.0, 14 * $metersPerLy, 0.0, 0.0],
]);

$plan = $planner->plan(1, 3, $systems, [], $carrierOptions, [], []);
if (!empty($plan['feasible'])) {
    throw new RuntimeException('Expected carrier plan to be rejected when midpoint is high-sec.');
}
if (($plan['reason'] ?? '') !== 'No valid jump chain within ship range.') {
    throw new RuntimeException('Expected range-based rejection reason for carrier plan.');
}

echo "Jump planner space rules test passed.\n";
