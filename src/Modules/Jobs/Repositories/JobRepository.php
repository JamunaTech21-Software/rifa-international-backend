<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Repositories;

use App\Core\Database;

final class JobRepository
{
    private const COLUMNS = 'j.*, c.slug AS category_slug, c.name AS category_name';
    private const FROM = 'FROM jobs j JOIN job_categories c ON c.id = j.job_category_id';
    private const ORDER = 'ORDER BY j.sort_order, j.id';
    private const EDITABLE = [
        'title', 'employer_type', 'job_category_id', 'location', 'salary_min', 'salary_max',
        'description', 'is_published', 'sort_order', 'image_public_id', 'updated_by',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> what the public site shows */
    public function published(): array
    {
        return $this->db->fetchAll('SELECT ' . self::COLUMNS . ' ' . self::FROM . ' WHERE j.is_published = 1 ' . self::ORDER);
    }

    /**
     * @param array{status?: ?string, category?: ?string, q?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['status'] ?? null) !== null) {
            $where[] = 'j.is_published = ?';
            $params[] = $filters['status'] === 'published' ? 1 : 0;
        }
        if (($filters['category'] ?? null) !== null) {
            $where[] = 'c.slug = ?';
            $params[] = $filters['category'];
        }
        if (($filters['q'] ?? null) !== null) {
            $where[] = '(j.title LIKE ? OR j.employer_type LIKE ? OR j.location LIKE ?)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }

        $sql = 'SELECT ' . self::COLUMNS . ' ' . self::FROM . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        return $this->db->fetchAll($sql . ' ' . self::ORDER, $params);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT ' . self::COLUMNS . ' ' . self::FROM . ' WHERE j.id = ?', [$id]);
    }

    public function slugExists(string $slug): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM jobs WHERE slug = ?', [$slug]) !== null;
    }

    public function nextSortOrder(): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM jobs');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('jobs', $data);
    }

    /** @param array<string, mixed> $changes */
    public function update(int $id, array $changes): void
    {
        $allowed = array_intersect_key($changes, array_flip(self::EDITABLE));
        if ($allowed === []) {
            return;
        }

        $set = implode(', ', array_map(fn (string $column) => "{$column} = ?", array_keys($allowed)));
        $this->db->execute("UPDATE jobs SET {$set} WHERE id = ?", [...array_values($allowed), $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM jobs WHERE id = ?', [$id]);
    }
}
