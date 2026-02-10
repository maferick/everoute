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
    require_once __DIR__ . '/../src/Universe/StargateRepository.php';
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

echo "API strictness normalization test passed.\n";
