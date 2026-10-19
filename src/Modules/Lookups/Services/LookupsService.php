<?php

declare(strict_types=1);

namespace App\Modules\Lookups\Services;

use App\Core\Config;
use App\Modules\Lookups\Repositories\JobCategoryRepository;

/**
 * Option lists for forms. Other modules use the *Values() helpers to build
 * `in:` validation rules so the API and the dropdowns never drift apart.
 */
final class LookupsService
{
    public function __construct(
        private readonly JobCategoryRepository $categories,
        private readonly Config $config,
    ) {
    }

    /** @return array<string, list<array<string, string>>> */
    public function publicLookups(): array
    {
        return [
            'job_categories' => array_map(
                fn (array $c) => ['slug' => $c['slug'], 'name' => $c['name']],
                $this->categories->active(),
            ),
            'genders' => $this->options('genders'),
            'work_experience' => $this->options('work_experience'),
            'education_levels' => $this->options('education_levels'),
            'passport_statuses' => $this->options('passport_statuses'),
            'contact_subjects' => $this->options('contact_subjects'),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public function options(string $list): array
    {
        return $this->config->get("lookups.{$list}", []);
    }

    /** @return list<string> e.g. values('work_experience') → ['none', 'lt_1', ...] */
    public function values(string $list): array
    {
        return array_column($this->options($list), 'value');
    }

    public function label(string $list, ?string $value): ?string
    {
        foreach ($this->options($list) as $option) {
            if ($option['value'] === $value) {
                return $option['label'];
            }
        }

        return null;
    }
}
