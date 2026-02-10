<?php

declare(strict_types=1);

use Everoute\Routing\MovementRules;
use Everoute\Routing\SecurityNav;
use Everoute\Routing\ShipRules;
use Everoute\Universe\SecurityStatus;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Universe/SecurityStatus.php';
    require_once __DIR__ . '/../src/Routing/SecurityNav.php';
    require_once __DIR__ . '/../src/Routing/MovementRules.php';
    require_once __DIR__ . '/../src/Routing/ShipRules.php';
}

$cases = [
    [0.49, 0.5, 'high'],
    [0.44, 0.4, 'low'],
    [0.05, 0.1, 'low'],
    [0.04, 0.0, 'null'],
    [-0.01, 0.0, 'null'],
    [-0.06, -0.1, 'null'],
    [0.45, 0.5, 'high'],
];

foreach ($cases as [$raw, $expectedDisplay, $expectedClass]) {
    $actual = SecurityStatus::navFromRaw((float) $raw);
    if (abs($actual - $expectedDisplay) > 0.0001) {
        throw new RuntimeException(sprintf('Expected %.2f to round to %.1f, got %.2f.', $raw, $expectedDisplay, $actual));
    }

    $band = SecurityStatus::secBandFromDisplay($actual);
    if ($band !== $expectedClass) {
        throw new RuntimeException(sprintf('Expected %.2f to classify as %s, got %s.', $raw, $expectedClass, $band));
    }

    $routing = SecurityNav::getSecurityForRouting(['security_raw' => $raw]);
    if (abs($routing - $expectedDisplay) > 0.0001) {
        throw new RuntimeException(sprintf('Expected routing security %.2f to round to %.1f, got %.2f.', $raw, $expectedDisplay, $routing));
    }
}

$fromRaw = ['security' => 0.6, 'security_raw' => 0.49];
if (abs(SecurityNav::value($fromRaw) - 0.6) > 0.0001) {
    throw new RuntimeException('Expected security display to prefer explicit security column when present.');
}

$fromSecurity = ['security' => 0.49];
if (abs(SecurityNav::value($fromSecurity) - 0.5) > 0.0001) {
    throw new RuntimeException('Expected fallback to rounded security display when security_nav/security_raw are absent.');
}

$comparison = SecurityNav::debugComparison(['name' => 'Liparer', 'security_raw' => 0.45, 'security_nav' => 0.5]);
if (abs((float) $comparison['sec_routing'] - 0.5) > 0.0001) {
    throw new RuntimeException('Expected Liparer sec_routing to be 0.5.');
}

if (SecurityNav::isIllegalHighsecForCapital(['security_raw' => 0.45]) !== true) {
    throw new RuntimeException('Expected capital highsec check to reject sec 0.45 due to display rounding.');
}

if (SecurityNav::isIllegalHighsecForCapital(['security_raw' => 0.44]) !== false) {
    throw new RuntimeException('Expected capital highsec check to allow sec 0.44.');
}

$movementRules = new MovementRules();
if ($movementRules->getSystemSpaceType(['security' => 0.6, 'security_raw' => 0.49, 'security_nav' => 0.5]) !== 'highsec') {
    throw new RuntimeException('Expected MovementRules to classify using display security.');
}

$shipRules = new ShipRules();
if ($shipRules->isSystemAllowed(ShipRules::CARRIER, ['security_raw' => 0.45], true) !== false) {
    throw new RuntimeException('Expected ShipRules legality checks to block sec 0.45 for capitals with display rounding.');
}
if ($shipRules->isSystemAllowed(ShipRules::CARRIER, ['security_raw' => 0.44], true) !== true) {
    throw new RuntimeException('Expected ShipRules legality checks to allow sec 0.44 for capitals.');
}

echo "Security routing rounding test passed.\n";
