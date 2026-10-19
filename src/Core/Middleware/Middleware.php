<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Route middleware. $params come from the alias string, e.g.
 * 'throttle:applications,5,3600' → handle($request, $next, 'applications', '5', '3600').
 */
interface Middleware
{
    /** @param callable(Request): Response $next */
    public function handle(Request $request, callable $next, string ...$params): Response;
}
