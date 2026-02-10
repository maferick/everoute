<?php

declare(strict_types=1);

use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\NavigationEngine;
use Everoute\Routing\PreferenceProfile;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Routing/SecurityNav.php';
    require_once __DIR__ . '/../src/Routing/PreferenceProfile.php';
    require_once __DIR__ . '/../src/Routing/JumpShipType.php';
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/NavigationEngine.php';
}

function assertTrueStrict(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(NavigationEngine::class);
/** @var NavigationEngine $engine */
$engine = $reflection->newInstanceWithoutConstructor();

$fatigueProp = $reflection->getProperty('fatigueModel');
$fatigueProp->setAccessible(true);
$fatigueProp->setValue($engine, new JumpFatigueModel());

$gateEdgeCost = $reflection->getMethod('gateEdgeCost');
$gateEdgeCost->setAccessible(true);
$jumpEdgeCost = $reflection->getMethod('jumpEdgeCost');
$jumpEdgeCost->setAccessible(true);

$profileRisky = [
    'risk_penalty' => 10.0,
    'security' => 0.0,
    'security_penalty' => 100.0,
    'security_class' => 'null',
    'has_npc_station' => false,
    'npc_station_count' => 0,
];
$profileSafe = [
    'risk_penalty' => 1.0,
    'security' => 0.9,
    'security_penalty' => 10.0,
    'security_class' => 'high',
    'has_npc_station' => false,
    'npc_station_count' => 0,
];

$speedOptions = ['preference_profile' => PreferenceProfile::SPEED, 'safety_vs_speed' => 20];
$balancedOptions = ['preference_profile' => PreferenceProfile::BALANCED, 'safety_vs_speed' => 50];
$safetyOptions = ['preference_profile' => PreferenceProfile::SAFETY, 'safety_vs_speed' => 80];

// Synthetic graph ordering: shortcut is risky but short; detour is safe but long.
$riskyShortcutSpeed = 2 * $gateEdgeCost->invoke($engine, $profileRisky, 'shorter', $speedOptions, true);
$safeDetourSpeed = 5 * $gateEdgeCost->invoke($engine, $profileSafe, 'shorter', $speedOptions, true);
assertTrueStrict($riskyShortcutSpeed < $safeDetourSpeed, 'Speed profile should favor risky shortcut in synthetic gate graph.');

$riskyShortcutSafety = 2 * $gateEdgeCost->invoke($engine, $profileRisky, 'shorter', $safetyOptions, true);
$safeDetourSafety = 5 * $gateEdgeCost->invoke($engine, $profileSafe, 'shorter', $safetyOptions, true);
assertTrueStrict($safeDetourSafety < $riskyShortcutSafety, 'Safety profile should favor safer detour in synthetic gate graph.');

// Coefficients must create mode ordering differences on the same edge.
$gateRiskSpeed = $gateEdgeCost->invoke($engine, $profileRisky, 'shorter', $speedOptions, true);
$gateRiskBalanced = $gateEdgeCost->invoke($engine, $profileRisky, 'shorter', $balancedOptions, true);
$gateRiskSafety = $gateEdgeCost->invoke($engine, $profileRisky, 'shorter', $safetyOptions, true);
assertTrueStrict($gateRiskSpeed < $gateRiskBalanced && $gateRiskBalanced < $gateRiskSafety, 'Risky gate edge should get increasingly expensive from speed->balanced->safety.');

// Per-jump constant should prefer fewer jumps when distances are close.
$balancedJumpOptions = [
    'preference_profile' => PreferenceProfile::BALANCED,
    'safety_vs_speed' => 50,
    'fuel_per_ly_factor' => 0.0,
    'jump_fuel_weight' => 1.0,
    'prefer_npc' => false,
    'avoid_lowsec' => false,
    'avoid_nullsec' => false,
    'avoid_systems' => [],
];
$nearNeutralProfile = [
    'risk_penalty' => 1.0,
    'security' => 0.4,
    'security_penalty' => 60.0,
    'security_class' => 'low',
    'has_npc_station' => false,
    'npc_station_count' => 0,
];

$directOneJump = $jumpEdgeCost->invoke($engine, 6.15, 'carrier', $nearNeutralProfile, $balancedJumpOptions);
$twoJumpChain = $jumpEdgeCost->invoke($engine, 3.00, 'carrier', $nearNeutralProfile, $balancedJumpOptions)
    + $jumpEdgeCost->invoke($engine, 3.00, 'carrier', $nearNeutralProfile, $balancedJumpOptions);
assertTrueStrict($directOneJump < $twoJumpChain, 'Per-jump constant should make one-jump path cheaper when LY totals are close.');

echo "preference_profile_modes passed\n";
