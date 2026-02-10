<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpMath;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\NavigationEngine;
use Everoute\Routing\ShipRules;
use Everoute\Routing\SystemLookup;
use Everoute\Security\Logger;
use Everoute\Universe\JumpNeighborCodec;
use Everoute\Universe\JumpNeighborRepository;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Config/Env.php';
    require_once __DIR__ . '/../src/DB/Connection.php';
    require_once __DIR__ . '/../src/Routing/Graph.php';
    require_once __DIR__ . '/../src/Routing/GraphStore.php';
    require_once __DIR__ . '/../src/Routing/Dijkstra.php';
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/JumpMath.php';
    require_once __DIR__ . '/../src/Routing/JumpShipType.php';
    require_once __DIR__ . '/../src/Routing/JumpRangeCalculator.php';
    require_once __DIR__ . '/../src/Routing/NavigationEngine.php';
    require_once __DIR__ . '/../src/Routing/ShipRules.php';
    require_once __DIR__ . '/../src/Routing/SystemLookup.php';
    require_once __DIR__ . '/../src/Security/Logger.php';
    require_once __DIR__ . '/../src/Universe/JumpNeighborCodec.php';
    require_once __DIR__ . '/../src/Universe/JumpNeighborRepository.php';
    require_once __DIR__ . '/../src/Universe/StargateRepository.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
    require_once __DIR__ . '/../src/Risk/RiskRepository.php';
    require_once __DIR__ . '/../src/Risk/RiskScorer.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping avoid fallback test.\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, security_raw REAL, security_nav REAL, region_id INTEGER, constellation_id INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');
$pdo->exec('CREATE TABLE stargates (from_system_id INTEGER, to_system_id INTEGER, is_regional_gate INTEGER)');
$pdo->exec('CREATE TABLE system_risk (system_id INTEGER, ship_kills_1h INTEGER, pod_kills_1h INTEGER, npc_kills_1h INTEGER, updated_at TEXT, risk_updated_at TEXT, kills_last_1h INTEGER, kills_last_24h INTEGER, pod_kills_last_1h INTEGER, pod_kills_last_24h INTEGER, last_updated_at TEXT)');
$pdo->exec('CREATE TABLE jump_neighbors (system_id INTEGER, range_ly INTEGER, neighbor_count INTEGER, neighbor_ids_blob BLOB, encoding_version INTEGER, updated_at TEXT)');

$metersPerLy = JumpMath::METERS_PER_LY;
$systems = [
    [1, 'LS-Start', 0.2, 0.2, 0.2, 1, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'LS-Mid', 0.3, 0.3, 0.3, 1, 1, 0, 0, 1.0, 6 * $metersPerLy, 0.0, 0.0],
    [3, 'LS-End', 0.2, 0.2, 0.2, 1, 1, 0, 0, 1.0, 12 * $metersPerLy, 0.0, 0.0],
];
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systems as $system) {
    $stmt->execute($system);
    $pdo->prepare('INSERT INTO system_risk VALUES (?, 0, 0, 0, ?, ?, 0, 0, 0, 0, ?)')->execute([$system[0], gmdate('c'), gmdate('c'), gmdate('c')]);
}

$gateStmt = $pdo->prepare('INSERT INTO stargates VALUES (?, ?, 0)');
$gateStmt->execute([1, 2]);
$gateStmt->execute([2, 1]);
$gateStmt->execute([2, 3]);
$gateStmt->execute([3, 2]);

$neighborsBySystem = [
    1 => [2],
    2 => [1, 3],
    3 => [2],
];
$neighborStmt = $pdo->prepare('INSERT INTO jump_neighbors VALUES (?, ?, ?, ?, 1, ?)');
foreach ($neighborsBySystem as $systemId => $neighborIds) {
    $payload = JumpNeighborCodec::encodeV1($neighborIds);
    $neighborStmt->execute([$systemId, 7, count($neighborIds), $payload, gmdate('c')]);
}

$logger = new Logger();
$systemsRepo = new SystemRepository($connection);
$engine = new NavigationEngine(
    $systemsRepo,
    new StargateRepository($connection),
    new JumpNeighborRepository($connection, $logger),
    new RiskRepository($connection),
    new JumpRangeCalculator(__DIR__ . '/../config/ships.php', __DIR__ . '/../config/jump_ranges.php'),
    new JumpFatigueModel(),
    new ShipRules(),
    new SystemLookup($systemsRepo),
    $logger
);

$options = [
    'from' => 'LS-Start',
    'to' => 'LS-End',
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'avoid_lowsec' => true,
    'avoid_nullsec' => false,
    'safety_vs_speed' => 50,
];

$result = $engine->compute($options);
foreach (['gate_route', 'jump_route', 'hybrid_route'] as $routeKey) {
    $route = $result[$routeKey] ?? [];
    if (empty($route['fallback_used'])) {
        throw new RuntimeException($routeKey . ' should mark fallback_used when strict avoid fails.');
    }
    if (empty($route['fallback_warning'])) {
        throw new RuntimeException($routeKey . ' should expose fallback warning metadata.');
    }
    if (($route['applied_avoid_strictness'] ?? '') !== 'soft') {
        throw new RuntimeException($routeKey . ' should end on soft strictness after fallback.');
    }
    if (($route['requested_avoid_strictness'] ?? '') !== 'strict') {
        throw new RuntimeException($routeKey . ' should default requested strictness to strict with avoid toggles.');
    }
}

if (empty($result['gate_route']['feasible']) || empty($result['jump_route']['feasible'])) {
    throw new RuntimeException('Gate and jump routes should become feasible after soft avoid fallback.');
}

if (empty($result['fallback_warning']) || empty($result['fallback_message'])) {
    throw new RuntimeException('Top-level payload should include fallback warning details for selected route.');
}
if (($result['chosen_route_lowsec_count'] ?? 0) <= 0) {
    throw new RuntimeException('Top-level payload should include chosen route lowsec count.');
}

$explanation = $result['explanation'] ?? [];
$bestEffortMentioned = false;
foreach ($explanation as $line) {
    if (stripos((string) $line, 'best effort route') !== false) {
        $bestEffortMentioned = true;
        break;
    }
}
if (!$bestEffortMentioned) {
    throw new RuntimeException('Explanation should mention best effort route fallback behavior.');
}

echo "Navigation avoid fallback test passed.\n";
