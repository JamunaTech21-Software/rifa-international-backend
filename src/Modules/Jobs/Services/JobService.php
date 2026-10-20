<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Services;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\UploadedFile;
use App\Core\Logger;
use App\Core\Validator;
use App\Modules\Jobs\Repositories\JobRepository;
use App\Modules\Lookups\Repositories\JobCategoryRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Clock;
use App\Shared\Storage\ImageStorage;
use App\Shared\Storage\InvalidDocumentException;
use App\Shared\Text\Slug;
use Throwable;

/**
 * Job listings (P5-02): public list for the website, CRUD + photo for admins.
 *
 * The slug is made from the title on create and never changes, so links stay valid.
 * Photos are public Cloudinary images under rifa/jobs; each upload gets a new public id
 * (no stale CDN copies) and the old photo is deleted.
 */
final class JobService
{
    public const CURRENCY = 'MYR';
    private const IMAGE_FOLDER = 'rifa/jobs';
    private const IMAGE_MAX_KB = 5120;
    private const REQUIRED = ['title', 'employer_type', 'job_category', 'location', 'salary_min', 'salary_max'];

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobCategoryRepository $categories,
        private readonly ImageStorage $images,
        private readonly AuditLogger $audit,
        private readonly Logger $logger,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function publicList(): array
    {
        return array_map(fn (array $row) => $this->present($row), $this->jobs->published());
    }

    /**
     * @param array<string, mixed> $query status (published|draft), category (slug), q
     * @return list<array<string, mixed>>
     */
    public function adminList(array $query): array
    {
        $filters = Validator::make($query, [
            'status' => 'in:published,draft',
            'category' => 'string|max:60',
            'q' => 'string|max:100',
        ])->validate();

        return array_map(fn (array $row) => $this->presentAdmin($row), $this->jobs->search($filters));
    }

    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        return $this->presentAdmin($this->findOrFail($id));
    }

    /**
     * @param array{id: int}       $actor
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $actor, array $input, string $ip): array
    {
        $data = $this->validate($input, self::REQUIRED);
        $data['slug'] = Slug::unique($data['title'], $this->jobs->slugExists(...), 'job');
        $data['sort_order'] ??= $this->jobs->nextSortOrder();
        $data['is_published'] = (int) ($data['is_published'] ?? true);
        $data['created_by'] = $actor['id'];
        $data['updated_by'] = $actor['id'];
        $data['created_at'] = $this->clock->now()->format('Y-m-d H:i:s');

        $id = $this->jobs->create($data);
        $this->audit->log($actor['id'], 'jobs.created', 'job', $id, $ip, ['slug' => $data['slug']]);

        return $this->show($id);
    }

    /**
     * @param array{id: int}       $actor
     * @param array<string, mixed> $input any subset of the create fields
     * @return array<string, mixed>
     */
    public function update(int $id, array $actor, array $input, string $ip): array
    {
        $job = $this->findOrFail($id);
        // Only the fields that were sent change; a required field that is sent must not be empty.
        $sent = array_keys($input);
        $validated = $this->validate($input, array_intersect(self::REQUIRED, $sent), $job);
        $data = [];
        foreach ($validated as $column => $value) {
            $field = $column === 'job_category_id' ? 'job_category' : $column;
            if (in_array($field, $sent, true) && ($value !== null || $field === 'description')) {
                $data[$column] = $value;
            }
        }
        if (isset($data['is_published'])) {
            $data['is_published'] = (int) $data['is_published'];
        }

        if ($data !== []) {
            $this->jobs->update($id, $data + ['updated_by' => $actor['id']]);
            $this->audit->log($actor['id'], 'jobs.updated', 'job', $id, $ip, ['changed' => array_keys($data)]);
        }

        return $this->show($id);
    }

    /** @param array{id: int} $actor */
    public function delete(int $id, array $actor, string $ip): void
    {
        $job = $this->findOrFail($id);
        $this->jobs->delete($id);
        $this->audit->log($actor['id'], 'jobs.deleted', 'job', $id, $ip, ['slug' => $job['slug'], 'title' => $job['title']]);
        $this->deleteImageQuietly($job['image_public_id']);
    }

    /**
     * @param array{id: int} $actor
     * @return array<string, mixed>
     */
    public function uploadImage(int $id, array $actor, ?UploadedFile $file, string $ip): array
    {
        $job = $this->findOrFail($id);
        Validator::make(['image' => $file], [
            'image' => 'required|file|max_kb:' . self::IMAGE_MAX_KB . '|mimes:jpg,png,webp',
        ], [
            'image.required' => 'Please choose a photo.',
            'image.file' => 'The photo could not be uploaded. Please try again.',
            'image.max_kb' => 'Photo must be 5 MB or smaller.',
            'image.mimes' => 'Photo must be a JPG, PNG or WebP image.',
        ])->validate();

        try {
            $stored = $this->images->uploadPublicImage(
                $file->path(),
                self::IMAGE_FOLDER,
                $job['slug'] . '-' . bin2hex(random_bytes(4)),
            );
        } catch (InvalidDocumentException) {
            throw new ValidationException(['image' => 'This file is not a valid image.']);
        }

        $this->jobs->update($id, ['image_public_id' => $stored['public_id'], 'updated_by' => $actor['id']]);
        $this->audit->log($actor['id'], 'jobs.image_updated', 'job', $id, $ip);
        $this->deleteImageQuietly($job['image_public_id']);

        return $this->show($id);
    }

    /**
     * @param array{id: int} $actor
     * @return array<string, mixed>
     */
    public function removeImage(int $id, array $actor, string $ip): array
    {
        $job = $this->findOrFail($id);
        if ($job['image_public_id'] !== null) {
            $this->jobs->update($id, ['image_public_id' => null, 'updated_by' => $actor['id']]);
            $this->audit->log($actor['id'], 'jobs.image_removed', 'job', $id, $ip);
            $this->deleteImageQuietly($job['image_public_id']);
        }

        return $this->show($id);
    }

    /**
     * @param array<string, mixed>      $input
     * @param list<string>              $required fields that must not be empty
     * @param array<string, mixed>|null $current  the job being updated (for the salary range check)
     * @return array<string, mixed> job_category is returned as job_category_id
     */
    private function validate(array $input, array $required, ?array $current = null): array
    {
        $rules = [
            'title' => 'string|min:2|max:100',
            'employer_type' => 'string|min:2|max:100',
            'job_category' => 'string|max:60',
            'location' => 'string|min:2|max:100',
            'salary_min' => 'integer|min:0|max:100000',
            'salary_max' => 'integer|min:0|max:100000',
            'description' => 'string|max:5000',
            'is_published' => 'boolean',
            'sort_order' => 'integer|min:0|max:9999',
        ];
        foreach ($required as $field) {
            $rules[$field] = 'required|' . $rules[$field];
        }
        $data = Validator::make($input, $rules, [
            'title.required' => 'Please enter the job title.',
            'title' => 'Job title must be 2–100 characters.',
            'employer_type.required' => 'Please enter the employer type.',
            'employer_type' => 'Employer type must be 2–100 characters.',
            'job_category.required' => 'Please select a job category.',
            'location.required' => 'Please enter the location.',
            'location' => 'Location must be 2–100 characters.',
            'salary_min.required' => 'Please enter the minimum salary.',
            'salary_min' => 'Minimum salary must be a whole number from 0 to 100000.',
            'salary_max.required' => 'Please enter the maximum salary.',
            'salary_max' => 'Maximum salary must be a whole number from 0 to 100000.',
            'description' => 'Description must be 5000 characters or fewer.',
            'is_published' => 'Published must be true or false.',
            'sort_order' => 'Order must be a whole number from 0 to 9999.',
        ])->validate();

        if ($data['job_category'] !== null) {
            $category = $this->categories->findActiveBySlug($data['job_category'])
                ?? throw new ValidationException(['job_category' => 'Please select a valid job category.']);
            $data['job_category_id'] = (int) $category['id'];
        } else {
            $data['job_category_id'] = null;
        }
        unset($data['job_category']);

        $min = $data['salary_min'] ?? ($current === null ? null : (int) $current['salary_min']);
        $max = $data['salary_max'] ?? ($current === null ? null : (int) $current['salary_max']);
        if ($min !== null && $max !== null && $max < $min) {
            throw new ValidationException(['salary_max' => 'Maximum salary must be at least the minimum salary.']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id): array
    {
        return $this->jobs->find($id) ?? throw new NotFoundException('Job not found.');
    }

    private function deleteImageQuietly(?string $publicId): void
    {
        if ($publicId === null) {
            return;
        }
        try {
            $this->images->deletePublicImage($publicId);
        } catch (Throwable $e) {
            // The listing change already happened; an orphaned photo is harmless.
            $this->logger->warning('Job photo could not be deleted from Cloudinary', ['public_id' => $publicId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'employer_type' => $row['employer_type'],
            'category' => ['slug' => $row['category_slug'], 'name' => $row['category_name']],
            'location' => $row['location'],
            'salary_min' => (int) $row['salary_min'],
            'salary_max' => (int) $row['salary_max'],
            'currency' => self::CURRENCY,
            'description' => $row['description'],
            'image_public_id' => $row['image_public_id'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentAdmin(array $row): array
    {
        return $this->present($row) + [
            'is_published' => (bool) $row['is_published'],
            'sort_order' => (int) $row['sort_order'],
            'created_at' => $this->clock->toIso($row['created_at']),
            'updated_at' => $this->clock->toIso($row['updated_at']),
        ];
    }
}
