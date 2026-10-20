<?php

declare(strict_types=1);

namespace App\Modules\News\Services;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\UploadedFile;
use App\Core\Logger;
use App\Core\Validator;
use App\Modules\News\Repositories\NewsRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Clock;
use App\Shared\Storage\ImageStorage;
use App\Shared\Storage\InvalidDocumentException;
use App\Shared\Text\Slug;
use Throwable;

/**
 * News & Updates (P5-03): public list/article for the website, CRUD + cover photo for admins.
 *
 * The body is plain text (no HTML); a blank line starts a new paragraph and the API returns
 * paragraphs as an array. A published post with a future published_on stays hidden until that day.
 * The slug is made from the title on create and never changes.
 */
final class NewsService
{
    private const IMAGE_FOLDER = 'rifa/news';
    private const IMAGE_MAX_KB = 5120;
    private const REQUIRED = ['title', 'excerpt', 'body'];

    public function __construct(
        private readonly NewsRepository $news,
        private readonly ImageStorage $images,
        private readonly AuditLogger $audit,
        private readonly Logger $logger,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $query limit (1–50, optional — e.g. 3 for the home page)
     * @return list<array<string, mixed>> without the body
     */
    public function publicList(array $query): array
    {
        $limit = Validator::make($query, ['limit' => 'integer|min:1|max:50'], [
            'limit' => 'Limit must be a whole number from 1 to 50.',
        ])->validate()['limit'];

        return array_map(fn (array $row) => $this->summary($row), $this->news->published($this->today(), $limit));
    }

    /** @return array<string, mixed> */
    public function publicArticle(string $slug): array
    {
        $row = $this->news->findPublishedBySlug($slug, $this->today())
            ?? throw new NotFoundException('This article does not exist or is no longer available.');

        return $this->article($row);
    }

    /**
     * @param array<string, mixed> $query status (published|scheduled|draft), q
     * @return list<array<string, mixed>>
     */
    public function adminList(array $query): array
    {
        $filters = Validator::make($query, [
            'status' => 'in:published,scheduled,draft',
            'q' => 'string|max:100',
        ])->validate();

        return array_map(fn (array $row) => $this->presentAdmin($row), $this->news->search($filters, $this->today()));
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
        $data['slug'] = Slug::unique($data['title'], $this->news->slugExists(...), 'news', 90);
        $data['is_published'] = (int) ($data['is_published'] ?? true);
        $data['published_on'] ??= $this->today();
        $data['created_by'] = $actor['id'];
        $data['updated_by'] = $actor['id'];
        $data['created_at'] = $this->clock->now()->format('Y-m-d H:i:s');

        $id = $this->news->create($data);
        $this->audit->log($actor['id'], 'news.created', 'news', $id, $ip, ['slug' => $data['slug']]);

        return $this->show($id);
    }

    /**
     * @param array{id: int}       $actor
     * @param array<string, mixed> $input any subset of the create fields
     * @return array<string, mixed>
     */
    public function update(int $id, array $actor, array $input, string $ip): array
    {
        $this->findOrFail($id);
        // Only the fields that were sent change; a required field that is sent must not be empty.
        $sent = array_keys($input);
        $validated = $this->validate($input, array_values(array_intersect(self::REQUIRED, $sent)));
        $data = array_filter(
            array_intersect_key($validated, array_flip($sent)),
            fn ($value) => $value !== null,
        );
        if (isset($data['is_published'])) {
            $data['is_published'] = (int) $data['is_published'];
        }

        if ($data !== []) {
            $this->news->update($id, $data + ['updated_by' => $actor['id']]);
            $this->audit->log($actor['id'], 'news.updated', 'news', $id, $ip, ['changed' => array_keys($data)]);
        }

        return $this->show($id);
    }

    /** @param array{id: int} $actor */
    public function delete(int $id, array $actor, string $ip): void
    {
        $post = $this->findOrFail($id);
        $this->news->delete($id);
        $this->audit->log($actor['id'], 'news.deleted', 'news', $id, $ip, ['slug' => $post['slug'], 'title' => $post['title']]);
        $this->deleteImageQuietly($post['image_public_id']);
    }

    /**
     * @param array{id: int} $actor
     * @return array<string, mixed>
     */
    public function uploadImage(int $id, array $actor, ?UploadedFile $file, string $ip): array
    {
        $post = $this->findOrFail($id);
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
                $post['slug'] . '-' . bin2hex(random_bytes(4)),
            );
        } catch (InvalidDocumentException) {
            throw new ValidationException(['image' => 'This file is not a valid image.']);
        }

        $this->news->update($id, ['image_public_id' => $stored['public_id'], 'updated_by' => $actor['id']]);
        $this->audit->log($actor['id'], 'news.image_updated', 'news', $id, $ip);
        $this->deleteImageQuietly($post['image_public_id']);

        return $this->show($id);
    }

    /**
     * @param array{id: int} $actor
     * @return array<string, mixed>
     */
    public function removeImage(int $id, array $actor, string $ip): array
    {
        $post = $this->findOrFail($id);
        if ($post['image_public_id'] !== null) {
            $this->news->update($id, ['image_public_id' => null, 'updated_by' => $actor['id']]);
            $this->audit->log($actor['id'], 'news.image_removed', 'news', $id, $ip);
            $this->deleteImageQuietly($post['image_public_id']);
        }

        return $this->show($id);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $required fields that must not be empty
     * @return array<string, mixed>
     */
    private function validate(array $input, array $required): array
    {
        // Normalise line endings and squeeze 3+ newlines, so paragraphs are split consistently.
        if (is_string($input['body'] ?? null)) {
            $input['body'] = (string) preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $input['body']));
        }

        $rules = [
            'title' => 'string|min:5|max:150',
            'excerpt' => 'string|min:10|max:300',
            'body' => 'string|min:20|max:20000',
            'is_published' => 'boolean',
            'published_on' => 'date',
        ];
        foreach ($required as $field) {
            $rules[$field] = 'required|' . $rules[$field];
        }

        return Validator::make($input, $rules, [
            'title.required' => 'Please enter the title.',
            'title' => 'Title must be 5–150 characters.',
            'excerpt.required' => 'Please enter a short summary.',
            'excerpt' => 'Summary must be 10–300 characters.',
            'body.required' => 'Please write the article.',
            'body' => 'Article must be 20–20000 characters.',
            'is_published' => 'Published must be true or false.',
            'published_on' => 'Enter a valid date (YYYY-MM-DD).',
        ])->validate();
    }

    private function today(): string
    {
        return $this->clock->now()->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id): array
    {
        return $this->news->find($id) ?? throw new NotFoundException('Article not found.');
    }

    private function deleteImageQuietly(?string $publicId): void
    {
        if ($publicId === null) {
            return;
        }
        try {
            $this->images->deletePublicImage($publicId);
        } catch (Throwable $e) {
            // The post change already happened; an orphaned photo is harmless.
            $this->logger->warning('News photo could not be deleted from Cloudinary', ['public_id' => $publicId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'published_on' => $row['published_on'],
            'excerpt' => $row['excerpt'],
            'image_public_id' => $row['image_public_id'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function article(array $row): array
    {
        $paragraphs = array_map(
            fn (string $p) => (string) preg_replace('/\s*\n\s*/', ' ', trim($p)),
            preg_split("/\n\s*\n/", trim((string) $row['body'])) ?: [],
        );

        return $this->summary($row) + ['body' => array_values(array_filter($paragraphs, fn ($p) => $p !== ''))];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentAdmin(array $row): array
    {
        $isPublished = (bool) $row['is_published'];

        return $this->article($row) + [
            'body_text' => $row['body'],
            'is_published' => $isPublished,
            'status' => !$isPublished ? 'draft' : ($row['published_on'] > $this->today() ? 'scheduled' : 'published'),
            'created_at' => $this->clock->toIso($row['created_at']),
            'updated_at' => $this->clock->toIso($row['updated_at']),
        ];
    }
}
