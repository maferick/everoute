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


$preferSecurityNav = ['security' => 0.6, 'security_raw' => 0.49, 'security_nav' => 0.4];
if (abs(SecurityNav::value($preferSecurityNav) - 0.4) > 0.0001) {
    throw new RuntimeException('Expected security_nav to take precedence when present.');
}

$fromRaw = ['security' => 0.6, 'security_raw' => 0.49];
if (abs(SecurityNav::value($fromRaw) - 0.5) > 0.0001) {
    throw new RuntimeException('Expected fallback to rounded security_raw when security_nav is absent.');
}

$fromSecurity = ['security' => 0.49];
if (abs(SecurityNav::value($fromSecurity) - 0.5) > 0.0001) {
    throw new RuntimeException('Expected fallback to rounded security when security_nav/security_raw are absent.');
}

$movementRules = new MovementRules();
if ($movementRules->getSystemSpaceType(['security' => 0.6, 'security_raw' => 0.49, 'security_nav' => 0.4]) !== 'lowsec') {
    throw new RuntimeException('Expected MovementRules to classify using security_nav first.');
}

$shipRules = new ShipRules();
if ($shipRules->isSystemAllowed(ShipRules::CARRIER, ['security' => 0.6, 'security_raw' => 0.49, 'security_nav' => 0.4], true) !== true) {
    throw new RuntimeException('Expected ShipRules legality checks to use security_nav first.');
}

echo "Security nav rounding test passed.\n";
