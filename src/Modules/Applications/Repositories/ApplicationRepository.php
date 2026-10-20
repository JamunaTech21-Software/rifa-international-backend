<?php

declare(strict_types=1);

namespace App\Modules\Applications\Repositories;

use App\Core\Database;

final class ApplicationRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Next number in today's sequence (1, 2, 3 …). Atomic across concurrent requests;
     * a failed submission leaves a gap, which is fine.
     */
    public function nextReferenceSequence(string $date): int
    {
        $this->db->execute(
            'INSERT INTO reference_counters (counter_date, last_seq) VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)',
            [$date],
        );

        return (int) $this->db->fetchValue('SELECT LAST_INSERT_ID()');
    }

    /** Same mobile or NID submitted since $since (soft-deleted rows ignored). */
    public function hasRecentDuplicate(string $mobile, ?string $nid, string $since): bool
    {
        $sql = 'SELECT 1 FROM applications WHERE deleted_at IS NULL AND created_at >= ? AND (mobile = ?';
        $params = [$since, $mobile];
        if ($nid !== null) {
            $sql .= ' OR nid_no = ?';
            $params[] = $nid;
        }

        return $this->db->fetchValue($sql . ') LIMIT 1', $params) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('applications', $data);
    }

    /** @param array{public_id: string, resource_type: string, format: string, bytes: int} $stored */
    public function addDocument(int $applicationId, string $docType, array $stored, string $originalName): int
    {
        return $this->db->insert('application_documents', [
            'application_id' => $applicationId,
            'doc_type' => $docType,
            'cloudinary_public_id' => $stored['public_id'],
            'resource_type' => $stored['resource_type'],
            'format' => $stored['format'],
            'bytes' => $stored['bytes'],
            'original_filename' => mb_substr($originalName, 0, 255),
        ]);
    }

    public function addStatusHistory(int $applicationId, ?string $from, string $to, ?string $note = null, ?int $adminId = null): int
    {
        return $this->db->insert('application_status_history', [
            'application_id' => $applicationId,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'changed_by' => $adminId,
        ]);
    }

    /**
     * Public status lookup (P5-04): both values must match a live application.
     *
     * @return array{id: int, reference_no: string, status: string, created_at: string, job_category: string}|null
     */
    public function findForStatusLookup(string $referenceNo, string $mobile): ?array
    {
        return $this->db->fetchOne(
            'SELECT a.id, a.reference_no, a.status, a.created_at, c.name AS job_category
             FROM applications a JOIN job_categories c ON c.id = a.job_category_id
             WHERE a.reference_no = ? AND a.mobile = ? AND a.deleted_at IS NULL',
            [$referenceNo, $mobile],
        );
    }

    /** @return list<array{to_status: string, created_at: string}> oldest first; notes are internal and not selected */
    public function statusTimeline(int $applicationId): array
    {
        return $this->db->fetchAll(
            'SELECT to_status, created_at FROM application_status_history WHERE application_id = ? ORDER BY created_at, id',
            [$applicationId],
        );
    }
}
