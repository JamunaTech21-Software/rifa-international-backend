<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Middleware\Middleware;
use Closure;
use RuntimeException;
use Throwable;

/**
 * HTTP kernel: match route → run middleware pipeline → call controller →
 * convert any exception to a JSON error.
 */
final class App
{
    /**
     * @param array<string, class-string<Middleware>> $middlewareAliases e.g. ['auth' => Auth::class]
     */
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly ErrorHandler $errors,
        private readonly array $middlewareAliases = [],
    ) {
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        $this->handle(Request::capture())->send();
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(8));
        $request->setAttribute('request_id', $requestId);

        try {
            [$route, $params] = $this->router->match($request->method(), $request->path());
            $request->setAttribute('params', $params);

            $response = $this->pipeline($route['middleware'], fn (Request $r) => $this->callHandler($route['handler'], $r))($request);
        } catch (Throwable $e) {
            $response = $this->errors->render($e, $request);
        }

        $response->withHeader('X-Request-Id', $requestId);
        $response->withHeader('X-Content-Type-Options', 'nosniff');
        if ($response->header('Cache-Control') === null) {
            $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }

    /**
     * @param list<string>               $middleware
     * @param Closure(Request): Response $core
     * @return Closure(Request): Response
     */
    private function pipeline(array $middleware, Closure $core): Closure
    {
        $next = $core;
        foreach (array_reverse($middleware) as $spec) {
            [$alias, $paramString] = array_pad(explode(':', $spec, 2), 2, '');
            $class = $this->middlewareAliases[$alias] ?? $alias;
            $params = $paramString === '' ? [] : explode(',', $paramString);

            $instance = $this->container->get($class);
            if (!$instance instanceof Middleware) {
                throw new RuntimeException("Middleware [{$spec}] must implement " . Middleware::class);
            }

            $next = fn (Request $request) => $instance->handle($request, $next, ...$params);
        }

        return $next;
    }

    private function callHandler(mixed $handler, Request $request): Response
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            $handler = [$this->container->get($handler[0]), $handler[1]];
        }

        if (!is_callable($handler)) {
            throw new RuntimeException('Route handler is not callable.');
        }

        $response = $handler($request);
        if (!$response instanceof Response) {
            throw new RuntimeException('Controllers must return an App\\Core\\Response.');
        }

        return $response;
    }
}
