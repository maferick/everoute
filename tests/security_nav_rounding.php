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
    [0.44, 0.4],
    [0.49, 0.5],
    [0.50, 0.5],
    [0.04, 0.0],
];

foreach ($cases as [$raw, $expected]) {
    $actual = SecurityStatus::navFromRaw((float) $raw);
    if (abs($actual - $expected) > 0.0001) {
        throw new RuntimeException(sprintf('Expected %.2f to round to %.1f, got %.2f.', $raw, $expected, $actual));
    }
}

echo "Security nav rounding test passed.\n";
