<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Core\Session;

/**
 * Per-session CSRF token. The admin UI receives it from /admin/auth/login or
 * /admin/auth/me and sends it back in the X-CSRF-Token header on every write.
 */
final class CsrfTokenManager
{
    private const KEY = 'csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = $this->rotate();
        }

        return $token;
    }

    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::KEY, $token);

        return $token;
    }

    public function verify(?string $token): bool
    {
        $expected = $this->session->get(self::KEY);

        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }
}
