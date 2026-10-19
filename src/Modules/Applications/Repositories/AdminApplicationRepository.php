<?php

declare(strict_types=1);

namespace App\Modules\Applications\Repositories;

use App\Core\Database;

/** Read/write queries for the admin panel. Soft-deleted applications are always excluded. */
final class AdminApplicationRepository
{
    private const SORTS = [
        '-created_at' => 'a.created_at DESC, a.id DESC',
        'created_at' => 'a.created_at ASC, a.id ASC',
        'full_name' => 'a.full_name ASC, a.id ASC',
        '-full_name' => 'a.full_name DESC, a.id DESC',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{q: ?string, status: ?string, category: ?string, passport_status: ?string, gender: ?string,
     *              flag: ?string, from: ?string, to: ?string, sort: ?string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->where($filters);
        $from = 'FROM applications a JOIN job_categories c ON c.id = a.job_category_id WHERE ' . $where;

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) {$from}", $params);
        $order = self::SORTS[$filters['sort'] ?? '-created_at'] ?? self::SORTS['-created_at'];

        $rows = $this->db->fetchAll(
            "SELECT a.id, a.reference_no, a.full_name, a.mobile, a.email, a.passport_status, a.status,
                    a.is_age_flagged, a.is_duplicate_flagged, a.created_at, c.slug AS category_slug, c.name AS category_name
             {$from} ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT a.*, c.slug AS category_slug, c.name AS category_name
               FROM applications a JOIN job_categories c ON c.id = a.job_category_id
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function documents(int $applicationId): array
    {
        return $this->db->fetchAll(
            'SELECT id, doc_type, format, bytes, original_filename, created_at
               FROM application_documents WHERE application_id = ? ORDER BY id',
            [$applicationId],
        );
    }

    /** @return array<string, mixed>|null */
    public function document(int $applicationId, int $documentId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, doc_type, cloudinary_public_id, resource_type, format
               FROM application_documents WHERE id = ? AND application_id = ?',
            [$documentId, $applicationId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function notes(int $applicationId): array
    {
        return $this->db->fetchAll(
            'SELECT n.id, n.body, n.created_at, n.admin_id, ad.name AS admin_name
               FROM application_notes n LEFT JOIN admins ad ON ad.id = n.admin_id
              WHERE n.application_id = ? ORDER BY n.created_at DESC, n.id DESC',
            [$applicationId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(int $applicationId): array
    {
        return $this->db->fetchAll(
            'SELECT h.id, h.from_status, h.to_status, h.note, h.created_at, h.changed_by, ad.name AS admin_name
               FROM application_status_history h LEFT JOIN admins ad ON ad.id = h.changed_by
              WHERE h.application_id = ? ORDER BY h.created_at DESC, h.id DESC',
            [$applicationId],
        );
    }

    public function updateStatus(int $id, string $status, string $at): void
    {
        $this->db->execute('UPDATE applications SET status = ?, updated_at = ? WHERE id = ?', [$status, $at, $id]);
    }

    public function addNote(int $applicationId, int $adminId, string $body, string $at): int
    {
        return $this->db->insert('application_notes', [
            'application_id' => $applicationId,
            'admin_id' => $adminId,
            'body' => $body,
            'created_at' => $at,
        ]);
    }

    public function softDelete(int $id, string $at): void
    {
        $this->db->execute('UPDATE applications SET deleted_at = ? WHERE id = ?', [$at, $id]);
    }

    /**
     * @param array<string, ?string> $f
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $f): array
    {
        $clauses = ['a.deleted_at IS NULL'];
        $params = [];

        if (($f['q'] ?? null) !== null) {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $search = ['a.full_name LIKE ?', 'a.reference_no LIKE ?', 'a.nid_no LIKE ?', 'a.email LIKE ?'];
            array_push($params, $like, $like, $like, $like);

            $digits = preg_replace('/\D+/', '', $f['q']);
            if ($digits !== null && strlen($digits) >= 4) {
                $search[] = 'a.mobile LIKE ?';
                $params[] = '%' . $digits . '%';
            }
            $clauses[] = '(' . implode(' OR ', $search) . ')';
        }

        foreach (['status' => 'a.status', 'category' => 'c.slug', 'passport_status' => 'a.passport_status', 'gender' => 'a.gender'] as $key => $column) {
            if (($f[$key] ?? null) !== null) {
                $clauses[] = "{$column} = ?";
                $params[] = $f[$key];
            }
        }

        if (($f['flag'] ?? null) === 'age') {
            $clauses[] = 'a.is_age_flagged = 1';
        } elseif (($f['flag'] ?? null) === 'duplicate') {
            $clauses[] = 'a.is_duplicate_flagged = 1';
        }

        if (($f['from'] ?? null) !== null) {
            $clauses[] = 'a.created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (($f['to'] ?? null) !== null) {
            $clauses[] = 'a.created_at <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }

        return [implode(' AND ', $clauses), $params];
    }
}
