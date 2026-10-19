<?php

declare(strict_types=1);

namespace App\Modules\Applications\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use App\Modules\Applications\Repositories\AdminApplicationRepository;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Lookups\Repositories\JobCategoryRepository;
use App\Modules\Lookups\Services\LookupsService;
use App\Shared\Audit\AuditLogger;
use App\Shared\Clock;
use App\Shared\Storage\DocumentStorage;

/**
 * Admin panel operations on applications (story BE-12).
 * $admin is the request attribute set by the auth middleware: ['id', 'name', 'email', 'role'].
 */
final class AdminApplicationService
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AdminApplicationRepository $repository,
        private readonly ApplicationRepository $applications,
        private readonly JobCategoryRepository $categories,
        private readonly LookupsService $lookups,
        private readonly StatusWorkflow $workflow,
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $audit,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}}
     */
    public function list(array $query): array
    {
        $filters = $this->validateFilters($query);
        $page = $filters['page'] ?? 1;
        $perPage = min($filters['per_page'] ?? 20, self::MAX_PER_PAGE);

        $result = $this->repository->search($filters, $page, $perPage);

        return [
            'data' => array_map(fn (array $row) => $this->summary($row), $result['rows']),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']],
        ];
    }

    /**
     * @param array{id: int, role: string} $admin
     * @return array<string, mixed>
     */
    public function detail(int $id, array $admin): array
    {
        $row = $this->findOrFail($id);

        return $this->summary($row) + [
            'father_name' => $row['father_name'],
            'date_of_birth' => $row['date_of_birth'],
            'age_at_submission' => (int) $row['age_at_submission'],
            'gender' => $row['gender'],
            'nid_no' => $row['nid_no'],
            'present_address' => $row['present_address'],
            'work_experience' => $row['work_experience'],
            'education_level' => $row['education_level'],
            'labels' => [
                'gender' => $this->lookups->label('genders', $row['gender']),
                'work_experience' => $this->lookups->label('work_experience', $row['work_experience']),
                'education_level' => $this->lookups->label('education_levels', $row['education_level']),
                'passport_status' => $this->lookups->label('passport_statuses', $row['passport_status']),
                'status' => $this->lookups->label('application_statuses', $row['status']),
            ],
            'updated_at' => $this->clock->toIso($row['updated_at']),
            'allowed_next_statuses' => $this->workflow->allowedNext($row['status'], $admin['role'] === 'super_admin'),
            'documents' => array_map(fn (array $d) => [
                'id' => (int) $d['id'],
                'doc_type' => $d['doc_type'],
                'format' => $d['format'],
                'bytes' => (int) $d['bytes'],
                'original_filename' => $d['original_filename'],
                'created_at' => $this->clock->toIso($d['created_at']),
            ], $this->repository->documents($id)),
            'notes' => array_map(fn (array $n) => $this->note($n), $this->repository->notes($id)),
            'history' => array_map(fn (array $h) => [
                'id' => (int) $h['id'],
                'from_status' => $h['from_status'],
                'to_status' => $h['to_status'],
                'note' => $h['note'],
                'changed_by' => $h['changed_by'] === null ? null : ['id' => (int) $h['changed_by'], 'name' => $h['admin_name']],
                'created_at' => $this->clock->toIso($h['created_at']),
            ], $this->repository->history($id)),
        ];
    }

    /**
     * @param array{id: int, role: string} $admin
     * @param array<string, mixed>         $input
     * @return array<string, mixed> updated detail
     */
    public function changeStatus(int $id, array $admin, array $input, string $ip): array
    {
        $row = $this->findOrFail($id);
        $statuses = implode(',', $this->lookups->values('application_statuses'));
        $data = Validator::make($input, [
            'status' => "required|in:{$statuses}",
            'note' => 'string|max:1000',
        ], [
            'status.required' => 'Please select a status.',
            'status' => 'Please select a valid status.',
            'note' => 'Note may not be longer than 1000 characters.',
        ])->validate();

        $from = $row['status'];
        $to = $data['status'];
        if (!$this->workflow->canMove($from, $to, $admin['role'] === 'super_admin')) {
            $fromLabel = $this->lookups->label('application_statuses', $from);
            $toLabel = $this->lookups->label('application_statuses', $to);
            throw new ValidationException(['status' => "Cannot change status from {$fromLabel} to {$toLabel}."]);
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->transaction(function () use ($id, $from, $to, $data, $admin, $now): void {
            $this->repository->updateStatus($id, $to, $now);
            $this->applications->addStatusHistory($id, $from, $to, $data['note'], $admin['id']);
        });

        $this->audit->log($admin['id'], 'applications.status_changed', 'application', $id, $ip, ['from' => $from, 'to' => $to]);

        return $this->detail($id, $admin);
    }

    /**
     * @param array{id: int, name: string} $admin
     * @param array<string, mixed>         $input
     * @return array<string, mixed>
     */
    public function addNote(int $id, array $admin, array $input, string $ip): array
    {
        $this->findOrFail($id);
        $data = Validator::make($input, ['body' => 'required|string|max:2000'], [
            'body.required' => 'Please write a note.',
            'body' => 'Note may not be longer than 2000 characters.',
        ])->validate();

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $noteId = $this->repository->addNote($id, $admin['id'], $data['body'], $now);
        $this->audit->log($admin['id'], 'applications.note_added', 'application', $id, $ip, ['note_id' => $noteId]);

        return $this->note(['id' => $noteId, 'body' => $data['body'], 'created_at' => $now, 'admin_id' => $admin['id'], 'admin_name' => $admin['name']]);
    }

    /**
     * @param array{id: int} $admin
     * @return array{url: string, expires_at: string}
     */
    public function documentUrl(int $id, int $documentId, array $admin, string $ip): array
    {
        $this->findOrFail($id);
        $document = $this->repository->document($id, $documentId);
        if ($document === null) {
            throw new NotFoundException('Document not found.');
        }

        $link = $this->storage->temporaryUrl(
            $document['cloudinary_public_id'],
            $document['resource_type'],
            $document['format'],
            (int) $this->config->get('cloudinary.signed_url_ttl', 300),
        );
        $this->audit->log($admin['id'], 'applications.document_viewed', 'application', $id, $ip, [
            'document_id' => $documentId,
            'doc_type' => $document['doc_type'],
        ]);

        return [
            'url' => $link['url'],
            'expires_at' => $this->clock->now()->setTimestamp($link['expires_at'])->format(DATE_ATOM),
        ];
    }

    /** @param array{id: int} $admin */
    public function delete(int $id, array $admin, string $ip): void
    {
        $row = $this->findOrFail($id);
        $this->repository->softDelete($id, $this->clock->now()->format('Y-m-d H:i:s'));
        $this->audit->log($admin['id'], 'applications.deleted', 'application', $id, $ip, ['reference_no' => $row['reference_no']]);
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id): array
    {
        return $this->repository->find($id) ?? throw new NotFoundException('Application not found.');
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function validateFilters(array $query): array
    {
        $categories = implode(',', array_column($this->categories->active(), 'slug'));

        return Validator::make($query, [
            'q' => 'string|max:100',
            'status' => 'in:' . implode(',', $this->lookups->values('application_statuses')),
            'category' => "in:{$categories}",
            'passport_status' => 'in:' . implode(',', $this->lookups->values('passport_statuses')),
            'gender' => 'in:' . implode(',', $this->lookups->values('genders')),
            'flag' => 'in:age,duplicate',
            'from' => 'date',
            'to' => 'date',
            'sort' => 'in:-created_at,created_at,full_name,-full_name',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ])->validate();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'reference_no' => $row['reference_no'],
            'full_name' => $row['full_name'],
            'mobile' => $row['mobile'],
            'email' => $row['email'],
            'category' => ['slug' => $row['category_slug'], 'name' => $row['category_name']],
            'passport_status' => $row['passport_status'],
            'status' => $row['status'],
            'is_age_flagged' => (bool) $row['is_age_flagged'],
            'is_duplicate_flagged' => (bool) $row['is_duplicate_flagged'],
            'created_at' => $this->clock->toIso($row['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $note
     * @return array<string, mixed>
     */
    private function note(array $note): array
    {
        return [
            'id' => (int) $note['id'],
            'body' => $note['body'],
            'admin' => $note['admin_id'] === null ? null : ['id' => (int) $note['admin_id'], 'name' => $note['admin_name']],
            'created_at' => $this->clock->toIso($note['created_at']),
        ];
    }
}
