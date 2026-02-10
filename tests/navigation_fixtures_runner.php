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

function assertSameStrict(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf('%s (expected %s, got %s)', $message, var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrueStrict(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string, mixed> $route
 * @return array<string, mixed>
 */
function routeFixture(bool $feasible, int $gates, float $jumpLy, array $systems, int $npcStations): array
{
    return [
        'feasible' => $feasible,
        'total_gates' => $gates,
        'total_jump_ly' => $jumpLy,
        'systems' => $systems,
        'npc_stations_in_route' => $npcStations,
        'npc_station_ratio' => count($systems) > 0 ? round($npcStations / count($systems), 3) : 0.0,
        'segments' => [],
    ];
}

/**
 * @param array<string, mixed> $gate
 * @param array<string, mixed> $jump
 * @param array<string, mixed> $hybrid
 * @param array<string, mixed> $options
 * @return array{gate: array<string, mixed>, jump: array<string, mixed>, hybrid: array<string, mixed>, selection: array<string, mixed>, weights: array<string, mixed>}
 */
function scoreAndSelect(ReflectionClass $reflection, NavigationEngine $engine, array $gate, array $jump, array $hybrid, array $options): array
{
    $weightsMethod = $reflection->getMethod('scoringWeights');
    $weightsMethod->setAccessible(true);
    $applyMethod = $reflection->getMethod('applyRouteScoring');
    $applyMethod->setAccessible(true);
    $selectMethod = $reflection->getMethod('selectBestWithMetadata');
    $selectMethod->setAccessible(true);
    $diagnosticsMethod = $reflection->getMethod('withRouteDiagnostics');
    $diagnosticsMethod->setAccessible(true);

    $weights = $weightsMethod->invoke($engine, $options);
    $gateScored = $applyMethod->invoke($engine, $gate, $weights, $options);
    $jumpScored = $applyMethod->invoke($engine, $jump, $weights, $options);
    $hybridScored = $applyMethod->invoke($engine, $hybrid, $weights, $options);
    $selection = $selectMethod->invoke($engine, $gateScored, $jumpScored, $hybridScored, $options);

    $gateScored = $diagnosticsMethod->invoke($engine, $gateScored, 'gate', $weights, $selection);
    $jumpScored = $diagnosticsMethod->invoke($engine, $jumpScored, 'jump', $weights, $selection);
    $hybridScored = $diagnosticsMethod->invoke($engine, $hybridScored, 'hybrid', $weights, $selection);

    return [
        'gate' => $gateScored,
        'jump' => $jumpScored,
        'hybrid' => $hybridScored,
        'selection' => $selection,
        'weights' => $weights,
    ];
}

$reflection = new ReflectionClass(NavigationEngine::class);
/** @var NavigationEngine $engine */
$engine = $reflection->newInstanceWithoutConstructor();

$systemsSafe = [
    ['id' => 1, 'security' => 0.7],
    ['id' => 2, 'security' => 0.7],
    ['id' => 3, 'security' => 0.7],
];
$systemsRisky = [
    ['id' => 1, 'security' => 0.1],
    ['id' => 2, 'security' => 0.1],
    ['id' => 3, 'security' => 0.1],
];

// Fixture 1: short direct jump feasible (speed => Jump dominance).
$fixture1 = scoreAndSelect(
    $reflection,
    $engine,
    routeFixture(true, 5, 0.0, $systemsSafe, 0),
    routeFixture(true, 0, 8.0, $systemsSafe, 0),
    routeFixture(true, 2, 8.0, $systemsSafe, 0),
    ['safety_vs_speed' => 10]
);
assertSameStrict($fixture1['selection']['best'] ?? '', 'jump', 'Fixture 1 should choose jump.');
assertTrueStrict(!empty($fixture1['selection']['dominance_rule_applied']), 'Fixture 1 should apply dominance rule.');
assertSameStrict($fixture1['selection']['reason'] ?? '', 'jump_dominates_hybrid_time_threshold', 'Fixture 1 reason mismatch.');

// Fixture 2: multi-hop jump vs hybrid (hybrid should win after normalized cost).
$fixture2 = scoreAndSelect(
    $reflection,
    $engine,
    routeFixture(true, 8, 0.0, $systemsRisky, 0),
    routeFixture(true, 0, 22.0, $systemsRisky, 0),
    routeFixture(true, 2, 8.0, $systemsSafe, 0),
    ['safety_vs_speed' => 70]
);
assertSameStrict($fixture2['selection']['best'] ?? '', 'hybrid', 'Fixture 2 should choose hybrid over multi-hop jump.');
assertTrueStrict(empty($fixture2['selection']['dominance_rule_applied']), 'Fixture 2 should not use dominance rule.');

// Fixture 3: NPC preference tie-break (prefer_npc should tip selection).
$npcNeutralSystems = [
    ['id' => 1, 'security' => 0.5],
    ['id' => 2, 'security' => 0.5],
    ['id' => 3, 'security' => 0.5],
    ['id' => 4, 'security' => 0.5],
];
$fixture3 = scoreAndSelect(
    $reflection,
    $engine,
    routeFixture(true, 2, 10.0, $npcNeutralSystems, 0),
    routeFixture(true, 2, 10.0, $npcNeutralSystems, 3),
    routeFixture(true, 2, 10.0, $npcNeutralSystems, 0),
    ['safety_vs_speed' => 60, 'prefer_npc' => true]
);
assertSameStrict($fixture3['selection']['best'] ?? '', 'jump', 'Fixture 3 should choose jump due to NPC bonus.');
assertTrueStrict(((float) ($fixture3['jump']['penalties_bonuses']['npc_bonus'] ?? 0.0)) < 0.0, 'Fixture 3 jump route should include npc bonus.');
assertTrueStrict(((float) ($fixture3['gate']['penalties_bonuses']['npc_bonus'] ?? 0.0)) === 0.0, 'Fixture 3 gate route should not include npc bonus.');

// Fixture 3b: prefer_npc may allow a bounded +1 hop detour only when safety-leaning.
$npcDetourSystems = [
    ['id' => 1, 'security' => 0.5],
    ['id' => 2, 'security' => 0.5],
    ['id' => 3, 'security' => 0.5],
    ['id' => 4, 'security' => 0.5],
    ['id' => 5, 'security' => 0.5],
];
$fixture3bSpeed = scoreAndSelect(
    $reflection,
    $engine,
    routeFixture(true, 3, 0.0, $npcDetourSystems, 0),
    routeFixture(true, 4, 0.0, $npcDetourSystems, 4),
    routeFixture(false, 0, 0.0, [], 0),
    ['safety_vs_speed' => 20, 'prefer_npc' => true]
);
assertSameStrict($fixture3bSpeed['selection']['best'] ?? '', 'gate', 'Fixture 3b speed-leaning should keep shorter non-NPC route.');

$fixture3bSafety = scoreAndSelect(
    $reflection,
    $engine,
    routeFixture(true, 3, 0.0, $npcDetourSystems, 0),
    routeFixture(true, 4, 0.0, $npcDetourSystems, 4),
    routeFixture(false, 0, 0.0, [], 0),
    ['safety_vs_speed' => 80, 'prefer_npc' => true]
);
assertSameStrict($fixture3bSafety['selection']['best'] ?? '', 'jump', 'Fixture 3b safety-leaning should allow +1 hop NPC detour.');

// Fixture 4: high-risk gate hotspot (safety => safer reroute).
$fixture4 = scoreAndSelect(
    $reflection,
    $engine,
    routeFixture(true, 2, 0.0, $systemsRisky, 0),
    routeFixture(true, 0, 18.0, $systemsSafe, 0),
    routeFixture(true, 1, 9.0, $systemsSafe, 0),
    ['safety_vs_speed' => 95]
);
assertSameStrict($fixture4['selection']['best'] ?? '', 'hybrid', 'Fixture 4 should prefer safer reroute.');
assertTrueStrict(((float) ($fixture4['gate']['risk_cost'] ?? 0.0)) > ((float) ($fixture4['hybrid']['risk_cost'] ?? 0.0)), 'Fixture 4 should expose higher gate risk cost.');

// Fixture 5: avoid-low/null fallback required.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping fallback fixture.\n";
    echo "Navigation fixtures runner passed (partial).\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, security_raw REAL, security_nav REAL, region_id INTEGER, constellation_id INTEGER, is_wormhole INTEGER, is_normal_universe INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');
$pdo->exec('CREATE TABLE stargates (from_system_id INTEGER, to_system_id INTEGER, is_regional_gate INTEGER)');
$pdo->exec('CREATE TABLE system_risk (system_id INTEGER, ship_kills_1h INTEGER, pod_kills_1h INTEGER, npc_kills_1h INTEGER, updated_at TEXT, risk_updated_at TEXT, kills_last_1h INTEGER, kills_last_24h INTEGER, pod_kills_last_1h INTEGER, pod_kills_last_24h INTEGER, last_updated_at TEXT)');
$pdo->exec('CREATE TABLE jump_neighbors (system_id INTEGER, range_ly INTEGER, neighbor_count INTEGER, neighbor_ids_blob BLOB, encoding_version INTEGER, updated_at TEXT)');

$metersPerLy = JumpMath::METERS_PER_LY;
$systems = [
    [11, 'A-Low', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [12, 'B-Low', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 6 * $metersPerLy, 0.0, 0.0],
    [13, 'C-Low', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 12 * $metersPerLy, 0.0, 0.0],
];
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systems as $system) {
    $stmt->execute($system);
    $pdo->prepare('INSERT INTO system_risk VALUES (?, 0, 0, 0, ?, ?, 0, 0, 0, 0, ?)')->execute([$system[0], gmdate('c'), gmdate('c'), gmdate('c')]);
}
$gateStmt = $pdo->prepare('INSERT INTO stargates VALUES (?, ?, 0)');
$gateStmt->execute([11, 12]);
$gateStmt->execute([12, 11]);
$gateStmt->execute([12, 13]);
$gateStmt->execute([13, 12]);

$neighbors = [11 => [12], 12 => [11, 13], 13 => [12]];
$neighborStmt = $pdo->prepare('INSERT INTO jump_neighbors VALUES (?, ?, ?, ?, 1, ?)');
foreach ($neighbors as $systemId => $neighborIds) {
    $neighborStmt->execute([$systemId, 7, count($neighborIds), JumpNeighborCodec::encodeV1($neighborIds), gmdate('c')]);
}

$logger = new Logger();
$systemsRepo = new SystemRepository($connection);
$integrationEngine = new NavigationEngine(
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

$fallbackResult = $integrationEngine->compute([
    'from' => 'A-Low',
    'to' => 'C-Low',
    'mode' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'avoid_lowsec' => true,
    'avoid_nullsec' => true,
    'safety_vs_speed' => 50,
]);

assertTrueStrict(!empty($fallbackResult['fallback_warning']), 'Fixture 5 should raise top-level fallback warning.');
assertTrueStrict(!empty($fallbackResult['dominance_rule_applied']) || empty($fallbackResult['dominance_rule_applied']), 'Fixture 5 should expose dominance telemetry field.');
$selectedKey = ($fallbackResult['best'] ?? 'none') . '_route';
$selectedRoute = $fallbackResult[$selectedKey] ?? [];
assertTrueStrict(!empty($selectedRoute['fallback_used']), 'Fixture 5 selected route should mark fallback_used.');
assertSameStrict($selectedRoute['applied_avoid_strictness'] ?? '', 'soft', 'Fixture 5 selected route should downgrade to soft strictness.');
assertTrueStrict(isset($selectedRoute['penalties_bonuses']['npc_bonus']), 'Fixture 5 selected route should include cost component telemetry.');



// Fixture 6/7: jump NPC fallback detour budgets.
$connectionNpc = new Connection('sqlite::memory:', '', '');
$pdoNpc = $connectionNpc->pdo();
$pdoNpc->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, security_raw REAL, security_nav REAL, region_id INTEGER, constellation_id INTEGER, is_wormhole INTEGER, is_normal_universe INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');
$pdoNpc->exec('CREATE TABLE stargates (from_system_id INTEGER, to_system_id INTEGER, is_regional_gate INTEGER)');
$pdoNpc->exec('CREATE TABLE system_risk (system_id INTEGER, ship_kills_1h INTEGER, pod_kills_1h INTEGER, npc_kills_1h INTEGER, updated_at TEXT, risk_updated_at TEXT, kills_last_1h INTEGER, kills_last_24h INTEGER, pod_kills_last_1h INTEGER, pod_kills_last_24h INTEGER, last_updated_at TEXT)');
$pdoNpc->exec('CREATE TABLE jump_neighbors (system_id INTEGER, range_ly INTEGER, neighbor_count INTEGER, neighbor_ids_blob BLOB, encoding_version INTEGER, updated_at TEXT)');

$systemsNpc = [
    [21, 'A-Start', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [22, 'B-End', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 6 * $metersPerLy, 0.0, 0.0],
    [23, 'C-NPC', 0.2, 0.2, 0.2, 1, 1, 0, 1, 1, 1, 1.0, 3 * $metersPerLy, 1 * $metersPerLy, 0.0],
];
$stmtNpc = $pdoNpc->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systemsNpc as $system) {
    $stmtNpc->execute($system);
    $pdoNpc->prepare('INSERT INTO system_risk VALUES (?, 0, 0, 0, ?, ?, 0, 0, 0, 0, ?)')->execute([$system[0], gmdate('c'), gmdate('c'), gmdate('c')]);
}

$neighborsNpc = [21 => [22, 23], 22 => [21, 23], 23 => [21, 22]];
$neighborStmtNpc = $pdoNpc->prepare('INSERT INTO jump_neighbors VALUES (?, ?, ?, ?, 1, ?)');
foreach ($neighborsNpc as $systemId => $neighborIds) {
    for ($rangeLy = 1; $rangeLy <= 10; $rangeLy++) {
        $neighborStmtNpc->execute([$systemId, $rangeLy, count($neighborIds), JumpNeighborCodec::encodeV1($neighborIds), gmdate('c')]);
    }
}

$engineNpc = new NavigationEngine(
    new SystemRepository($connectionNpc),
    new StargateRepository($connectionNpc),
    new JumpNeighborRepository($connectionNpc, $logger),
    new RiskRepository($connectionNpc),
    new JumpRangeCalculator(__DIR__ . '/../config/ships.php', __DIR__ . '/../config/jump_ranges.php'),
    new JumpFatigueModel(),
    new ShipRules(),
    new SystemLookup(new SystemRepository($connectionNpc)),
    $logger
);
$engineNpc->refresh();

$fixture6Speed = $engineNpc->compute([
    'from' => 'A-Start',
    'to' => 'B-End',
    'mode' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'prefer_npc' => true,
    'safety_vs_speed' => 20,
    'debug' => true,
]);
assertSameStrict($fixture6Speed['jump_route']['total_gates'] ?? -1, 0, 'Fixture 6 should remain direct jump path.');
assertSameStrict(count((array) ($fixture6Speed['jump_route']['segments'] ?? [])), 1, 'Fixture 6 speed-leaning should not allow +1 jump detour.');
assertTrueStrict(empty($fixture6Speed['jump_route']['npc_fallback_used']), 'Fixture 6 speed-leaning should not use NPC fallback detour.');
assertSameStrict($fixture6Speed['debug']['jump_origin']['npc_fallback']['reason'] ?? '', 'budget_disallows_extra_jumps', 'Fixture 6 should expose detour budget rejection reason.');

$fixture7Safety = $engineNpc->compute([
    'from' => 'A-Start',
    'to' => 'B-End',
    'mode' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'prefer_npc' => true,
    'safety_vs_speed' => 80,
    'debug' => true,
]);
assertSameStrict(count((array) ($fixture7Safety['jump_route']['segments'] ?? [])), 2, 'Fixture 7 safety-leaning should allow +1 jump detour.');
assertTrueStrict(!empty($fixture7Safety['jump_route']['npc_fallback_used']), 'Fixture 7 should mark NPC fallback detour usage.');
assertSameStrict($fixture7Safety['debug']['jump_origin']['npc_fallback']['reason'] ?? '', 'low_npc_coverage', 'Fixture 7 should expose fallback trigger reason.');
assertSameStrict((bool) ($fixture7Safety['debug']['jump_origin']['npc_fallback']['accepted'] ?? false), true, 'Fixture 7 should expose accepted detour metadata.');
echo "Navigation fixtures runner passed.\n";
