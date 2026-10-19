<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Dashboard\Repositories\DashboardRepository;
use App\Modules\Lookups\Services\LookupsService;
use App\Shared\Clock;

/** Admin dashboard numbers (plan.md §4.4, story BE-14). The office week starts on Sunday. */
final class DashboardService
{
    private const LATEST = 5;

    public function __construct(
        private readonly DashboardRepository $repository,
        private readonly LookupsService $lookups,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $today = $this->clock->today();
        $weekStart = $today->format('w') === '0' ? $today : $today->modify('last sunday');
        $byStatus = $this->repository->applicationsByStatus();

        return [
            'applications' => [
                'today' => $this->repository->applicationsSince($today->format('Y-m-d H:i:s')),
                'this_week' => $this->repository->applicationsSince($weekStart->format('Y-m-d H:i:s')),
                'total' => $this->repository->applicationsSince(null),
            ],
            'messages' => $this->repository->messageCounts(),
            'by_status' => array_map(fn (array $s) => [
                'status' => $s['value'],
                'label' => $s['label'],
                'count' => $byStatus[$s['value']] ?? 0,
            ], $this->lookups->options('application_statuses')),
            'by_category' => array_map(fn (array $c) => [
                'slug' => $c['slug'],
                'name' => $c['name'],
                'count' => (int) $c['total'],
            ], $this->repository->applicationsByCategory()),
            'latest_applications' => array_map(fn (array $a) => [
                'id' => (int) $a['id'],
                'reference_no' => $a['reference_no'],
                'full_name' => $a['full_name'],
                'category_name' => $a['category_name'],
                'status' => $a['status'],
                'status_label' => $this->lookups->label('application_statuses', $a['status']),
                'created_at' => $this->clock->toIso($a['created_at']),
            ], $this->repository->latestApplications(self::LATEST)),
            'latest_messages' => array_map(fn (array $m) => [
                'id' => (int) $m['id'],
                'full_name' => $m['full_name'],
                'subject_label' => $this->lookups->label('contact_subjects', $m['subject']),
                'status' => $m['status'],
                'created_at' => $this->clock->toIso($m['created_at']),
            ], $this->repository->latestMessages(self::LATEST)),
        ];
    }
}
