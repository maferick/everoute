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
    require_once __DIR__ . '/../src/Routing/RouteRequest.php';
    require_once __DIR__ . '/../src/Routing/ShipProfile.php';
    require_once __DIR__ . '/../src/Routing/PreferenceProfile.php';
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
    echo "SQLite driver not available, skipping jump station midpoint test.\n";
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
    [1, 'Start', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'Mid-No-Station', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 6 * $ly, 0.0, 0.0],
    [3, 'Mid-Station-A', 0.2, 0.2, 0.2, 1, 1, 0, 1, 1, 1, 1.0, 0.0, 6 * $ly, 0.0],
    [4, 'Destination', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 10 * $ly, 2 * $ly, 0.0],
    [5, 'Mid-Station-B', 0.2, 0.2, 0.2, 1, 1, 0, 1, 1, 1, 1.0, 6 * $ly, 6 * $ly, 0.0],
];
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systems as $system) {
    $stmt->execute($system);
    $pdo->prepare('INSERT INTO system_risk VALUES (?, 0, 0, 0, ?, ?, 0, 0, 0, 0, ?)')->execute([$system[0], gmdate('c'), gmdate('c'), gmdate('c')]);
}

$neighborMap7 = [
    1 => [2, 3],
    2 => [1, 4],
    3 => [1, 5],
    4 => [2, 5],
    5 => [3, 4],
];
$neighborStmt = $pdo->prepare('INSERT INTO jump_neighbors VALUES (?, ?, ?, ?, 1, ?)');
foreach ([7, 10] as $rangeLy) {
    foreach ($neighborMap7 as $systemId => $neighbors) {
        $neighborStmt->execute([$systemId, $rangeLy, count($neighbors), JumpNeighborCodec::encodeV1($neighbors), gmdate('c')]);
    }
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

$base = [
    'from' => 'Start',
    'to' => 'Destination',
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'titan',
    'jump_skill_level' => 5,
    'safety_vs_speed' => 50,
];

$normal = $engine->compute(RouteRequest::fromLegacyOptions($base));
$normalRoute = $normal['jump_route'] ?? [];
if (empty($normalRoute['feasible'])) {
    throw new RuntimeException('Expected baseline jump route to be feasible.');
}
$normalMidpoints = array_values(array_filter(array_map(static fn (array $s): string => (string) ($s['to'] ?? ''), $normalRoute['segments'] ?? []), static fn (string $name): bool => $name !== 'Destination'));
if (!in_array('Mid-No-Station', $normalMidpoints, true)) {
    throw new RuntimeException('Baseline route should be able to include non-station midpoint.');
}

$strict = $base;
$strict['require_station_midpoints'] = true;
$strict['station_type'] = 'npc';
$strict['avoid_strictness'] = 'strict';
$strictResult = $engine->compute(RouteRequest::fromLegacyOptions($strict));
$strictRoute = $strictResult['jump_route'] ?? [];
if (empty($strictRoute['feasible'])) {
    throw new RuntimeException('Strict station midpoint route should be feasible.');
}
$strictMidpoints = array_values(array_filter(array_map(static fn (array $s): string => (string) ($s['to'] ?? ''), $strictRoute['segments'] ?? []), static fn (string $name): bool => $name !== 'Destination'));
if (in_array('Mid-No-Station', $strictMidpoints, true)) {
    throw new RuntimeException('Strict station midpoint route must reject non-station midpoints.');
}
if (($strictRoute['midpoints_with_station'] ?? '') !== '2/2') {
    throw new RuntimeException('Strict station diagnostics should report all midpoints with stations.');
}

$soft = $base;
$soft['require_station_midpoints'] = true;
$soft['station_type'] = 'npc';
$soft['avoid_strictness'] = 'soft';
$softResult = $engine->compute(RouteRequest::fromLegacyOptions($soft));
$softRoute = $softResult['jump_route'] ?? [];
if (empty($softRoute['feasible'])) {
    throw new RuntimeException('Soft station midpoint route should be feasible.');
}
if (($softRoute['midpoints_with_station'] ?? '') !== '2/2') {
    throw new RuntimeException('Soft station penalty should prefer all-station midpoint chain when available.');
}
if (!empty($softRoute['station_midpoint_violations'])) {
    throw new RuntimeException('Soft station run should not report violations when all-station route exists.');
}

echo "Jump station midpoint constraints passed.\n";
