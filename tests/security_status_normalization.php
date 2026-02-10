<?php

declare(strict_types=1);

use Everoute\Universe\SecurityStatus;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Universe/SecurityStatus.php';
}

$cases = [
    [0.49, 0.49, 0.5, 'high'],
    [0.50, 0.50, 0.5, 'high'],
    [0.05, 0.05, 0.1, 'low'],
    [0.04, 0.04, 0.0, 'null'],
    [-0.01, -0.01, 0.0, 'null'],
    [-0.06, -0.06, -0.1, 'null'],
    [1.2, 1.0, 1.0, 'high'],
    [-1.2, -1.0, -1.0, 'null'],
];

foreach ($cases as [$input, $expectedNorm, $expectedEffective, $expectedBand]) {
    $normalized = SecurityStatus::normalizeSecurityRaw((float) $input);
    $effective = SecurityStatus::secEffectiveFromRaw((float) $input);
    $band = SecurityStatus::secBandFromEffective($effective);

    if (abs($normalized - $expectedNorm) > 0.0001) {
        throw new RuntimeException('normalizeSecurityRaw mismatch');
    }

    if (abs($effective - $expectedEffective) > 0.0001) {
        throw new RuntimeException('secEffectiveFromRaw mismatch');
    }

    if ($band !== $expectedBand) {
        throw new RuntimeException('secBandFromEffective mismatch');
    }
}

echo "SecurityStatus normalization test passed.\n";
