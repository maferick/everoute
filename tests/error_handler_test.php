<?php

declare(strict_types=1);

use Everoute\Http\ErrorHandler;
use Everoute\Http\Request;
use Everoute\Security\Logger;

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

$logLines = [];
$logger = new Logger(function (string $line) use (&$logLines): void {
    $logLines[] = $line;
});
$requestId = 'test-request-id';
$logger->setContext(['request_id' => $requestId]);
$handler = new ErrorHandler($logger, true);
$request = new Request('POST', '/api/v1/route', [], [
    'from' => 'Jita',
    'to' => 'Amarr',
    'mode' => 'subcap',
], [], '127.0.0.1', $requestId);
$handler->setRequest($request);

$exception = new RuntimeException('Boom');
$response = $handler->handleThrowable($exception);

$logOutput = trim(implode("\n", $logLines));

$payload = json_decode($response->body, true);
assertTrue(is_array($payload), 'Expected JSON response payload.');
assertTrue(($payload['error'] ?? null) === 'internal_error', 'Expected internal_error response.');
assertTrue(($payload['request_id'] ?? null) === $requestId, 'Expected request_id in response.');

$logLines = array_filter(explode("\n", $logOutput));
$lastLog = json_decode((string) end($logLines), true);
assertTrue(is_array($lastLog), 'Expected JSON log output.');
assertTrue(($lastLog['context']['request_id'] ?? null) === $requestId, 'Expected request_id in log context.');
assertTrue(isset($lastLog['context']['trace']), 'Expected stack trace in log context.');
assertTrue(str_contains((string) $lastLog['context']['trace'], '#0'), 'Expected stack trace to be present.');

echo "ok\n";
