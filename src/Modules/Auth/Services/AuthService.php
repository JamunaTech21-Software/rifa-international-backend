<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\RateLimitedException;
use App\Core\Exceptions\ValidationException;
use App\Core\Session;
use App\Core\Validator;
use App\Modules\Auth\Repositories\AdminRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Clock;
use App\Shared\Security\CsrfTokenManager;
use App\Shared\Security\Password;
use App\Shared\Security\RateLimiter;
use DateTimeImmutable;

/**
 * Admin login/logout/me/change-password (plan.md §4.4, story BE-11).
 *
 * - Generic "Invalid email or password." for unknown email, wrong password and inactive account.
 * - 5 wrong passwords lock the account for 15 minutes (429 + Retry-After).
 * - At most 20 failed logins per IP per 15 minutes, across all accounts.
 */
final class AuthService
{
    private const IP_MAX_FAILURES = 20;

    private const IP_WINDOW_SECONDS = 900;

    // Verified against when the email is unknown, so response time does not reveal which emails exist.
    private const DUMMY_HASH = '$2y$12$Gmo0CPSmlcc8vHkgNYZJ2O1eAsz53HN.YeKn6RMI1uWR9rKxgqqFq';

    public function __construct(
        private readonly AdminRepository $admins,
        private readonly Session $session,
        private readonly CsrfTokenManager $csrf,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{admin: array{id: int, name: string, email: string, role: string}, csrf_token: string}
     */
    public function login(array $input, string $ip): array
    {
        $data = Validator::make($input, [
            'email' => 'required|email|max:150',
            'password' => 'required|string|max:200',
        ], [
            'email.required' => 'Please enter your email address.',
            'email' => 'Enter a valid email address.',
            'password.required' => 'Please enter your password.',
        ])->validate();

        $ipKey = 'login-failed|' . $ip;
        if ($this->limiter->attempts($ipKey, self::IP_WINDOW_SECONDS) >= self::IP_MAX_FAILURES) {
            throw new RateLimitedException($this->limiter->availableIn($ipKey, self::IP_WINDOW_SECONDS));
        }

        $now = $this->clock->now();
        $admin = $this->admins->findByEmail(strtolower($data['email']));

        if ($admin !== null && $admin['locked_until'] !== null) {
            $lockedFor = (new DateTimeImmutable($admin['locked_until'], $this->clock->timezone()))->getTimestamp() - $now->getTimestamp();
            if ($lockedFor > 0) {
                throw new RateLimitedException(
                    $lockedFor,
                    'Too many failed attempts. Please try again in ' . (int) ceil($lockedFor / 60) . ' minutes.',
                );
            }
        }

        $passwordOk = Password::verify($data['password'], $admin['password_hash'] ?? self::DUMMY_HASH);

        if ($admin === null || !$passwordOk || (int) $admin['is_active'] !== 1) {
            $this->limiter->hit($ipKey, self::IP_WINDOW_SECONDS);
            if ($admin !== null && !$passwordOk) {
                $maxAttempts = (int) $this->config->get('security.login.max_attempts', 5);
                $lockMinutes = (int) $this->config->get('security.login.lockout_minutes', 15);
                $this->admins->recordFailedLogin(
                    $admin['id'],
                    $maxAttempts,
                    $now->modify("+{$lockMinutes} minutes")->format('Y-m-d H:i:s'),
                );
            }
            $this->audit->log($admin['id'] ?? null, 'auth.login_failed', 'admin', $admin['id'] ?? null, $ip);

            throw new HttpException(401, 'invalid_credentials', 'Invalid email or password.');
        }

        if (Password::needsRehash($admin['password_hash'])) {
            $this->admins->rehash($admin['id'], Password::hash($data['password']));
        }
        $this->admins->recordSuccessfulLogin($admin['id'], $now->format('Y-m-d H:i:s'));

        $this->session->regenerate();
        $this->session->set('admin_id', $admin['id']);
        $this->session->set('session_version', $admin['session_version']);
        $this->session->set('last_activity', $now->getTimestamp());
        $token = $this->csrf->rotate();

        $this->audit->log($admin['id'], 'auth.login', 'admin', $admin['id'], $ip);

        return ['admin' => $this->publicAdmin($admin), 'csrf_token' => $token];
    }

    /**
     * @param array{id: int, name: string, email: string, role: string} $admin
     * @return array{admin: array{id: int, name: string, email: string, role: string}, csrf_token: string}
     */
    public function me(array $admin): array
    {
        return ['admin' => $this->publicAdmin($admin), 'csrf_token' => $this->csrf->token()];
    }

    /** @param array{id: int} $admin */
    public function logout(array $admin, string $ip): void
    {
        $this->audit->log($admin['id'], 'auth.logout', 'admin', $admin['id'], $ip);
        $this->session->destroy();
    }

    /**
     * @param array{id: int} $admin
     * @param array<string, mixed> $input
     */
    public function changePassword(array $admin, array $input, string $ip): void
    {
        $min = (int) $this->config->get('security.password_min_length', 10);
        $data = Validator::make($input, [
            'current_password' => 'required|string',
            'new_password' => "required|string|min:{$min}|max:200",
        ], [
            'current_password.required' => 'Please enter your current password.',
            'new_password.required' => 'Please enter a new password.',
            'new_password' => "Password must be {$min}–200 characters.",
        ])->validate();

        $row = $this->admins->findById($admin['id']);
        if ($row === null || !Password::verify($data['current_password'], $row['password_hash'])) {
            throw new ValidationException(['current_password' => 'Current password is incorrect.']);
        }
        if ($data['new_password'] === $data['current_password']) {
            throw new ValidationException(['new_password' => 'New password must be different from the current password.']);
        }

        $version = $this->admins->updatePassword($admin['id'], Password::hash($data['new_password']));
        // Keep this session; every other session of this admin is now invalid.
        $this->session->regenerate();
        $this->session->set('session_version', $version);

        $this->audit->log($admin['id'], 'auth.password_changed', 'admin', $admin['id'], $ip);
    }

    /**
     * @param array{id: int, name: string, email: string, role: string} $admin
     * @return array{id: int, name: string, email: string, role: string}
     */
    private function publicAdmin(array $admin): array
    {
        return [
            'id' => (int) $admin['id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
            'role' => $admin['role'],
        ];
    }
}
