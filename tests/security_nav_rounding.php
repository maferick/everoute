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
    [0.449136, 0.4, 'low'],
    [0.44, 0.4, 'low'],
    [0.05, 0.1, 'low'],
    [0.04, 0.0, 'null'],
    [-0.01, 0.0, 'null'],
    [-0.06, -0.1, 'null'],
];

foreach ($cases as [$raw, $expectedDisplay, $expectedClass]) {
    $actual = SecurityStatus::navFromRaw((float) $raw);
    if (abs($actual - $expectedDisplay) > 0.0001) {
        throw new RuntimeException(sprintf('Expected %.6f to round to %.1f, got %.2f.', $raw, $expectedDisplay, $actual));
    }

    $band = SecurityStatus::secBandFromDisplay($actual);
    if ($band !== $expectedClass) {
        throw new RuntimeException(sprintf('Expected %.6f to classify as %s, got %s.', $raw, $expectedClass, $band));
    }

    $routing = SecurityNav::getSecurityForRouting(['security_true' => $raw]);
    if (abs($routing - $expectedDisplay) > 0.0001) {
        throw new RuntimeException(sprintf('Expected routing security %.6f to round to %.1f, got %.2f.', $raw, $expectedDisplay, $routing));
    }
}

$comparison = SecurityNav::debugComparison(['name' => 'Liparer', 'security_true' => 0.449136, 'security_display' => 0.4, 'sec_class' => 'low']);
if (abs((float) $comparison['sec_routing'] - 0.4) > 0.0001) {
    throw new RuntimeException('Expected Liparer sec_routing to be 0.4.');
}

if (SecurityNav::isIllegalHighsecForCapital(['security_true' => 0.449136, 'security_display' => 0.4, 'sec_class' => 'low']) !== false) {
    throw new RuntimeException('Expected capital highsec check to allow Liparer as lowsec.');
}

if (SecurityNav::isIllegalHighsecForCapital(['security_true' => 0.49, 'security_display' => 0.5, 'sec_class' => 'high']) !== true) {
    throw new RuntimeException('Expected capital highsec check to reject sec 0.49 displayed as 0.5.');
}

$movementRules = new MovementRules();
if ($movementRules->getSystemSpaceType(['security_true' => 0.449136, 'security_display' => 0.4, 'sec_class' => 'low']) !== 'lowsec') {
    throw new RuntimeException('Expected MovementRules to classify Liparer as lowsec.');
}

$shipRules = new ShipRules();
if ($shipRules->isSystemAllowed(ShipRules::CARRIER, ['security_true' => 0.449136, 'security_display' => 0.4, 'sec_class' => 'low'], true) !== true) {
    throw new RuntimeException('Expected ShipRules legality checks to allow sec 0.4 lowsec for capitals.');
}

if ($shipRules->isSystemAllowed(ShipRules::CARRIER, ['security_true' => 0.49, 'security_display' => 0.5, 'sec_class' => 'high'], true) !== false) {
    throw new RuntimeException('Expected ShipRules legality checks to block displayed highsec for capitals.');
}

echo "Security routing rounding test passed.\n";
