<?php

declare(strict_types=1);

namespace App\Modules\Lookups\Repositories;

use App\Core\Database;

final class JobCategoryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array{id: int, slug: string, name: string}> */
    public function active(): array
    {
        /** @var list<array{id: int, slug: string, name: string}> */
        return $this->db->fetchAll(
            'SELECT id, slug, name FROM job_categories WHERE is_active = 1 ORDER BY sort_order, name',
        );
    }

    /** @return array{id: int, slug: string, name: string}|null */
    public function findActiveBySlug(string $slug): ?array
    {
        /** @var array{id: int, slug: string, name: string}|null */
        return $this->db->fetchOne(
            'SELECT id, slug, name FROM job_categories WHERE slug = ? AND is_active = 1',
            [$slug],
        );
    }
}
