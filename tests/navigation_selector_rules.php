<?php

declare(strict_types=1);

use Everoute\Routing\NavigationEngine;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Routing/NavigationEngine.php';
}

$reflection = new ReflectionClass(NavigationEngine::class);
/** @var NavigationEngine $engine */
$engine = $reflection->newInstanceWithoutConstructor();
$select = $reflection->getMethod('selectBestWithMetadata');
$select->setAccessible(true);

$gate = [
    'feasible' => true,
    'time_cost' => 0.42,
    'total_gates' => 8,
    'total_cost' => 0.42,
];
$jump = [
    'feasible' => true,
    'time_cost' => 0.20,
    'total_gates' => 0,
    'total_cost' => 0.20,
];
$hybrid = [
    'feasible' => true,
    'time_cost' => 0.25,
    'total_gates' => 3,
    'total_cost' => 0.25,
];

$resultDominance = $select->invoke($engine, $gate, $jump, $hybrid, ['safety_vs_speed' => 25]);
if (($resultDominance['best'] ?? null) !== 'jump') {
    throw new RuntimeException('Expected jump to be selected by dominance rule.');
}
if (empty($resultDominance['dominance_rule_applied'])) {
    throw new RuntimeException('Expected dominance_rule_applied=true when dominance rule matches.');
}
if (!in_array(($resultDominance['reason'] ?? ''), ['jump_dominates_hybrid_time_threshold', 'jump_dominates_by_lexicographic_hops'], true)) {
    throw new RuntimeException('Unexpected dominance rule reason.');
}

$gatePenalty = [
    'feasible' => true,
    'time_cost' => 0.24,
    'total_gates' => 7,
    'total_cost' => 0.24,
];
$jumpPenalty = [
    'feasible' => true,
    'time_cost' => 0.22,
    'total_gates' => 0,
    'total_cost' => 0.22,
];
$hybridPenalty = [
    'feasible' => true,
    'time_cost' => 0.23,
    'total_gates' => 2,
    'total_cost' => 0.23,
];

$resultPenalty = $select->invoke($engine, $gatePenalty, $jumpPenalty, $hybridPenalty, ['safety_vs_speed' => 20, 'dominance_rule_enabled' => false]);
$extraGatePenalty = $resultPenalty['extra_gate_penalty'] ?? [];
if (empty($extraGatePenalty['applied'])) {
    throw new RuntimeException('Expected extra_gate_penalty to be applied for materially extra gates at similar time.');
}
if (empty($extraGatePenalty['penalty_routes']['gate'])) {
    throw new RuntimeException('Expected gate route to receive extra gate penalty details.');
}
if (($resultPenalty['reason'] ?? '') !== 'normalized_total_cost_with_extra_gate_penalty') {
    throw new RuntimeException('Expected reason to reflect extra gate penalty selection.');
}



$jumpSeven = [
    'feasible' => true,
    'jump_hops' => 7,
    'total_gates' => 0,
    'total_jump_ly' => 42.0,
    'max_jump_hop_ly' => 6.8,
    'time_cost' => 1.0,
    'total_cost' => 1.0,
];
$jumpEight = [
    'feasible' => true,
    'jump_hops' => 8,
    'total_gates' => 0,
    'total_jump_ly' => 41.0,
    'max_jump_hop_ly' => 6.2,
    'time_cost' => 0.95,
    'total_cost' => 0.95,
];
$resultLexicographic = $select->invoke($engine, $gate, $jumpSeven, $jumpEight, [
    'safety_vs_speed' => 50,
    'preference_profile' => 'speed',
    'dominance_rule_enabled' => true,
]);
if (($resultLexicographic['best'] ?? null) !== 'jump') {
    throw new RuntimeException('Expected 7-hop jump route to dominate 8-hop alternative in speed profile.');
}
if (($resultLexicographic['reason'] ?? '') !== 'jump_dominates_by_lexicographic_hops') {
    throw new RuntimeException('Expected lexicographic dominance reason for jump selection.');
}


echo "Navigation selector rules passed.\n";
