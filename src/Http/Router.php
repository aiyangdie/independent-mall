<?php

declare(strict_types=1);

namespace IndependentMall\Http;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }
            if ($route['pattern'] === $request->path()) {
                return ($route['handler'])($request);
            }
        }
        return Response::html('Not found', 404);
    }
}
