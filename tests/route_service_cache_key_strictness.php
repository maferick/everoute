<?php

declare(strict_types=1);

use Everoute\Routing\RouteService;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Routing/JumpFatigueModel.php';
    require_once __DIR__ . '/../src/Routing/RouteService.php';
}

$reflection = new ReflectionClass(RouteService::class);
/** @var RouteService $service */
$service = $reflection->newInstanceWithoutConstructor();
$routeCacheKey = $reflection->getMethod('routeCacheKey');
$routeCacheKey->setAccessible(true);

$baseOptions = [
    'from' => 'A',
    'to' => 'B',
    'mode' => 'capital',
    'ship_class' => 'capital',
    'jump_ship_type' => 'carrier',
    'jump_skill_level' => 5,
    'safety_vs_speed' => 50,
    'prefer_npc' => false,
];

$softByDefault = $baseOptions + [
    'avoid_lowsec' => false,
    'avoid_nullsec' => false,
];
$strictByDefault = $baseOptions + [
    'avoid_lowsec' => true,
    'avoid_nullsec' => false,
];
$explicitSoft = $strictByDefault + [
    'avoid_strictness' => 'soft',
];
$explicitStrict = $strictByDefault + [
    'avoid_strictness' => 'strict',
];

$keySoftDefault = $routeCacheKey->invoke($service, $softByDefault);
$keyStrictDefault = $routeCacheKey->invoke($service, $strictByDefault);
$keyExplicitSoft = $routeCacheKey->invoke($service, $explicitSoft);
$keyExplicitStrict = $routeCacheKey->invoke($service, $explicitStrict);

if ($keySoftDefault === $keyStrictDefault) {
    throw new RuntimeException('Cache key should differ when avoid toggles imply strict default strictness.');
}
if ($keyStrictDefault !== $keyExplicitStrict) {
    throw new RuntimeException('Implicit strict default should normalize to same key as explicit strict.');
}
if ($keyStrictDefault === $keyExplicitSoft) {
    throw new RuntimeException('Explicit soft strictness should not collide with strict-default key.');
}

echo "Route service cache key strictness test passed.\n";
