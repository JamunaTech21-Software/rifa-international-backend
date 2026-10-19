<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config;
use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use App\Modules\Auth\Repositories\AdminRepository;
use App\Modules\Auth\Repositories\PasswordResetRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Clock;
use App\Shared\Mail\Mailer;
use App\Shared\Security\Password;

/**
 * Forgot/reset password by email link (story BE-11).
 * The link token is 32 random bytes; only its SHA-256 hash is stored. Valid 30 minutes, single use.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly PasswordResetRepository $resets,
        private readonly Mailer $mailer,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    /**
     * Always succeeds from the caller's point of view, so it cannot be used to discover admin emails.
     *
     * @param array<string, mixed> $input
     */
    public function requestLink(array $input, string $ip): void
    {
        $data = Validator::make($input, ['email' => 'required|email|max:150'], [
            'email.required' => 'Please enter your email address.',
            'email' => 'Enter a valid email address.',
        ])->validate();

        $admin = $this->admins->findByEmail(strtolower($data['email']));
        if ($admin === null || (int) $admin['is_active'] !== 1) {
            return;
        }

        $now = $this->clock->now();
        $minutes = (int) $this->config->get('security.password_reset_minutes', 30);
        $token = bin2hex(random_bytes(32));

        $this->resets->create(
            $admin['id'],
            hash('sha256', $token),
            $now->modify("+{$minutes} minutes")->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
        );

        $this->mailer->send($admin['email'], 'Reset your RIFA admin password', 'password-reset', [
            'name' => $admin['name'],
            'resetUrl' => $this->config->get('app.admin_url') . '/reset-password?token=' . $token,
            'minutes' => $minutes,
        ]);

        $this->audit->log($admin['id'], 'auth.password_reset_requested', 'admin', $admin['id'], $ip);
    }

    /** @param array<string, mixed> $input */
    public function reset(array $input, string $ip): void
    {
        $min = (int) $this->config->get('security.password_min_length', 10);
        $data = Validator::make($input, [
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'password' => "required|string|min:{$min}|max:200",
        ], [
            'token' => 'This reset link is invalid or has expired.',
            'password.required' => 'Please enter a new password.',
            'password' => "Password must be {$min}–200 characters.",
        ])->validate();

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $reset = $this->resets->findValid(hash('sha256', $data['token']), $now);
        if ($reset === null) {
            throw new ValidationException(['token' => 'This reset link is invalid or has expired.']);
        }

        $this->admins->updatePassword($reset['admin_id'], Password::hash($data['password']));
        $this->resets->markUsed($reset['id'], $now);

        $this->audit->log($reset['admin_id'], 'auth.password_reset', 'admin', $reset['admin_id'], $ip);
    }
}
