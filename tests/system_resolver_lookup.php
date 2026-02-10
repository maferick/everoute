<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Routing\SystemResolver;
use Everoute\Security\Logger;
use Everoute\Universe\SystemRepository;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/DB/Connection.php';
    require_once __DIR__ . '/../src/Security/Logger.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
    require_once __DIR__ . '/../src/Routing/SystemResolver.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping resolver lookup test.\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, security_raw REAL, region_id INTEGER, constellation_id INTEGER, is_wormhole INTEGER, is_normal_universe INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');
$insert = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute([30005087, 'Liparer', 0.1, 0.1, 100, 200, 0, 1, 0, 0, 1.0, 1.0, 2.0, 3.0]);
$insert->execute([30005295, 'Murethand', 0.2, 0.2, 100, 200, 0, 1, 0, 0, 1.0, 4.0, 5.0, 6.0]);

$resolver = new SystemResolver(new SystemRepository($connection), new Logger());
$liparer = $resolver->resolveSystem('Liparer');
$murethand = $resolver->resolveSystem('Murethand');

if (($liparer['id'] ?? null) !== 30005087) {
    throw new RuntimeException('Expected Liparer to resolve to id 30005087.');
}
if (($murethand['id'] ?? null) !== 30005295) {
    throw new RuntimeException('Expected Murethand to resolve to id 30005295.');
}

echo "system_resolver_lookup:ok\n";
