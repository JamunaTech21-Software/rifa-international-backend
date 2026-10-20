<?php

declare(strict_types=1);

namespace App\Modules\Applications\Repositories;

use App\Core\Database;

final class ApplicantMessageRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('application_messages', $data);
    }

    /** @return list<array<string, mixed>> newest first, with the sender's name */
    public function forApplication(int $applicationId): array
    {
        return $this->db->fetchAll(
            'SELECT m.*, a.name AS admin_name FROM application_messages m
             LEFT JOIN admins a ON a.id = m.admin_id
             WHERE m.application_id = ? ORDER BY m.created_at DESC, m.id DESC',
            [$applicationId],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT m.*, a.name AS admin_name FROM application_messages m LEFT JOIN admins a ON a.id = m.admin_id WHERE m.id = ?',
            [$id],
        );
    }
}
