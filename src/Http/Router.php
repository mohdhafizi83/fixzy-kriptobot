<?php

namespace Fixzy\Kriptobot\Http;

/**
 * Router — minimal method+pattern router for the /api/v1 front controller.
 *
 * Patterns look like: GET /bots, POST /bots/{id}/activate
 * Handler callbacks receive (array $params, array $body) and return a value
 * that is wrapped in ApiResponse::ok(), or throw ApiException for errors.
 */
class Router
{
    /** @var array<int, array{method: string, regex: string, params: array<int, string>, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * Dispatch a request. Returns the handler result.
     *
     * @throws ApiException 404 when no route matches, 405 when path matches other methods.
     */
    public function dispatch(string $method, string $path, array $body): mixed
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $m[$i + 1];
            }
            return ($route['handler'])($params, $body);
        }

        if ($pathMatched) {
            throw new ApiException("Method {$method} not allowed for {$path}", 405);
        }
        throw new ApiException("No such endpoint: {$path}", 404);
    }
}
