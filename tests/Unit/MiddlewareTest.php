<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\CsrfMismatchException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Core\Middleware\Csrf;
use App\Core\Middleware\Role;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Shared\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    private function ok(): callable
    {
        return fn () => Response::data('ok');
    }

    public function test_csrf_allows_safe_methods_without_token(): void
    {
        $csrf = new Csrf(new CsrfTokenManager(new Session([], false)));

        $this->assertSame(200, $csrf->handle(new Request('GET', '/x'), $this->ok())->status());
    }

    public function test_csrf_rejects_write_with_missing_or_wrong_token(): void
    {
        $tokens = new CsrfTokenManager(new Session([], false));
        $tokens->token();
        $csrf = new Csrf($tokens);

        $this->expectException(CsrfMismatchException::class);
        $csrf->handle(new Request('PATCH', '/x', [], [], [], ['X-CSRF-Token' => 'wrong']), $this->ok());
    }

    public function test_csrf_accepts_matching_token(): void
    {
        $tokens = new CsrfTokenManager(new Session([], false));
        $csrf = new Csrf($tokens);

        $response = $csrf->handle(
            new Request('POST', '/x', [], [], [], ['x-csrf-token' => $tokens->token()]),
            $this->ok(),
        );

        $this->assertSame(200, $response->status());
    }

    public function test_role_requires_matching_role(): void
    {
        $request = new Request('GET', '/x');
        $request->setAttribute('admin', ['id' => 1, 'role' => 'staff']);

        $this->expectException(ForbiddenException::class);
        (new Role())->handle($request, $this->ok(), 'super_admin');
    }

    public function test_role_passes_for_allowed_role(): void
    {
        $request = new Request('GET', '/x');
        $request->setAttribute('admin', ['id' => 1, 'role' => 'staff']);

        $this->assertSame(200, (new Role())->handle($request, $this->ok(), 'super_admin', 'staff')->status());
    }

    public function test_role_without_admin_is_unauthenticated(): void
    {
        $this->expectException(UnauthenticatedException::class);
        (new Role())->handle(new Request('GET', '/x'), $this->ok(), 'staff');
    }
}
