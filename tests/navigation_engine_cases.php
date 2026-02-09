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
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping navigation engine cases test.\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, security_raw REAL, security_nav REAL, region_id INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');
$pdo->exec('CREATE TABLE stargates (from_system_id INTEGER, to_system_id INTEGER, is_regional_gate INTEGER)');
$pdo->exec('CREATE TABLE system_risk (system_id INTEGER, kills_last_1h INTEGER, kills_last_24h INTEGER, pod_kills_last_1h INTEGER, pod_kills_last_24h INTEGER, last_updated_at TEXT)');
$pdo->exec('CREATE TABLE jump_neighbors (system_id INTEGER, range_ly INTEGER, neighbor_count INTEGER, neighbor_ids_blob BLOB, encoding_version INTEGER, updated_at TEXT)');

$metersPerLy = JumpMath::METERS_PER_LY;
$systems = [
    [1, '1-SMEB', 0.2, 0.2, 0.2, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'Midpoint-LS', 0.3, 0.3, 0.3, 1, 0, 0, 1.0, 6 * $metersPerLy, 0.0, 0.0],
    [3, 'Eurgrana', 0.4, 0.4, 0.4, 1, 0, 0, 1.0, 12 * $metersPerLy, 0.0, 0.0],
    [4, 'Highsec-A', 0.6, 0.6, 0.6, 1, 0, 0, 1.0, 5 * $metersPerLy, 0.0, 0.0],
    [5, 'Highsec-B', 0.6, 0.6, 0.6, 1, 0, 0, 1.0, 9 * $metersPerLy, 0.0, 0.0],
];
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systems as $system) {
    $stmt->execute($system);
    $pdo->prepare('INSERT INTO system_risk VALUES (?, 0, 0, 0, 0, ?)')
        ->execute([$system[0], gmdate('c')]);
}

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
    3 => [2, 5],
    4 => [2, 5],
    5 => [3, 4],
];
$neighborMap10 = [
    1 => [2, 3, 4, 5],
    2 => [1, 3, 4, 5],
    3 => [1, 2, 4, 5],
    4 => [1, 2, 3, 5],
    5 => [1, 2, 3, 4],
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
$resultCarrier = $engine->compute($optionsCarrier);
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
$resultJf = $engine->compute($optionsJf);
$jfJumpRoute = $resultJf['jump_route'] ?? [];
if (empty($jfJumpRoute['feasible'])) {
    throw new RuntimeException('Jump freighter should be able to land in highsec.');
}
foreach ($jfJumpRoute['segments'] as $segment) {
    if (($segment['to'] ?? '') === 'Highsec-A') {
        throw new RuntimeException('Jump freighter cannot use highsec midpoint.');
    }
}

echo "Navigation engine cases passed.\n";
