<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\NavigationEngine;
use Everoute\Routing\ShipRules;
use Everoute\Routing\SystemLookup;
use Everoute\Security\Logger;
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
    require_once __DIR__ . '/../src/Universe/JumpNeighborRepository.php';
    require_once __DIR__ . '/../src/Universe/StargateRepository.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
    require_once __DIR__ . '/../src/Risk/RiskRepository.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping subcap gate routing tests.\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, security_raw REAL, security_nav REAL, region_id INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');
$pdo->exec('CREATE TABLE stargates (from_system_id INTEGER, to_system_id INTEGER, is_regional_gate INTEGER)');
$pdo->exec('CREATE TABLE system_risk (system_id INTEGER, kills_last_1h INTEGER, kills_last_24h INTEGER, pod_kills_last_1h INTEGER, pod_kills_last_24h INTEGER, last_updated_at TEXT)');

$systems = [
    [1, 'HS-A', 0.6, 0.6, 0.6, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [2, 'HS-B', 0.7, 0.7, 0.7, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [3, 'HS-C', 0.65, 0.65, 0.65, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [4, 'LS-1', 0.3, 0.3, 0.3, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [5, 'LS-2', 0.2, 0.2, 0.2, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [6, 'NS-1', -0.2, -0.2, -0.2, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
    [7, 'NS-2', -0.3, -0.3, -0.3, 1, 0, 0, 1.0, 0.0, 0.0, 0.0],
];
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($systems as $system) {
    $stmt->execute($system);
    $pdo->prepare('INSERT INTO system_risk VALUES (?, 0, 0, 0, 0, ?)')
        ->execute([$system[0], gmdate('c')]);
}

$edges = [
    [1, 3],
    [3, 2],
    [1, 4],
    [4, 5],
    [5, 2],
    [6, 7],
    [7, 4],
];
$gateStmt = $pdo->prepare('INSERT INTO stargates VALUES (?, ?, ?)');
foreach ($edges as [$from, $to]) {
    $gateStmt->execute([$from, $to, 0]);
    $gateStmt->execute([$to, $from, 0]);
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
    'mode' => 'subcap',
    'ship_class' => 'subcap',
    'preference' => 'shorter',
];

$hsOptions = $baseOptions + [
    'from' => 'HS-A',
    'to' => 'HS-B',
    'avoid_lowsec' => true,
    'avoid_nullsec' => true,
];
$hsResult = $engine->compute($hsOptions);
$hsGate = $hsResult['gate_route'] ?? [];
if (empty($hsGate['feasible'])) {
    throw new RuntimeException('HS to HS route should be feasible with avoid flags.');
}
foreach ($hsGate['systems'] as $system) {
    if ((float) $system['security'] < 0.5) {
        throw new RuntimeException('HS to HS route must stay in highsec with avoid flags.');
    }
}
if (!empty($hsGate['exception_corridor']['start']['required']) || !empty($hsGate['exception_corridor']['destination']['required'])) {
    throw new RuntimeException('HS to HS route should not require exception corridors.');
}

$nsToHsOptions = $baseOptions + [
    'from' => 'NS-1',
    'to' => 'HS-A',
    'avoid_lowsec' => false,
    'avoid_nullsec' => true,
];
$nsToHsResult = $engine->compute($nsToHsOptions);
$nsToHsGate = $nsToHsResult['gate_route'] ?? [];
if (empty($nsToHsGate['feasible'])) {
    throw new RuntimeException('NS to HS route should be feasible with avoid null.');
}
if (empty($nsToHsGate['exception_corridor']['start']['required']) || ($nsToHsGate['exception_corridor']['start']['hops'] ?? 0) !== 2) {
    throw new RuntimeException('NS to HS route should use minimal nullsec corridor at start.');
}
if (!empty($nsToHsGate['exception_corridor']['destination']['required'])) {
    throw new RuntimeException('NS to HS route should not require destination corridor.');
}
$firstNonNull = null;
foreach ($nsToHsGate['systems'] as $index => $system) {
    if ((float) $system['security'] > 0.0) {
        $firstNonNull = $firstNonNull ?? $index;
        if ($index < $firstNonNull) {
            throw new RuntimeException('Unexpected nullsec ordering in NS to HS route.');
        }
    }
}
foreach ($nsToHsGate['systems'] as $index => $system) {
    if ($firstNonNull !== null && $index >= $firstNonNull && (float) $system['security'] <= 0.0) {
        throw new RuntimeException('NS to HS route should not re-enter nullsec after reaching core.');
    }
}

$hsToNsOptions = $baseOptions + [
    'from' => 'HS-A',
    'to' => 'NS-1',
    'avoid_lowsec' => false,
    'avoid_nullsec' => true,
];
$hsToNsResult = $engine->compute($hsToNsOptions);
$hsToNsGate = $hsToNsResult['gate_route'] ?? [];
if (empty($hsToNsGate['feasible'])) {
    throw new RuntimeException('HS to NS route should be feasible with avoid null.');
}
if (!empty($hsToNsGate['exception_corridor']['start']['required'])) {
    throw new RuntimeException('HS to NS route should not require start corridor.');
}
if (empty($hsToNsGate['exception_corridor']['destination']['required']) || ($hsToNsGate['exception_corridor']['destination']['hops'] ?? 0) !== 2) {
    throw new RuntimeException('HS to NS route should use minimal nullsec corridor at destination.');
}
$firstNull = null;
foreach ($hsToNsGate['systems'] as $index => $system) {
    if ((float) $system['security'] <= 0.0) {
        $firstNull = $firstNull ?? $index;
    }
}
if ($firstNull !== null) {
    foreach ($hsToNsGate['systems'] as $index => $system) {
        $security = (float) $system['security'];
        if ($index < $firstNull && $security <= 0.0) {
            throw new RuntimeException('HS to NS route should not enter nullsec before destination corridor.');
        }
        if ($index >= $firstNull && $security > 0.0) {
            throw new RuntimeException('HS to NS route should remain in nullsec after corridor entry.');
        }
    }
}

echo "Subcap gate routing avoid cases passed.\n";
