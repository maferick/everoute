<?php

declare(strict_types=1);

namespace Everoute\Http;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method][$request->path] ?? null;
        if ($handler === null) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        }

        return $handler($request);
    }
}
