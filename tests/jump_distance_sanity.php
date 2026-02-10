<?php

declare(strict_types=1);

use Everoute\Config\Env;
use Everoute\DB\Connection;
use Everoute\Routing\JumpMath;
use Everoute\Universe\SystemRepository;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Config/Env.php';
    require_once __DIR__ . '/../src/DB/Connection.php';
    require_once __DIR__ . '/../src/Routing/JumpMath.php';
    require_once __DIR__ . '/../src/Universe/SystemRepository.php';
}

$dsn = Env::get('DB_DSN', '');
if ($dsn === '') {
    echo "DB_DSN not configured, skipping jump distance sanity test.\n";
    exit(0);
}

$conn = new Connection($dsn, Env::get('DB_USER', ''), Env::get('DB_PASS', ''));
$repo = new SystemRepository($conn);
$from = $repo->findByNameOrId('1-SMEB');
$to = $repo->findByNameOrId('Irmalin');
if ($from === null || $to === null) {
    echo "Required systems not present, skipping jump distance sanity test.\n";
    exit(0);
}

$distance = JumpMath::distanceLy($from, $to);
if ($distance >= 7.0) {
    throw new RuntimeException(sprintf('Expected 1-SMEB -> Irmalin to be < 7.0 LY, got %.6f.', $distance));
}

echo "Jump distance sanity passed.\n";
