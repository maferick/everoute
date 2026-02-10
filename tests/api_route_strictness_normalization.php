<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Http\ApiController;
use Everoute\Http\Request;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpMath;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\NavigationEngine;
use Everoute\Routing\RouteService;
use Everoute\Routing\ShipRules;
use Everoute\Routing\SystemLookup;
use Everoute\Security\Logger;
use Everoute\Security\RateLimiter;
use Everoute\Security\Validator;
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
    require_once __DIR__ . '/../src/Http/ApiController.php';
    require_once __DIR__ . '/../src/Http/Response.php';
    require_once __DIR__ . '/../src/Http/JsonResponse.php';
    require_once __DIR__ . '/../src/Http/Request.php';
    require_once __DIR__ . '/../src/Routing/Graph.php';
    require_once __DIR__ . '/../src/Routing/GraphStore.php';
    require_once __DIR__ . '/../src/Routing/Dijkstra.php';
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/JumpMath.php';
    require_once __DIR__ . '/../src/Routing/JumpShipType.php';
    require_once __DIR__ . '/../src/Routing/JumpRangeCalculator.php';
    require_once __DIR__ . '/../src/Routing/NavigationEngine.php';
    require_once __DIR__ . '/../src/Routing/RouteService.php';
    require_once __DIR__ . '/../src/Routing/ShipRules.php';
    require_once __DIR__ . '/../src/Routing/SystemLookup.php';
    require_once __DIR__ . '/../src/Security/Logger.php';
    require_once __DIR__ . '/../src/Security/RateLimiter.php';
    require_once __DIR__ . '/../src/Security/Validator.php';
    require_once __DIR__ . '/../src/Universe/JumpNeighborCodec.php';
    require_once __DIR__ . '/../src/Universe/JumpNeighborRepository.php';
    require_once __DIR__ . '/../src/Universe/StaticTableResolver.php';
    require_once __DIR__ . '/../src/Universe/StaticMetaRepository.php';
    require_once __DIR__ . '/../src/Universe/StargateRepository.php';
    require_once __DIR__ . '/../src/Universe/ConstellationGraphRepository.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
    require_once __DIR__ . '/../src/Risk/RiskRepository.php';
    require_once __DIR__ . '/../src/Risk/RiskScorer.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping API strictness normalization test.\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, security_raw REAL, security_nav REAL, region_id INTEGER, constellation_id INTEGER, is_wormhole INTEGER, is_normal_universe INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');
$pdo->exec('CREATE TABLE stargates (from_system_id INTEGER, to_system_id INTEGER, is_regional_gate INTEGER)');
$pdo->exec('CREATE TABLE system_risk (system_id INTEGER, ship_kills_1h INTEGER, pod_kills_1h INTEGER, npc_kills_1h INTEGER, updated_at TEXT, risk_updated_at TEXT, kills_last_1h INTEGER, kills_last_24h INTEGER, pod_kills_last_1h INTEGER, pod_kills_last_24h INTEGER, last_updated_at TEXT)');
$pdo->exec('CREATE TABLE jump_neighbors (system_id INTEGER, range_ly INTEGER, neighbor_count INTEGER, neighbor_ids_blob BLOB, encoding_version INTEGER, updated_at TEXT)');
$pdo->exec('CREATE TABLE risk_meta (provider TEXT PRIMARY KEY, updated_at TEXT, last_modified TEXT)');
$pdo->exec('CREATE TABLE static_meta (id INTEGER PRIMARY KEY, active_sde_build_number INTEGER NULL, precompute_version INTEGER NOT NULL, built_at TEXT NOT NULL, active_build_id TEXT NULL)');
$pdo->prepare('INSERT INTO static_meta (id, active_sde_build_number, precompute_version, built_at, active_build_id) VALUES (1, NULL, 1, ?, NULL)')->execute([gmdate('c')]);

$metersPerLy = JumpMath::METERS_PER_LY;
$systems = [
    [1, 'LS-Start', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'LS-Mid', 0.3, 0.3, 0.3, 1, 1, 0, 1, 0, 0, 1.0, 6 * $metersPerLy, 0.0, 0.0],
    [3, 'LS-End', 0.2, 0.2, 0.2, 1, 1, 0, 1, 0, 0, 1.0, 12 * $metersPerLy, 0.0, 0.0],
];
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
$riskRepo = new RiskRepository($connection);
$engine = new NavigationEngine(
    $systemsRepo,
    new StargateRepository($connection),
    new JumpNeighborRepository($connection, $logger),
    $riskRepo,
    new JumpRangeCalculator(__DIR__ . '/../config/ships.php', __DIR__ . '/../config/jump_ranges.php'),
    new JumpFatigueModel(),
    new ShipRules(),
    new SystemLookup($systemsRepo),
    $logger
);
$routeService = new RouteService($engine, $logger, null, 600);
$controller = new ApiController($routeService, $riskRepo, $systemsRepo, new Validator(), new RateLimiter(1000, 1000));

$baseBody = [
    'from' => 'LS-Start',
    'to' => 'LS-End',
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'safety_vs_speed' => 50,
    'avoid_lowsec' => true,
    'avoid_nullsec' => false,
];

$implicitRequest = new Request('POST', '/api/route', [], $baseBody, [], '127.0.0.1');
$implicitResponse = $controller->route($implicitRequest);
$implicitPayload = json_decode($implicitResponse->body, true);
if (!is_array($implicitPayload)) {
    throw new RuntimeException('Expected JSON payload for implicit strictness request.');
}

if (($implicitPayload['gate_route']['requested_avoid_strictness'] ?? '') !== 'strict') {
    throw new RuntimeException('Omitted avoid_strictness should default to strict when avoid toggles are enabled.');
}
if (($implicitPayload['gate_route']['applied_avoid_strictness'] ?? '') !== 'soft') {
    throw new RuntimeException('Implicit strictness request should fall back to soft on infeasible strict path.');
}
if (empty($implicitPayload['gate_route']['fallback_used'])) {
    throw new RuntimeException('Implicit strictness request should mark fallback_used in route payload.');
}

$explicitSoftBody = $baseBody;
$explicitSoftBody['avoid_strictness'] = 'soft';
$softRequest = new Request('POST', '/api/route', [], $explicitSoftBody, [], '127.0.0.1');
$softResponse = $controller->route($softRequest);
$softPayload = json_decode($softResponse->body, true);
if (!is_array($softPayload)) {
    throw new RuntimeException('Expected JSON payload for explicit soft request.');
}

if (($softPayload['gate_route']['requested_avoid_strictness'] ?? '') !== 'soft') {
    throw new RuntimeException('Explicit avoid_strictness=soft should be preserved by API normalization.');
}
if (($softPayload['gate_route']['applied_avoid_strictness'] ?? '') !== 'soft') {
    throw new RuntimeException('Explicit soft strictness should remain soft in applied strictness.');
}
if (!empty($softPayload['gate_route']['fallback_used'])) {
    throw new RuntimeException('Explicit soft strictness should not be marked as fallback_used.');
}


$detourSpeedBody = $baseBody;
$detourSpeedBody['prefer_npc_stations'] = true;
$detourSpeedBody['safety_vs_speed'] = 50;
$speedRequest = new Request('POST', '/api/route', [], $detourSpeedBody, [], '127.0.0.1');
$speedResponse = $controller->route($speedRequest);
$speedPayload = json_decode($speedResponse->body, true);
if (!is_array($speedPayload)) {
    throw new RuntimeException('Expected JSON payload for speed-side detour policy request.');
}
if (($speedPayload['selected_policy']['slider_side'] ?? '') !== 'speed') {
    throw new RuntimeException('safety_vs_speed=50 should map to speed side policy.');
}
if ((int) ($speedPayload['selected_policy']['npc_detour_max_extra_jumps'] ?? -1) !== 0) {
    throw new RuntimeException('Speed side policy should not allow extra NPC detour jumps.');
}

$detourSafetyBody = $baseBody;
$detourSafetyBody['prefer_npc_stations'] = true;
$detourSafetyBody['safety_vs_speed'] = 51;
$safetyRequest = new Request('POST', '/api/route', [], $detourSafetyBody, [], '127.0.0.1');
$safetyResponse = $controller->route($safetyRequest);
$safetyPayload = json_decode($safetyResponse->body, true);
if (!is_array($safetyPayload)) {
    throw new RuntimeException('Expected JSON payload for safety-side detour policy request.');
}
if (($safetyPayload['selected_policy']['slider_side'] ?? '') !== 'safety') {
    throw new RuntimeException('safety_vs_speed=51 should map to safety side policy.');
}
if ((int) ($safetyPayload['selected_policy']['npc_detour_max_extra_jumps'] ?? -1) !== 1) {
    throw new RuntimeException('Safety side policy should allow +1 NPC detour jump when prefer_npc_stations=true.');
}
if (!in_array('NPC detour policy: safety side at 51% (may accept +1 jump detour for NPC coverage).', (array) ($safetyPayload['explanation'] ?? []), true)) {
    throw new RuntimeException('Explanation should include selected NPC detour policy details.');
}


$profileDefaultBody = $baseBody;
$profileDefaultBody['safety_vs_speed'] = 80;
unset($profileDefaultBody['preference_profile']);
$profileDefaultRequest = new Request('POST', '/api/route', [], $profileDefaultBody, [], '127.0.0.1');
$profileDefaultResponse = $controller->route($profileDefaultRequest);
$profileDefaultPayload = json_decode($profileDefaultResponse->body, true);
if (!is_array($profileDefaultPayload)) {
    throw new RuntimeException('Expected JSON payload for safety-derived preference profile request.');
}
if (($profileDefaultPayload['preference_profile'] ?? '') !== 'safety') {
    throw new RuntimeException('safety_vs_speed compatibility mapping should derive safety preference profile.');
}

$profileExplicitBody = $baseBody;
$profileExplicitBody['safety_vs_speed'] = 80;
$profileExplicitBody['preference_profile'] = 'speed';
$profileExplicitRequest = new Request('POST', '/api/route', [], $profileExplicitBody, [], '127.0.0.1');
$profileExplicitResponse = $controller->route($profileExplicitRequest);
$profileExplicitPayload = json_decode($profileExplicitResponse->body, true);
if (!is_array($profileExplicitPayload)) {
    throw new RuntimeException('Expected JSON payload for explicit preference profile request.');
}
if (($profileExplicitPayload['preference_profile'] ?? '') !== 'speed') {
    throw new RuntimeException('Explicit preference_profile should override safety_vs_speed compatibility mapping.');
}

echo "API strictness normalization test passed.\n";
