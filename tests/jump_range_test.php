<?php

declare(strict_types=1);

use Everoute\Routing\JumpRangeCalculator;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Routing/JumpRangeCalculator.php';
}

$calculator = new JumpRangeCalculator(__DIR__ . '/../config/jump_ranges.php');

$previous = null;
for ($level = 0; $level <= 5; $level++) {
    $range = $calculator->effectiveRange('carrier', $level);
    if ($range === null) {
        throw new RuntimeException('Expected a range for carrier.');
    }
    if ($previous !== null && $range < $previous) {
        throw new RuntimeException('Jump range should be monotonic across skill levels.');
    }
    $previous = $range;
}

$level5 = $calculator->effectiveRange('carrier', 5);
if ($level5 === null || abs($level5 - 10.0) > 0.01) {
    throw new RuntimeException('Unexpected level 5 range for carrier.');
}

echo "Jump range test passed.\n";
