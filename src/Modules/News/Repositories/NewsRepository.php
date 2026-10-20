<?php

declare(strict_types=1);

namespace App\Modules\News\Repositories;

use App\Core\Database;

final class NewsRepository
{
    private const ORDER = 'ORDER BY published_on DESC, id DESC';
    private const EDITABLE = ['title', 'excerpt', 'body', 'is_published', 'published_on', 'image_public_id', 'updated_by'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> public posts (published, date reached), newest first */
    public function published(string $today, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM news_posts WHERE is_published = 1 AND published_on <= ? ' . self::ORDER;

        return $limit === null
            ? $this->db->fetchAll($sql, [$today])
            : $this->db->fetchAll($sql . ' LIMIT ' . $limit, [$today]); // $limit is a validated int
    }

    /** @return array<string, mixed>|null */
    public function findPublishedBySlug(string $slug, string $today): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM news_posts WHERE slug = ? AND is_published = 1 AND published_on <= ?',
            [$slug, $today],
        );
    }

    /**
     * @param array{status?: ?string, q?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, string $today): array
    {
        $where = [];
        $params = [];
        switch ($filters['status'] ?? null) {
            case 'published':
                $where[] = 'is_published = 1 AND published_on <= ?';
                $params[] = $today;
                break;
            case 'scheduled':
                $where[] = 'is_published = 1 AND published_on > ?';
                $params[] = $today;
                break;
            case 'draft':
                $where[] = 'is_published = 0';
                break;
        }
        if (($filters['q'] ?? null) !== null) {
            $where[] = '(title LIKE ? OR excerpt LIKE ?)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            array_push($params, $like, $like);
        }

        $sql = 'SELECT * FROM news_posts' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        return $this->db->fetchAll($sql . ' ' . self::ORDER, $params);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM news_posts WHERE id = ?', [$id]);
    }

    public function slugExists(string $slug): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM news_posts WHERE slug = ?', [$slug]) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('news_posts', $data);
    }

    /** @param array<string, mixed> $changes */
    public function update(int $id, array $changes): void
    {
        $allowed = array_intersect_key($changes, array_flip(self::EDITABLE));
        if ($allowed === []) {
            return;
        }

        $set = implode(', ', array_map(fn (string $column) => "{$column} = ?", array_keys($allowed)));
        $this->db->execute("UPDATE news_posts SET {$set} WHERE id = ?", [...array_values($allowed), $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM news_posts WHERE id = ?', [$id]);
    }
}
