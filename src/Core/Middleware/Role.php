<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Core\Request;
use App\Core\Response;

/** Alias: role:{role}[,{role}] — e.g. 'role:super_admin'. Must run after 'auth'. */
final class Role implements Middleware
{
    public function handle(Request $request, callable $next, string ...$params): Response
    {
        $admin = $request->attribute('admin');
        if (!is_array($admin)) {
            throw new UnauthenticatedException();
        }

        if (!in_array($admin['role'] ?? null, $params, true)) {
            throw new ForbiddenException();
        }

        return $next($request);
    }
}
