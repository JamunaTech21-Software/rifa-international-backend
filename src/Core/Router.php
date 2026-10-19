<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\MethodNotAllowedException;
use App\Core\Exceptions\NotFoundException;

/**
 * Path router with {param} placeholders and prefix/middleware groups.
 *
 *   $router->get('/admin/applications/{id:\d+}', [Controller::class, 'show'], ['auth']);
 *   $router->group('/admin', ['auth', 'csrf'], function (Router $r) { ... });
 *
 * Handlers are [ClassName::class, 'method'] (resolved from the container) or closures.
 * Middleware entries are alias strings with optional params: 'throttle:applications,5,3600'.
 */
final class Router
{
    /** @var list<array{method: string, path: string, regex: string, params: list<string>, handler: mixed, middleware: list<string>}> */
    private array $routes = [];

    /** @var list<array{prefix: string, middleware: list<string>}> */
    private array $groups = [];

    /** @param list<string> $middleware */
    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param list<string> $middleware */
    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param list<string> $middleware */
    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param list<string> $middleware */
    public function patch(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /** @param list<string> $middleware */
    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * @param list<string>           $middleware
     * @param callable(Router): void $routes
     */
    public function group(string $prefix, array $middleware, callable $routes): void
    {
        $this->groups[] = ['prefix' => $prefix, 'middleware' => $middleware];
        try {
            $routes($this);
        } finally {
            array_pop($this->groups);
        }
    }

    /** @param list<string> $middleware */
    public function add(string $method, string $path, mixed $handler, array $middleware = []): void
    {
        $prefix = '';
        $groupMiddleware = [];
        foreach ($this->groups as $group) {
            $prefix .= '/' . trim($group['prefix'], '/');
            $groupMiddleware = [...$groupMiddleware, ...$group['middleware']];
        }

        $fullPath = $this->normalise($prefix . '/' . trim($path, '/'));
        [$regex, $params] = $this->compile($fullPath);

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middleware' => [...$groupMiddleware, ...$middleware],
        ];
    }

    /**
     * @return array{0: array{method: string, path: string, regex: string, params: list<string>, handler: mixed, middleware: list<string>}, 1: array<string, string>}
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $path = $this->normalise($path);
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name];
            }

            return [$route, $params];
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedException(array_values(array_unique($allowed)));
        }

        throw new NotFoundException('The requested endpoint does not exist.');
    }

    /** @return list<array{method: string, path: string}> */
    public function routes(): array
    {
        return array_map(fn (array $r) => ['method' => $r['method'], 'path' => $r['path']], $this->routes);
    }

    /** @return array{0: string, 1: list<string>} */
    private function compile(string $path): array
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            function (array $m) use (&$params): string {
                $params[] = $m[1];
                $pattern = $m[2] ?? '[^/]+';

                return "(?P<{$m[1]}>{$pattern})";
            },
            $path,
        );

        return ['#^' . $regex . '$#', $params];
    }

    private function normalise(string $path): string
    {
        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');

        return $path === '/' ? '/' : $path;
    }
}
