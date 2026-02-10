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
    require_once __DIR__ . '/../src/Universe/ConstellationGraphRepository.php';
    require_once __DIR__ . '/../src/Universe/StargateRepository.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
    require_once __DIR__ . '/../src/Universe/StaticTableResolver.php';
    require_once __DIR__ . '/../src/Universe/StaticMetaRepository.php';
    require_once __DIR__ . '/../src/Risk/RiskRepository.php';
    require_once __DIR__ . '/../src/Risk/RiskScorer.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping fuel route preference regression test.\n";
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

$ly = JumpMath::METERS_PER_LY;
$systems = [
    [1, 'Origin', 0.2, 0.2, 0.2, 1, 10, 0, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'LongBridge', 0.2, 0.2, 0.2, 1, 10, 0, 1, 0, 0, 1.0, 6.5416666667 * $ly, 2.4913040460 * $ly, 0.0],
    [3, 'ShortA', -0.1, -0.1, -0.1, 1, 10, 0, 1, 0, 0, 1.0, 4.0 * $ly, 0.0, 0.0],
    [4, 'Destination', 0.2, 0.2, 0.2, 1, 10, 0, 1, 0, 0, 1.0, 12.0 * $ly, 0.0, 0.0],
    [5, 'ShortB', -0.1, -0.1, -0.1, 1, 10, 0, 1, 0, 0, 1.0, 8.0 * $ly, 0.0, 0.0],
];

$insertSystem = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systems as $system) {
    $insertSystem->execute($system);
    $pdo->prepare('INSERT INTO system_risk VALUES (?, 0, 0, 0, ?, ?, 0, 0, 0, 0, ?)')
        ->execute([$system[0], gmdate('c'), gmdate('c'), gmdate('c')]);
}

$pdo->exec("INSERT INTO jump_constellation_edges VALUES
    (7, 10, 10, 1, 2, 4.0)");
$pdo->exec("INSERT INTO jump_constellation_portals VALUES
    (10, 7, 1, 1),
    (10, 7, 2, 1),
    (10, 7, 3, 1),
    (10, 7, 4, 1),
    (10, 7, 5, 1)");
$pdo->exec("INSERT INTO jump_midpoint_candidates VALUES
    (10, 7, 2, 2.0),
    (10, 7, 3, 1.0),
    (10, 7, 5, 1.0)");

$insertNeighbor = $pdo->prepare('INSERT INTO jump_neighbors VALUES (?, ?, ?, ?, ?, ?)');
$neighborMap = [
    1 => [2, 3],
    2 => [1, 4],
    3 => [1, 5],
    4 => [2, 5],
    5 => [3, 4],
];
foreach ($neighborMap as $systemId => $neighbors) {
    $payload = JumpNeighborCodec::encodeV1($neighbors);
    $insertNeighbor->execute([$systemId, 7, count($neighbors), $payload, 1, gmdate('c')]);
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

$baseOptions = [
    'from' => 'Origin',
    'to' => 'Destination',
    'mode' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'safety_vs_speed' => 50,
    'avoid_nullsec' => true,
    'avoid_strictness' => 'soft',
];

$lowFuel = $engine->compute($baseOptions + [
    'fuel_per_ly_factor' => 0.0,
]);
$lowJump = $lowFuel['jump_route'] ?? [];
if (empty($lowJump['feasible'])) {
    throw new RuntimeException('Expected low-fuel jump route to be feasible.');
}
$lowRouteSystems = array_map(static fn (array $segment): string => (string) ($segment['to'] ?? ''), $lowJump['segments'] ?? []);
if (!in_array('LongBridge', $lowRouteSystems, true)) {
    throw new RuntimeException('Expected low-fuel route to prefer fewer jumps through LongBridge.');
}

$highFuel = $engine->compute($baseOptions + [
    'fuel_per_ly_factor' => 8.0,
]);
$highJump = $highFuel['jump_route'] ?? [];
if (empty($highJump['feasible'])) {
    throw new RuntimeException('Expected high-fuel jump route to be feasible.');
}
$highRouteSystems = array_map(static fn (array $segment): string => (string) ($segment['to'] ?? ''), $highJump['segments'] ?? []);
if (in_array('LongBridge', $highRouteSystems, true)) {
    throw new RuntimeException('Expected high-fuel route to avoid long-LY LongBridge chain.');
}
if (!in_array('ShortA', $highRouteSystems, true) || !in_array('ShortB', $highRouteSystems, true)) {
    throw new RuntimeException('Expected high-fuel route to use shorter-LY ShortA/ShortB chain.');
}

if ((float) ($highJump['total_fuel'] ?? 0.0) <= (float) ($lowJump['total_fuel'] ?? 0.0)) {
    throw new RuntimeException('Expected total fuel to increase when fuel_per_ly_factor increases.');
}

foreach ([$lowJump, $highJump] as $route) {
    if (!array_key_exists('jump_count', $route) || !array_key_exists('gate_count', $route) || !array_key_exists('total_ly', $route)) {
        throw new RuntimeException('Route summary missing explainability aggregates.');
    }
}

echo "fuel_route_preference_regression passed\n";
