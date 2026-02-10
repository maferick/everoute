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

echo "ok\n";
