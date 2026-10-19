<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\RateLimitedException;
use App\Core\Request;
use App\Core\Response;
use App\Shared\Security\RateLimiter;

/**
 * Alias: throttle:{name},{max},{windowSeconds} — e.g. 'throttle:applications,5,3600'.
 *
 * Limits successful (2xx) requests per IP: a visitor who gets validation
 * errors can correct the form without being locked out. Requests are blocked
 * once {max} successful requests happened inside the window.
 */
final class RateLimit implements Middleware
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, callable $next, string ...$params): Response
    {
        [$name, $max, $window] = $params + ['default', '60', '60'];
        $max = (int) $max;
        $window = (int) $window;
        $key = $name . '|' . $request->ip();

        if ($this->limiter->attempts($key, $window) >= $max) {
            throw new RateLimitedException($this->limiter->availableIn($key, $window));
        }

        $response = $next($request);

        if ($response->status() >= 200 && $response->status() < 300) {
            $this->limiter->hit($key, $window);
        }

        return $response;
    }
}
