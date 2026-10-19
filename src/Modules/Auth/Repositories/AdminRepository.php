<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Core\Database;

/**
 * Admin rows for authentication. (Admin user management lives in the AdminUsers module.)
 *
 * @phpstan-type AdminRow array{id: int, name: string, email: string, role: string, password_hash: string,
 *     session_version: int, is_active: int, failed_logins: int, locked_until: ?string}
 */
final class AdminRepository
{
    private const COLUMNS = 'id, name, email, role, password_hash, session_version, is_active, failed_logins, locked_until';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return AdminRow|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM admins WHERE email = ?', [$email]);
    }

    /** @return AdminRow|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM admins WHERE id = ?', [$id]);
    }

    /** Count a failed login; lock the account when $maxAttempts is reached. */
    public function recordFailedLogin(int $id, int $maxAttempts, string $lockUntil): void
    {
        $this->db->execute(
            'UPDATE admins
                SET locked_until = IF(failed_logins + 1 >= ?, ?, locked_until),
                    failed_logins = IF(failed_logins + 1 >= ?, 0, failed_logins + 1)
              WHERE id = ?',
            [$maxAttempts, $lockUntil, $maxAttempts, $id],
        );
    }

    public function recordSuccessfulLogin(int $id, string $at): void
    {
        $this->db->execute(
            'UPDATE admins SET failed_logins = 0, locked_until = NULL, last_login_at = ? WHERE id = ?',
            [$at, $id],
        );
    }

    /** Store a new hash and bump session_version (logs out older sessions). Returns the new version. */
    public function updatePassword(int $id, string $hash): int
    {
        $this->db->execute(
            'UPDATE admins SET password_hash = ?, session_version = session_version + 1, failed_logins = 0, locked_until = NULL WHERE id = ?',
            [$hash, $id],
        );

        return (int) $this->db->fetchValue('SELECT session_version FROM admins WHERE id = ?', [$id]);
    }

    /** Silent upgrade of an old hash format (e.g. bcrypt → argon2id) on login. */
    public function rehash(int $id, string $hash): void
    {
        $this->db->execute('UPDATE admins SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }
}
