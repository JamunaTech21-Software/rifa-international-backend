<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Repositories;

use App\Core\Database;

final class DashboardRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function applicationsSince(?string $since): int
    {
        return $since === null
            ? (int) $this->db->fetchValue('SELECT COUNT(*) FROM applications WHERE deleted_at IS NULL')
            : (int) $this->db->fetchValue('SELECT COUNT(*) FROM applications WHERE deleted_at IS NULL AND created_at >= ?', [$since]);
    }

    /** @return array<string, int> status => count */
    public function applicationsByStatus(): array
    {
        $rows = $this->db->fetchAll('SELECT status, COUNT(*) AS total FROM applications WHERE deleted_at IS NULL GROUP BY status');

        return array_map('intval', array_column($rows, 'total', 'status'));
    }

    /** @return list<array{slug: string, name: string, total: int}> active categories, including empty ones */
    public function applicationsByCategory(): array
    {
        return $this->db->fetchAll(
            'SELECT c.slug, c.name, COUNT(a.id) AS total
               FROM job_categories c
               LEFT JOIN applications a ON a.job_category_id = c.id AND a.deleted_at IS NULL
              WHERE c.is_active = 1
              GROUP BY c.id, c.slug, c.name, c.sort_order
              ORDER BY c.sort_order',
        );
    }

    /** @return array{unread: int, open: int} */
    public function messageCounts(): array
    {
        $row = $this->db->fetchOne(
            "SELECT SUM(status = 'new') AS unread, SUM(status <> 'archived') AS open FROM contact_messages",
        );

        return ['unread' => (int) ($row['unread'] ?? 0), 'open' => (int) ($row['open'] ?? 0)];
    }

    /** @return list<array<string, mixed>> */
    public function latestApplications(int $limit): array
    {
        return $this->db->fetchAll(
            'SELECT a.id, a.reference_no, a.full_name, a.status, a.created_at, c.name AS category_name
               FROM applications a JOIN job_categories c ON c.id = a.job_category_id
              WHERE a.deleted_at IS NULL
              ORDER BY a.created_at DESC, a.id DESC LIMIT ?',
            [$limit],
        );
    }

    /** @return list<array<string, mixed>> */
    public function latestMessages(int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT id, full_name, subject, status, created_at FROM contact_messages
              WHERE status <> 'archived' ORDER BY created_at DESC, id DESC LIMIT ?",
            [$limit],
        );
    }
}
