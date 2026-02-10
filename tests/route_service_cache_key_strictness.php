<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\RouteService;
use Everoute\Universe\StaticMetaRepository;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/DB/Connection.php';
    require_once __DIR__ . '/../src/Risk/RiskRepository.php';
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/RouteService.php';
    require_once __DIR__ . '/../src/Universe/StaticMetaRepository.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping route service cache key strictness test.\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE static_meta (id INTEGER PRIMARY KEY, active_sde_build_number INTEGER, precompute_version INTEGER, built_at TEXT, active_build_id TEXT)');
$pdo->exec("INSERT INTO static_meta (id, active_sde_build_number, precompute_version, built_at, active_build_id) VALUES (1, 999, 4, datetime('now'), NULL)");
$pdo->exec('CREATE TABLE sde_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, build_number INTEGER, installed_at TEXT)');
$pdo->exec("INSERT INTO sde_meta (build_number, installed_at) VALUES (1234, datetime('now'))");
$pdo->exec('CREATE TABLE system_risk (system_id INTEGER PRIMARY KEY, risk_updated_at TEXT, last_updated_at TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO system_risk (system_id, risk_updated_at, last_updated_at, updated_at) VALUES (30000142, '2024-01-01 00:05:00', NULL, NULL)");
$pdo->exec('CREATE TABLE risk_meta (provider TEXT PRIMARY KEY, etag TEXT, last_modified TEXT, checked_at TEXT, updated_at TEXT)');

$reflection = new ReflectionClass(RouteService::class);
/** @var RouteService $service */
$service = $reflection->newInstanceWithoutConstructor();
$routeCacheKey = $reflection->getMethod('routeCacheKey');
$routeCacheKey->setAccessible(true);

$staticMetaProperty = $reflection->getProperty('staticMetaRepository');
$staticMetaProperty->setAccessible(true);
$riskRepoProperty = $reflection->getProperty('riskRepository');
$riskRepoProperty->setAccessible(true);
$riskEpochSecondsProperty = $reflection->getProperty('riskEpochBucketSeconds');
$riskEpochSecondsProperty->setAccessible(true);

$staticMetaProperty->setValue($service, new StaticMetaRepository($connection));
$riskRepoProperty->setValue($service, new RiskRepository($connection));
$riskEpochSecondsProperty->setValue($service, 300);

$baseOptions = [
    'from' => 'A',
    'to' => 'B',
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'safety_vs_speed' => 50,
    'prefer_npc' => false,
];

$softByDefault = $baseOptions + [
    'avoid_lowsec' => false,
    'avoid_nullsec' => false,
];
$strictByDefault = $baseOptions + [
    'avoid_lowsec' => true,
    'avoid_nullsec' => false,
];
$explicitSoft = $strictByDefault + [
    'avoid_strictness' => 'soft',
];
$explicitStrict = $strictByDefault + [
    'avoid_strictness' => 'strict',
];

$keySoftDefault = $routeCacheKey->invoke($service, $softByDefault);
$keyStrictDefault = $routeCacheKey->invoke($service, $strictByDefault);
$keyExplicitSoft = $routeCacheKey->invoke($service, $explicitSoft);
$keyExplicitStrict = $routeCacheKey->invoke($service, $explicitStrict);

if ($keySoftDefault === $keyStrictDefault) {
    throw new RuntimeException('Cache key should differ when avoid toggles imply strict default strictness.');
}
if ($keyStrictDefault !== $keyExplicitStrict) {
    throw new RuntimeException('Implicit strict default should normalize to same key as explicit strict.');
}
if ($keyStrictDefault === $keyExplicitSoft) {
    throw new RuntimeException('Explicit soft strictness should not collide with strict-default key.');
}

$strictKeyInitial = $routeCacheKey->invoke($service, $explicitStrict);
$pdo->exec("UPDATE static_meta SET active_build_id = 'blue-build'");
$strictKeyWithBuild = $routeCacheKey->invoke($service, $explicitStrict);
if ($strictKeyInitial === $strictKeyWithBuild) {
    throw new RuntimeException('Cache key should include static build identifier.');
}

$pdo->exec("UPDATE system_risk SET risk_updated_at = '2024-01-01 00:10:00' WHERE system_id = 30000142");
$strictKeyWithRiskEpoch = $routeCacheKey->invoke($service, $explicitStrict);
if ($strictKeyWithBuild === $strictKeyWithRiskEpoch) {
    throw new RuntimeException('Cache key should include risk epoch bucket.');
}

echo "Route service cache key strictness test passed.\n";
