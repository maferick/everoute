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
$pdo->exec('CREATE TABLE jump_neighbors (system_id INTEGER, range_ly INTEGER, neighbor_count INTEGER, neighbor_ids_blob BLOB, encoding_version INTEGER, updated_at TEXT)');

$stmt = $pdo->prepare('INSERT INTO systems VALUES (?, ?)');
$stmt->execute([1, 'Alpha']);
$stmt->execute([2, 'Beta']);
$stmt->execute([3, 'Gamma']);

$insert = $pdo->prepare('INSERT INTO jump_neighbors VALUES (?, ?, ?, ?, ?, ?)');
$insert->execute([1, 1, 2, '', 1, gmdate('c')]);
$insert->execute([1, 2, 3, '', 1, gmdate('c')]);
$insert->execute([1, 3, 5, '', 1, gmdate('c')]);
$insert->execute([2, 1, 1, '', 1, gmdate('c')]);
$insert->execute([2, 2, 1, '', 1, gmdate('c')]);
$insert->execute([2, 3, 2, '', 1, gmdate('c')]);

$validator = new JumpNeighborValidator($connection);
$result = $validator->validateMonotonicity([1, 2, 3]);
if ($result['violations_found'] !== 0) {
    throw new RuntimeException('Expected monotonic neighbor counts for sample systems.');
}


$subsetCompleteness = $validator->validateCompleteness([1, 2, 3], [1]);
if ($subsetCompleteness['missing_rows_found'] !== 0) {
    throw new RuntimeException('Expected no missing rows when validating only included routing systems.');
}

$fullCompleteness = $validator->validateCompleteness([1, 2, 3], [1, 2, 3]);
if ($fullCompleteness['missing_rows_found'] !== 1 || ($fullCompleteness['missing'][3] ?? 0) !== 3) {
    throw new RuntimeException('Expected missing rows for system IDs that were not precomputed.');
}

echo "Jump neighbor validator test passed.\n";
