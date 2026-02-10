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

putenv('EVEREOUTE_ROUTING_SECURITY_SOURCE');

$cases = [
    [0.45, 0.4],
    [0.49, 0.4],
    [0.50, 0.5],
    [0.04, 0.0],
];

foreach ($cases as [$raw, $expected]) {
    $actual = SecurityStatus::navFromRaw((float) $raw);
    if (abs($actual - $expected) > 0.0001) {
        throw new RuntimeException(sprintf('Expected %.2f to floor to %.1f, got %.2f.', $raw, $expected, $actual));
    }

    $routing = SecurityNav::getSecurityForRouting(['security_raw' => $raw]);
    if (abs($routing - $expected) > 0.0001) {
        throw new RuntimeException(sprintf('Expected routing security %.2f to floor to %.1f, got %.2f.', $raw, $expected, $routing));
    }
}

$fromRaw = ['security' => 0.6, 'security_raw' => 0.49];
if (abs(SecurityNav::value($fromRaw) - 0.4) > 0.0001) {
    throw new RuntimeException('Expected fallback to floored security_raw when security_nav is absent.');
}

$fromSecurity = ['security' => 0.49];
if (abs(SecurityNav::value($fromSecurity) - 0.4) > 0.0001) {
    throw new RuntimeException('Expected fallback to floored security when security_nav/security_raw are absent.');
}

$comparison = SecurityNav::debugComparison(['name' => 'Liparer', 'security_raw' => 0.45, 'security_nav' => 0.4]);
if (abs((float) $comparison['sec_routing'] - 0.4) > 0.0001) {
    throw new RuntimeException('Expected Liparer sec_routing to be 0.4.');
}

if (SecurityNav::isIllegalHighsecForCapital(['security_raw' => 0.45]) !== false) {
    throw new RuntimeException('Expected capital highsec check to allow sec 0.45.');
}

if (SecurityNav::isIllegalHighsecForCapital(['security_raw' => 0.50]) !== true) {
    throw new RuntimeException('Expected capital highsec check to reject sec 0.50.');
}

$movementRules = new MovementRules();
if ($movementRules->getSystemSpaceType(['security' => 0.6, 'security_raw' => 0.49, 'security_nav' => 0.5]) !== 'lowsec') {
    throw new RuntimeException('Expected MovementRules to classify using floored security_raw by default.');
}

$shipRules = new ShipRules();
if ($shipRules->isSystemAllowed(ShipRules::CARRIER, ['security_raw' => 0.45], true) !== true) {
    throw new RuntimeException('Expected ShipRules legality checks to allow sec 0.45 for capitals.');
}
if ($shipRules->isSystemAllowed(ShipRules::CARRIER, ['security_raw' => 0.50], true) !== false) {
    throw new RuntimeException('Expected ShipRules legality checks to block sec 0.50 for capitals.');
}

putenv('EVEREOUTE_ROUTING_SECURITY_SOURCE=nav');
$preferSecurityNav = ['security' => 0.6, 'security_raw' => 0.49, 'security_nav' => 0.5];
if (abs(SecurityNav::value($preferSecurityNav) - 0.5) > 0.0001) {
    throw new RuntimeException('Expected optional nav strategy override to use security_nav.');
}
putenv('EVEREOUTE_ROUTING_SECURITY_SOURCE');

echo "Security routing floor test passed.\n";
