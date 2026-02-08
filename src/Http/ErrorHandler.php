<?php

declare(strict_types=1);

namespace Everoute\Http;

use Everoute\Security\Logger;
use Throwable;

final class ErrorHandler
{
    private const FATAL_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
    ];

    private ?Request $request = null;
    private array $requestContext = [];

    public function __construct(private Logger $logger, private bool $debug)
    {
    }

    public function register(): void
    {
        set_exception_handler(function (Throwable $e): void {
            $response = $this->handleThrowable($e);
            $response->send();
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], self::FATAL_TYPES, true)) {
                return;
            }

            $requestId = $this->request?->requestId ?? Request::fromGlobals(false)->requestId;
            $this->logger->error('Fatal error', [
                'request_id' => $requestId,
                'message' => $error['message'] ?? 'Fatal error',
                'file' => $error['file'] ?? null,
                'line' => $error['line'] ?? null,
            ] + $this->buildRequestContext($this->request));

            if (headers_sent()) {
                return;
            }

            $response = $this->buildErrorResponse($requestId);
            $response->send();
        });
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function setRequestContext(array $context): void
    {
        $this->requestContext = $context;
    }

    public function handleThrowable(Throwable $e, ?Request $request = null): Response
    {
        if ($request !== null) {
            $this->request = $request;
        }

        $requestId = $this->request?->requestId ?? Request::fromGlobals(false)->requestId;
        $context = [
            'request_id' => $requestId,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->limitString($e->getTraceAsString(), 4000),
        ];

        $context = array_merge($context, $this->buildRequestContext($this->request), $this->requestContext);
        $this->logger->error('Unhandled exception', $context);

        return $this->buildErrorResponse($requestId);
    }

    private function buildErrorResponse(string $requestId): Response
    {
        return new JsonResponse([
            'error' => 'internal_error',
            'request_id' => $requestId,
            'message' => 'Route planning failed',
        ], 500, [
            'X-Request-Id' => $requestId,
        ]);
    }

    private function buildRequestContext(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        $body = $request->body;
        $context = [
            'path' => $request->path,
            'method' => $request->method,
            'ip' => $request->ip,
            'from' => $this->limitString((string) ($body['from'] ?? $request->query['from'] ?? ''), 120),
            'to' => $this->limitString((string) ($body['to'] ?? $request->query['to'] ?? ''), 120),
            'mode' => $this->limitString((string) ($body['mode'] ?? ''), 40),
            'ship_class' => $this->limitString((string) ($body['ship_class'] ?? ''), 40),
            'jump_ship_type' => $this->limitString((string) ($body['jump_ship_type'] ?? ''), 40),
            'jump_skill_level' => $body['jump_skill_level'] ?? null,
            'safety_vs_speed' => $body['safety_vs_speed'] ?? null,
            'avoid_lowsec' => $body['avoid_lowsec'] ?? null,
            'avoid_nullsec' => $body['avoid_nullsec'] ?? null,
            'prefer_npc' => $body['prefer_npc'] ?? null,
        ];

        $avoidSystems = $body['avoid_specific_systems'] ?? $body['avoid_systems'] ?? null;
        if (is_string($avoidSystems)) {
            $avoidSystems = array_filter(array_map('trim', explode(',', $avoidSystems)));
        }
        if (is_array($avoidSystems)) {
            $context['avoid_specific_systems'] = array_slice($avoidSystems, 0, 10);
            if (count($avoidSystems) > 10) {
                $context['avoid_specific_systems_count'] = count($avoidSystems);
            }
        }

        return $context;
    }

    private function limitString(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '...';
    }
}
