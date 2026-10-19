<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Core\Database;

final class PasswordResetRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Store a new token hash; any older unused tokens of the admin stop working. */
    public function create(int $adminId, string $tokenHash, string $expiresAt, string $now): void
    {
        $this->db->execute('UPDATE password_resets SET used_at = ? WHERE admin_id = ? AND used_at IS NULL', [$now, $adminId]);
        $this->db->insert('password_resets', [
            'admin_id' => $adminId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);
    }

    /** @return array{id: int, admin_id: int}|null an unused, unexpired token of an active admin */
    public function findValid(string $tokenHash, string $now): ?array
    {
        return $this->db->fetchOne(
            'SELECT r.id, r.admin_id
               FROM password_resets r
               JOIN admins a ON a.id = r.admin_id AND a.is_active = 1
              WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?',
            [$tokenHash, $now],
        );
    }

    public function markUsed(int $id, string $now): void
    {
        $this->db->execute('UPDATE password_resets SET used_at = ? WHERE id = ?', [$now, $id]);
    }
}
