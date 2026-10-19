<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Services;

use App\Core\Config;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use App\Modules\AdminUsers\Repositories\AdminUserRepository;
use App\Modules\Auth\Repositories\AdminRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Clock;
use App\Shared\Security\Password;

/**
 * Admin user management for super admins (story BE-14).
 *
 * Rules: emails are unique; you cannot deactivate yourself; there must always be at least one
 * active super admin; your own password is changed via /admin/auth/change-password, not here.
 * Setting a password or deactivating a user logs that user out everywhere.
 */
final class AdminUserService
{
    private const ROLES = ['super_admin', 'staff'];

    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly AdminRepository $auth,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(fn (array $row) => $this->present($row), $this->users->all());
    }

    /**
     * @param array{id: int}       $actor
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $actor, array $input, string $ip): array
    {
        $min = (int) $this->config->get('security.password_min_length', 10);
        $data = Validator::make($input, [
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|email|max:150',
            'role' => 'required|in:' . implode(',', self::ROLES),
            'password' => "required|string|min:{$min}|max:200",
        ], $this->messages($min))->validate();

        $email = strtolower($data['email']);
        if ($this->users->emailTaken($email)) {
            throw new ValidationException(['email' => 'An admin with this email already exists.']);
        }

        $id = $this->users->create([
            'name' => $data['name'],
            'email' => $email,
            'role' => $data['role'],
            'password_hash' => Password::hash($data['password']),
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
        $this->audit->log($actor['id'], 'users.created', 'admin', $id, $ip, ['role' => $data['role']]);

        return $this->present($this->users->find($id) ?? []);
    }

    /**
     * @param array{id: int}       $actor
     * @param array<string, mixed> $input name, role, is_active, password — all optional
     * @return array<string, mixed>
     */
    public function update(int $id, array $actor, array $input, string $ip): array
    {
        $user = $this->users->find($id) ?? throw new NotFoundException('Admin user not found.');
        $min = (int) $this->config->get('security.password_min_length', 10);
        $data = Validator::make($input, [
            'name' => 'string|min:2|max:100',
            'role' => 'in:' . implode(',', self::ROLES),
            'is_active' => 'boolean',
            'password' => "string|min:{$min}|max:200",
        ], $this->messages($min))->validate();
        $data = array_filter($data, fn ($value) => $value !== null);

        $isSelf = $id === $actor['id'];
        if ($isSelf && ($data['is_active'] ?? true) === false) {
            throw new ValidationException(['is_active' => 'You cannot deactivate your own account.']);
        }
        if ($isSelf && isset($data['password'])) {
            throw new ValidationException(['password' => 'Use "Change password" to change your own password.']);
        }

        $losesSuperAdmin = $user['role'] === 'super_admin' && (int) $user['is_active'] === 1
            && (($data['role'] ?? 'super_admin') !== 'super_admin' || ($data['is_active'] ?? true) === false);
        if ($losesSuperAdmin && $this->users->activeSuperAdminsExcept($id) === 0) {
            $field = isset($data['role']) && $data['role'] !== 'super_admin' ? 'role' : 'is_active';
            throw new ValidationException([$field => 'There must be at least one active super admin.']);
        }

        $this->users->update($id, $data);
        if (isset($data['password'])) {
            $this->auth->updatePassword($id, Password::hash($data['password'])); // also logs the user out
        }

        $changed = array_keys($data);
        if ($changed !== []) {
            $this->audit->log($actor['id'], 'users.updated', 'admin', $id, $ip, ['changed' => $changed]);
        }

        return $this->present($this->users->find($id) ?? []);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $lockedUntil = $row['locked_until'] ?? null;

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'is_active' => (bool) $row['is_active'],
            'is_locked' => $lockedUntil !== null && $this->clock->toIso($lockedUntil) > $this->clock->now()->format(DATE_ATOM),
            'last_login_at' => $this->clock->toIso($row['last_login_at']),
            'created_at' => $this->clock->toIso($row['created_at']),
        ];
    }

    /** @return array<string, string> */
    private function messages(int $min): array
    {
        return [
            'name.required' => 'Please enter a name.',
            'name' => 'Name must be 2–100 characters.',
            'email.required' => 'Please enter an email address.',
            'email' => 'Enter a valid email address.',
            'role.required' => 'Please select a role.',
            'role' => 'Role must be super admin or staff.',
            'is_active' => 'Active must be true or false.',
            'password.required' => 'Please enter a password.',
            'password' => "Password must be {$min}–200 characters.",
        ];
    }
}
