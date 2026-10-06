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
            if (str_starts_with($route['pattern'], '#') && preg_match($route['pattern'], $request->path(), $matches)) {
                return ($route['handler'])($request, $matches);
            }
        }
        return Response::html('页面不存在', 404);
    }
}
