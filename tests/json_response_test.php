<?php

declare(strict_types=1);

use Everoute\Http\JsonResponse;

spl_autoload_register(function (string $class): void {
    $prefix = 'Everoute\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$invalidUtf8 = "\xB1\x31";
$response = new JsonResponse(['bad' => $invalidUtf8]);
$decoded = json_decode($response->body, true);

assertTrue(is_array($decoded), 'Expected JSON to decode after invalid UTF-8 substitution.');
assertTrue(array_key_exists('bad', $decoded), 'Expected bad key to be present in decoded payload.');
assertTrue(($decoded['bad'] ?? null) !== null, 'Expected substituted string value instead of null.');

$headers = $response->headers;
assertTrue(($headers['Content-Type'] ?? '') === 'application/json; charset=utf-8', 'Expected JSON content-type header.');


$specialFloats = [
    'inf' => INF,
    'neg_inf' => -INF,
    'nan' => NAN,
    'ok' => 1.5,
    'nested' => [
        'inf' => INF,
    ],
];
$specialResponse = new JsonResponse($specialFloats);
$specialDecoded = json_decode($specialResponse->body, true);

assertTrue(is_array($specialDecoded), 'Expected JSON to decode for special float payload.');
assertTrue(array_key_exists('inf', $specialDecoded) && $specialDecoded['inf'] === null, 'Expected INF to be normalized to null.');
assertTrue(array_key_exists('neg_inf', $specialDecoded) && $specialDecoded['neg_inf'] === null, 'Expected -INF to be normalized to null.');
assertTrue(array_key_exists('nan', $specialDecoded) && $specialDecoded['nan'] === null, 'Expected NAN to be normalized to null.');
assertTrue(($specialDecoded['ok'] ?? null) === 1.5, 'Expected finite float to remain unchanged.');
assertTrue(isset($specialDecoded['nested']) && is_array($specialDecoded['nested']) && array_key_exists('inf', $specialDecoded['nested']) && $specialDecoded['nested']['inf'] === null, 'Expected nested INF to be normalized to null.');

echo "ok\n";
