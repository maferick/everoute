<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Routing\JumpMath;
use Everoute\Universe\SystemRepository;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/DB/Connection.php';
    require_once __DIR__ . '/../src/Routing/JumpMath.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT, security REAL, region_id INTEGER, has_npc_station INTEGER, npc_station_count INTEGER, system_size_au REAL, x REAL, y REAL, z REAL)');

$metersPerLy = JumpMath::METERS_PER_LY;
$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([1, 'Origin', 0.2, 1, 0, 0, 1.0, 0.0, 0.0, 0.0]);
$stmt->execute([2, 'ThreeLY', 0.2, 1, 0, 0, 1.0, 3 * $metersPerLy, 0.0, 0.0]);

$repo = new SystemRepository($connection);
$systems = $repo->listForRouting();
$origin = $systems[0];
$target = $systems[1];

$distanceMeters = JumpMath::distanceMeters($origin, $target);
$distanceLy = JumpMath::distanceLy($origin, $target);

if (abs($distanceMeters - (3 * $metersPerLy)) > 1e6) {
    throw new RuntimeException('Meters distance conversion mismatch.');
}

if (abs($distanceLy - 3.0) > 1e-6) {
    throw new RuntimeException('LY distance conversion mismatch.');
}

echo "Jump math test passed.\n";
