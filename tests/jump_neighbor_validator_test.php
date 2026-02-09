<?php

declare(strict_types=1);

use Everoute\DB\Connection;
use Everoute\Universe\JumpNeighborValidator;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/DB/Connection.php';
    require_once __DIR__ . '/../src/Universe/JumpNeighborValidator.php';
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SQLite driver not available, skipping jump neighbor validator test.\n";
    exit(0);
}

$connection = new Connection('sqlite::memory:', '', '');
$pdo = $connection->pdo();
$pdo->exec('CREATE TABLE systems (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE jump_neighbors (system_id INTEGER, range_ly INTEGER, neighbor_count INTEGER, neighbor_ids_blob BLOB, updated_at TEXT)');

$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?)');
$stmt->execute([1, 'Alpha']);
$stmt->execute([2, 'Beta']);

$insert = $pdo->prepare('INSERT INTO jump_neighbors VALUES (?, ?, ?, ?, ?)');
$insert->execute([1, 1, 2, '', gmdate('c')]);
$insert->execute([1, 2, 3, '', gmdate('c')]);
$insert->execute([1, 3, 5, '', gmdate('c')]);
$insert->execute([2, 1, 1, '', gmdate('c')]);
$insert->execute([2, 2, 1, '', gmdate('c')]);
$insert->execute([2, 3, 2, '', gmdate('c')]);

$validator = new JumpNeighborValidator($connection);
$result = $validator->validateMonotonicity([1, 2, 3]);
if ($result['violations_found'] !== 0) {
    throw new RuntimeException('Expected monotonic neighbor counts for sample systems.');
}

echo "Jump neighbor validator test passed.\n";
