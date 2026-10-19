<?php

declare(strict_types=1);

namespace App\Modules\Messages\Repositories;

use App\Core\Database;

final class AdminMessageRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{q: ?string, status: ?string, subject: ?string} $filters  status null = everything except archived
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $clauses = [];
        $params = [];

        if (($filters['status'] ?? null) === null) {
            $clauses[] = "m.status <> 'archived'";
        } else {
            $clauses[] = 'm.status = ?';
            $params[] = $filters['status'];
        }
        if (($filters['subject'] ?? null) !== null) {
            $clauses[] = 'm.subject = ?';
            $params[] = $filters['subject'];
        }
        if (($filters['q'] ?? null) !== null) {
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $clauses[] = '(m.full_name LIKE ? OR m.email LIKE ? OR m.phone LIKE ? OR m.message LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $where = implode(' AND ', $clauses);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM contact_messages m WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            "SELECT m.id, m.full_name, m.email, m.phone, m.subject, m.message, m.status, m.read_at, m.created_at,
                    (SELECT COUNT(*) FROM message_replies r WHERE r.contact_message_id = m.id) AS replies_count
               FROM contact_messages m
              WHERE {$where}
              ORDER BY m.created_at DESC, m.id DESC
              LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public function unreadCount(): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT m.*, (SELECT COUNT(*) FROM message_replies r WHERE r.contact_message_id = m.id) AS replies_count
               FROM contact_messages m WHERE m.id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> oldest first (conversation order) */
    public function replies(int $messageId): array
    {
        return $this->db->fetchAll(
            'SELECT r.id, r.body, r.sent_to, r.sent_at, r.delivery_status, r.created_at, r.admin_id, a.name AS admin_name
               FROM message_replies r LEFT JOIN admins a ON a.id = r.admin_id
              WHERE r.contact_message_id = ? ORDER BY r.created_at ASC, r.id ASC',
            [$messageId],
        );
    }

    public function setStatus(int $id, string $status, ?string $readAt): void
    {
        $this->db->execute('UPDATE contact_messages SET status = ?, read_at = ? WHERE id = ?', [$status, $readAt, $id]);
    }

    /** @param array<string, mixed> $data */
    public function addReply(array $data): int
    {
        return $this->db->insert('message_replies', $data);
    }
}
