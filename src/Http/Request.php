<?php

declare(strict_types=1);

namespace Everoute\Http;

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;
    public array $headers;
    public string $ip;
    public string $requestId;
    public array $params;

    public function __construct(string $method, string $path, array $query, array $body, array $headers, string $ip, ?string $requestId = null, array $params = [])
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
        $this->ip = $ip;
        $this->requestId = $requestId ?? ($headers['x-request-id'] ?? self::generateRequestId());
        $this->params = $params;
    }

    public static function fromGlobals(bool $trustProxy): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = $_GET ?? [];
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$header] = $value;
            }
        }

        $raw = file_get_contents('php://input');
        $body = [];
        if (!empty($raw)) {
            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $body = $json;
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($trustProxy && isset($headers['x-forwarded-for'])) {
            $parts = explode(',', $headers['x-forwarded-for']);
            $ip = trim($parts[0]);
        }

        $requestId = $headers['x-request-id'] ?? self::generateRequestId();

        return new self($method, $path, $query, $body, $headers, $ip, $requestId);
    }

    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $this->headers, $this->ip, $this->requestId, $params);
    }

    private static function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
