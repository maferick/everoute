<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpMath;
use Everoute\Routing\JumpPlanner;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\MovementRules;
use Everoute\Routing\RouteService;
use Everoute\Routing\WeightCalculator;
use Everoute\Security\Logger;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/DB/Connection.php';
    require_once __DIR__ . '/../src/Config/Env.php';
    require_once __DIR__ . '/../src/Risk/RiskRepository.php';
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/JumpMath.php';
    require_once __DIR__ . '/../src/Routing/JumpPlanner.php';
    require_once __DIR__ . '/../src/Routing/JumpRangeCalculator.php';
    require_once __DIR__ . '/../src/Routing/MovementRules.php';
    require_once __DIR__ . '/../src/Routing/Graph.php';
    require_once __DIR__ . '/../src/Routing/GraphStore.php';
    require_once __DIR__ . '/../src/Routing/Dijkstra.php';
    require_once __DIR__ . '/../src/Routing/RouteService.php';
    require_once __DIR__ . '/../src/Routing/WeightCalculator.php';
    require_once __DIR__ . '/../src/Security/Logger.php';
    require_once __DIR__ . '/../src/Universe/StargateRepository.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping hybrid planner integration test.\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, security_raw REAL, security_nav REAL, region_id INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');
$pdo->exec('CREATE TABLE stargates (id INTEGER PRIMARY KEY, from_system_id INTEGER, to_system_id INTEGER, is_regional_gate INTEGER)');
$pdo->exec('CREATE TABLE system_risk (system_id INTEGER PRIMARY KEY, kills_last_1h INTEGER, kills_last_24h INTEGER, pod_kills_last_1h INTEGER, pod_kills_last_24h INTEGER, last_updated_at TEXT)');
$pdo->exec('CREATE TABLE chokepoints (system_id INTEGER PRIMARY KEY, reason TEXT, category TEXT, is_active INTEGER)');

$metersPerLy = JumpMath::METERS_PER_LY;
$systemsData = [
    [1, 'Start', 0.2, 0.2, 0.2, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'Launch', 0.2, 0.2, 0.2, 1, 0, 0, 1.0, 11 * $metersPerLy, 0.0, 0.0],
    [3, 'Mid', 0.2, 0.2, 0.2, 1, 0, 0, 1.0, 19 * $metersPerLy, 0.0, 0.0],
    [4, 'End', 0.2, 0.2, 0.2, 1, 1, 1, 1.0, 27 * $metersPerLy, 0.0, 0.0],
];
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systemsData as $row) {
    $stmt->execute($row);
}

$riskStmt = $pdo->prepare('INSERT INTO system_risk VALUES (?, 0, 0, 0, 0, ?)');
foreach ([1, 2, 3, 4] as $id) {
    $riskStmt->execute([$id, '2024-01-01 00:00:00']);
}

$gateStmt = $pdo->prepare('INSERT INTO stargates VALUES (?, ?, ?, ?)');
$gates = [
    [1, 1, 2, 0],
    [2, 2, 1, 0],
    [3, 2, 3, 1],
    [4, 3, 2, 1],
    [5, 3, 4, 0],
    [6, 4, 3, 0],
];
foreach ($gates as $gate) {
    $gateStmt->execute($gate);
}

$weightCalculator = new WeightCalculator();
$movementRules = new MovementRules();
$jumpPlanner = new JumpPlanner(
    new JumpRangeCalculator(__DIR__ . '/../config/ships.php', __DIR__ . '/../config/jump_ranges.php'),
    $weightCalculator,
    $movementRules,
    new JumpFatigueModel(),
    new Logger()
);
$service = new RouteService(
    new SystemRepository($connection),
    new StargateRepository($connection),
    new RiskRepository($connection),
    $weightCalculator,
    $movementRules,
    $jumpPlanner,
    new Logger(),
    null,
    600,
    60
);

$options = [
    'from' => 'Start',
    'to' => 'End',
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 4,
    'safety_vs_speed' => 50,
    'avoid_lowsec' => false,
    'avoid_nullsec' => false,
    'avoid_systems' => [],
    'prefer_npc' => false,
    'ship_modifier' => 1.0,
];

$result = $service->computeRoutes($options);
$plans = $result['routes']['balanced']['plans'] ?? [];
$jumpOnly = $plans['jump'] ?? [];
$hybrid = $plans['hybrid'] ?? [];

if (!empty($jumpOnly['feasible'])) {
    throw new RuntimeException('Expected jump-only to be infeasible for hybrid test.');
}

if (empty($hybrid['feasible'])) {
    throw new RuntimeException('Expected hybrid plan to be feasible.');
}

echo "Hybrid planner integration test passed.\n";
