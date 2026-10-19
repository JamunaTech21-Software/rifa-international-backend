<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\CsrfMismatchException;
use App\Core\Request;
use App\Core\Response;
use App\Shared\Security\CsrfTokenManager;

/** Alias: csrf. Every non-GET admin request must send the session token in X-CSRF-Token. */
final class Csrf implements Middleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly CsrfTokenManager $tokens)
    {
    }

    public function handle(Request $request, callable $next, string ...$params): Response
    {
        if (!in_array($request->method(), self::SAFE_METHODS, true)
            && !$this->tokens->verify($request->header('X-CSRF-Token'))) {
            throw new CsrfMismatchException();
        }

        return $next($request);
    }
}
