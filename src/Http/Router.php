<?php

declare(strict_types=1);

namespace Everoute\Http;

final class Router
{
    /** @var array<string, array<int, array{path:string,handler:callable,regex:string,param_names:array<int,string>}>> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $matches) use (&$paramNames): string {
            $paramNames[] = $matches[1];
            return '([^/]+)';
        }, $path) ?? $path;

        $this->routes[$method][] = [
            'path' => $path,
            'handler' => $handler,
            'regex' => '#^' . $regex . '$#',
            'param_names' => $paramNames,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes[$request->method] ?? [] as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['param_names'] as $index => $name) {
                $params[$name] = urldecode($matches[$index] ?? '');
            }

            return ($route['handler'])($request->withParams($params));
        }

        return new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
    }
}
