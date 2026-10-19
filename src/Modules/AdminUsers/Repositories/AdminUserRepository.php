<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Repositories;

use App\Core\Database;

final class AdminUserRepository
{
    private const COLUMNS = 'id, name, email, role, is_active, locked_until, last_login_at, created_at';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT ' . self::COLUMNS . ' FROM admins ORDER BY is_active DESC, name ASC');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM admins WHERE id = ?', [$id]);
    }

    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM admins WHERE email = ? AND id <> ?',
            [$email, $exceptId ?? 0],
        ) !== null;
    }

    public function activeSuperAdminsExcept(int $id): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM admins WHERE role = 'super_admin' AND is_active = 1 AND id <> ?",
            [$id],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('admins', $data);
    }

    /** @param array{name?: string, role?: string, is_active?: bool} $changes */
    public function update(int $id, array $changes): void
    {
        $allowed = array_intersect_key($changes, array_flip(['name', 'role', 'is_active']));
        if ($allowed === []) {
            return;
        }

        $set = implode(', ', array_map(fn (string $column) => "{$column} = ?", array_keys($allowed)));
        $this->db->execute("UPDATE admins SET {$set} WHERE id = ?", [...array_values($allowed), $id]);
    }
}
