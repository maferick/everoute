<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpMath;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\NavigationEngine;
use Everoute\Routing\RouteRequest;
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
    require_once __DIR__ . '/../src/Universe/ConstellationGraphRepository.php';
    require_once __DIR__ . '/../src/Universe/StargateRepository.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
    require_once __DIR__ . '/../src/Universe/StaticTableResolver.php';
    require_once __DIR__ . '/../src/Universe/StaticMetaRepository.php';
    require_once __DIR__ . '/../src/Risk/RiskRepository.php';
    require_once __DIR__ . '/../src/Risk/RiskScorer.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping navigation engine cases test.\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, security_raw REAL, security_nav REAL, region_id INTEGER, constellation_id INTEGER, is_wormhole INTEGER, is_normal_universe INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');
$pdo->exec('CREATE TABLE stargates (id INTEGER PRIMARY KEY AUTOINCREMENT, from_system_id INTEGER, to_system_id INTEGER, is_regional_gate INTEGER, is_constellation_boundary INTEGER DEFAULT 0, is_region_boundary INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE constellation_portals (constellation_id INTEGER, system_id INTEGER, has_region_boundary INTEGER, PRIMARY KEY (constellation_id, system_id))');
$pdo->exec('CREATE TABLE constellation_edges (from_constellation_id INTEGER, to_constellation_id INTEGER, from_system_id INTEGER, to_system_id INTEGER, is_region_boundary INTEGER, PRIMARY KEY (from_constellation_id, to_constellation_id, from_system_id, to_system_id))');
$pdo->exec('CREATE TABLE constellation_dist (constellation_id INTEGER, portal_system_id INTEGER, system_id INTEGER, gate_dist INTEGER, PRIMARY KEY (constellation_id, portal_system_id, system_id))');
$pdo->exec('CREATE TABLE jump_constellation_portals (constellation_id INTEGER, range_ly INTEGER, system_id INTEGER, outbound_constellations_count INTEGER, PRIMARY KEY (constellation_id, range_ly, system_id))');
$pdo->exec('CREATE TABLE jump_constellation_edges (range_ly INTEGER, from_constellation_id INTEGER, to_constellation_id INTEGER, example_from_system_id INTEGER, example_to_system_id INTEGER, min_hop_ly REAL, PRIMARY KEY (range_ly, from_constellation_id, to_constellation_id))');
$pdo->exec('CREATE TABLE jump_midpoint_candidates (constellation_id INTEGER, range_ly INTEGER, system_id INTEGER, score REAL, PRIMARY KEY (constellation_id, range_ly, system_id))');
$pdo->exec('CREATE TABLE system_risk (system_id INTEGER, ship_kills_1h INTEGER, pod_kills_1h INTEGER, npc_kills_1h INTEGER, updated_at TEXT, risk_updated_at TEXT, kills_last_1h INTEGER, kills_last_24h INTEGER, pod_kills_last_1h INTEGER, pod_kills_last_24h INTEGER, last_updated_at TEXT)');
$pdo->exec('CREATE TABLE jump_neighbors (system_id INTEGER, range_ly INTEGER, neighbor_count INTEGER, neighbor_ids_blob BLOB, encoding_version INTEGER, updated_at TEXT)');
$pdo->exec('CREATE TABLE static_meta (id INTEGER PRIMARY KEY, active_sde_build_number INTEGER, precompute_version INTEGER, built_at TEXT, active_build_id TEXT)');
$pdo->exec("INSERT INTO static_meta (id, active_sde_build_number, precompute_version, built_at, active_build_id) VALUES (1, NULL, 1, datetime('now'), NULL)");

$metersPerLy = JumpMath::METERS_PER_LY;
$systems = [
    [1, '1-SMEB', 0.2, 0.2, 0.2, 1, 10, 0, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'Midpoint-LS', 0.3, 0.3, 0.3, 1, 10, 0, 1, 0, 0, 1.0, 6 * $metersPerLy, 0.0, 0.0],
    [3, 'Eurgrana', 0.4, 0.4, 0.4, 1, 10, 0, 1, 0, 0, 1.0, 12 * $metersPerLy, 0.0, 0.0],
    [4, 'Highsec-A', 0.6, 0.6, 0.6, 1, 10, 0, 1, 0, 0, 1.0, 5 * $metersPerLy, 0.0, 0.0],
    [5, 'Highsec-B', 0.6, 0.6, 0.6, 1, 10, 0, 1, 0, 0, 1.0, 9 * $metersPerLy, 0.0, 0.0],
    [6, 'Pochven-A', -1.0, -1.0, -1.0, 10000070, 70, 0, 1, 0, 0, 1.0, 18 * $metersPerLy, 0.0, 0.0],
];
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systems as $system) {
    $stmt->execute($system);
    $pdo->prepare('INSERT INTO system_risk VALUES (?, 0, 0, 0, ?, ?, 0, 0, 0, 0, ?)')
        ->execute([$system[0], gmdate('c'), gmdate('c'), gmdate('c')]);
}
$gateStmt = $pdo->prepare('INSERT INTO stargates (from_system_id, to_system_id, is_regional_gate, is_constellation_boundary, is_region_boundary) VALUES (?, ?, 0, ?, ?)');
$gateRows = [
    [1, 2, 0, 0], [2, 1, 0, 0],
    [2, 3, 0, 0], [3, 2, 0, 0],
    [2, 4, 0, 0], [4, 2, 0, 0],
    [4, 5, 0, 0], [5, 4, 0, 0],
    [5, 3, 0, 0], [3, 5, 0, 0],
    [3, 6, 1, 1], [6, 3, 1, 1],
];
foreach ($gateRows as [$from, $to, $isConstellationBoundary, $isRegionBoundary]) {
    $gateStmt->execute([$from, $to, $isConstellationBoundary, $isRegionBoundary]);
}

$pdo->exec("INSERT INTO constellation_edges VALUES
    (10, 70, 3, 6, 1),
    (70, 10, 6, 3, 1)");
$pdo->exec("INSERT INTO constellation_portals VALUES
    (10, 3, 1),
    (70, 6, 1)");
$pdo->exec("INSERT INTO constellation_dist VALUES
    (10, 3, 1, 2),
    (10, 3, 2, 1),
    (10, 3, 3, 0),
    (10, 3, 4, 2),
    (10, 3, 5, 1),
    (70, 6, 6, 0)");

$pdo->exec("INSERT INTO jump_constellation_edges VALUES
    (7, 10, 70, 3, 6, 6.0),
    (7, 70, 10, 6, 3, 6.0),
    (10, 10, 70, 3, 6, 6.0),
    (10, 70, 10, 6, 3, 6.0)");
$pdo->exec("INSERT INTO jump_constellation_portals VALUES
    (10, 7, 3, 1),
    (70, 7, 6, 1),
    (10, 10, 1, 1),
    (10, 10, 2, 1),
    (10, 10, 3, 1),
    (10, 10, 4, 1),
    (10, 10, 5, 1),
    (70, 10, 6, 1)");
$pdo->exec("INSERT INTO jump_midpoint_candidates VALUES
    (10, 7, 2, 3.0),
    (10, 7, 3, 4.0),
    (70, 7, 6, 1.0),
    (10, 10, 2, 3.0),
    (10, 10, 3, 4.0),
    (70, 10, 6, 1.0)");


function packNeighbors(array $neighborIds): string
{
    return JumpNeighborCodec::encodeV1($neighborIds);
}

function insertNeighbors(PDO $pdo, int $systemId, int $range, array $neighborIds): void
{
    $payload = packNeighbors($neighborIds);
    $stmt = $pdo->prepare('INSERT INTO jump_neighbors VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$systemId, $range, count($neighborIds), $payload, 1, gmdate('c')]);
}

$neighborMap7 = [
    1 => [2],
    2 => [1, 3, 4],
    3 => [2, 5, 6],
    4 => [2, 5],
    5 => [3, 4, 6],
    6 => [3, 5],
];
$neighborMap10 = [
    1 => [2, 3, 4, 5, 6],
    2 => [1, 3, 4, 5, 6],
    3 => [1, 2, 4, 5, 6],
    4 => [1, 2, 3, 5, 6],
    5 => [1, 2, 3, 4, 6],
    6 => [1, 2, 3, 4, 5],
];
foreach ($neighborMap7 as $systemId => $neighbors) {
    insertNeighbors($pdo, $systemId, 7, $neighbors);
}
foreach ($neighborMap10 as $systemId => $neighbors) {
    insertNeighbors($pdo, $systemId, 10, $neighbors);
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

$optionsCarrier = [
    'from' => '1-SMEB',
    'to' => 'Eurgrana',
    'mode' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'safety_vs_speed' => 50,
];
$resultCarrier = $engine->compute(RouteRequest::fromLegacyOptions($optionsCarrier));
$jumpRoute = $resultCarrier['jump_route'] ?? [];
if (empty($jumpRoute['feasible'])) {
    throw new RuntimeException('Carrier jump route should be feasible.');
}
$jumpSegments = $jumpRoute['segments'] ?? [];
if (count($jumpSegments) < 2) {
    throw new RuntimeException('Carrier jump route should include a midpoint.');
}
foreach ($jumpSegments as $segment) {
    if (($segment['to'] ?? '') === 'Highsec-A' || ($segment['to'] ?? '') === 'Highsec-B') {
        throw new RuntimeException('Carrier route must not enter highsec.');
    }
    if (($segment['type'] ?? '') === 'jump' && (float) $segment['distance_ly'] > 7.0) {
        throw new RuntimeException('Carrier jump exceeds max range.');
    }
}

$optionsJf = [
    'from' => '1-SMEB',
    'to' => 'Highsec-B',
    'mode' => 'capital',
    'jump_ship_type' => 'jump_freighter',
    'jump_skill_level' => 5,
    'safety_vs_speed' => 50,
];
$resultJf = $engine->compute(RouteRequest::fromLegacyOptions($optionsJf));
$jfJumpRoute = $resultJf['jump_route'] ?? [];
if (empty($jfJumpRoute['feasible'])) {
    throw new RuntimeException('Jump freighter should be able to land in highsec.');
}
foreach ($jfJumpRoute['segments'] as $segment) {
    if (($segment['to'] ?? '') === 'Highsec-A') {
        throw new RuntimeException('Jump freighter cannot use highsec midpoint.');
    }
}

$optionsPochven = [
    'from' => '1-SMEB',
    'to' => 'Pochven-A',
    'mode' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'safety_vs_speed' => 50,
];
$resultPochven = $engine->compute(RouteRequest::fromLegacyOptions($optionsPochven));
$jumpToPochven = $resultPochven['jump_route'] ?? [];
if (!empty($jumpToPochven['feasible'])) {
    throw new RuntimeException('Jump route into Pochven must not be feasible.');
}

$hybridToPochven = $resultPochven['hybrid_route'] ?? [];
if (!empty($hybridToPochven['feasible'])) {
    throw new RuntimeException('Hybrid route with jump segment into Pochven must not be feasible.');
}



$optionsGateHierarchy = [
    'from' => '1-SMEB',
    'to' => 'Pochven-A',
    'mode' => 'subcap',
    'safety_vs_speed' => 50,
];
$hierResult = $engine->compute(RouteRequest::fromLegacyOptions($optionsGateHierarchy));
$hierGate = $hierResult['gate_route'] ?? [];
if (empty($hierGate['feasible'])) {
    throw new RuntimeException('Hierarchical gate route across constellations should be feasible.');
}
if (($hierGate['hierarchy']['kind'] ?? null) !== 'constellation') {
    throw new RuntimeException('Expected constellation hierarchy routing metadata on cross-constellation path.');
}

$pdo->exec('DELETE FROM constellation_edges');
$pdo->exec('DELETE FROM constellation_portals');
$pdo->exec('DELETE FROM constellation_dist');
$engine->refresh();
$flatResult = $engine->compute(RouteRequest::fromLegacyOptions($optionsGateHierarchy));
$flatGate = $flatResult['gate_route'] ?? [];
if (empty($flatGate['feasible'])) {
    throw new RuntimeException('Flat gate route should still be feasible after clearing hierarchy tables.');
}
if ((int) ($hierGate['nodes_explored'] ?? 0) >= (int) ($flatGate['nodes_explored'] ?? PHP_INT_MAX)) {
    throw new RuntimeException('Hierarchical route should explore fewer nodes than flat route.');
}


$jumpWithHierarchy = $resultCarrier['jump_route'] ?? [];
$hybridWithHierarchy = $resultCarrier['hybrid_route'] ?? [];
$pdo->exec('DELETE FROM jump_constellation_portals');
$pdo->exec('DELETE FROM jump_constellation_edges');
$pdo->exec('DELETE FROM jump_midpoint_candidates');
$engine->refresh();
$flatJumpResult = $engine->compute(RouteRequest::fromLegacyOptions($optionsCarrier));
$jumpWithoutHierarchy = $flatJumpResult['jump_route'] ?? [];
$hybridWithoutHierarchy = $flatJumpResult['hybrid_route'] ?? [];
if (empty($jumpWithoutHierarchy['feasible'])) {
    throw new RuntimeException('Expected jump route to stay feasible without jump-constellation hierarchy.');
}
if (!empty($hybridWithHierarchy['feasible']) && empty($hybridWithoutHierarchy['feasible'])) {
    throw new RuntimeException('Hybrid route feasibility should be preserved when hierarchy data is removed.');
}
if ((int) ($jumpWithHierarchy['nodes_explored'] ?? PHP_INT_MAX) > (int) ($jumpWithoutHierarchy['nodes_explored'] ?? 0)) {
    throw new RuntimeException('Jump hierarchy should not explore more nodes than flat jump search.');
}
if ((int) ($hybridWithHierarchy['nodes_explored'] ?? PHP_INT_MAX) > (int) ($hybridWithoutHierarchy['nodes_explored'] ?? 0)) {
    throw new RuntimeException('Hybrid hierarchy should not explore more nodes than flat hybrid search.');
}

echo "Navigation engine cases passed.\n";
