<?php

declare(strict_types=1);

function assertContains(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Missing: {$needle}");
    }
}

$result = [
    'best' => 'gate',
    'fallback_warning' => true,
    'fallback_message' => 'Strict avoid filters produced no feasible route; returned best effort route using soft avoid penalties.',
    'risk_updated_at' => 'now',
    'gate_route' => [
        'feasible' => true,
        'fallback_used' => true,
        'requested_avoid_strictness' => 'strict',
        'applied_avoid_strictness' => 'soft',
        'systems' => [
            ['name' => 'Jita', 'security' => 0.9, 'security_raw' => 0.9, 'has_npc_station' => true],
            ['name' => 'Perimeter', 'security' => 0.9, 'security_raw' => 0.9, 'has_npc_station' => true],
        ],
        'segments' => [
            ['from' => 'Jita', 'to' => 'Perimeter', 'type' => 'gate'],
        ],
    ],
    'jump_route' => null,
    'hybrid_route' => null,
    'explanation' => ['Best effort route selected after strict fallback.'],
];

$token = 'test-token';
$_POST = [
    'from' => 'Jita',
    'to' => 'Perimeter',
    'mode' => 'subcap',
    'ship_class' => 'subcap',
    'jump_skill_level' => '5',
    'avoid_lowsec' => '1',
    'avoid_nullsec' => '1',
    'avoid_strictness' => 'strict',
];

ob_start();
include __DIR__ . '/../public/templates/home.php';
$html = (string) ob_get_clean();

assertContains($html, 'Fallback warning:', 'Top-level fallback warning should be rendered.');
assertContains($html, 'Fallback details:', 'Best option fallback details should be rendered.');
assertContains($html, 'Requested strictness:', 'Route card should render requested strictness metadata.');
assertContains($html, 'Applied strictness:', 'Route card should render applied strictness metadata.');
assertContains($html, 'Fallback used:', 'Route card should render fallback usage metadata.');
assertContains($html, 'Strict avoid produced no feasible route; showing best effort.', 'Route card should render inline fallback explanation.');

echo "home template fallback metadata test passed.\n";
