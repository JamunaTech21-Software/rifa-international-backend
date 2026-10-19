<?php

declare(strict_types=1);

namespace App\Modules\Applications\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\UploadedFile;
use App\Core\Logger;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Applications\Validators\ApplicationValidator;
use App\Modules\Lookups\Repositories\JobCategoryRepository;
use App\Shared\Clock;
use App\Shared\Storage\DocumentStorage;
use App\Shared\Storage\InvalidDocumentException;
use DateTimeImmutable;
use Throwable;

/**
 * Public job application submission (plan.md §4.1, story BE-08).
 *
 * Order: honeypot → validate → normalise → age/duplicate checks → reference number →
 * upload documents → one DB transaction → notify. Uploaded files are deleted again if
 * anything after the upload fails.
 */
final class ApplicationService
{
    private const MIN_AGE = 18;

    private const FLAG_AGE_ABOVE = 45;

    private const DUPLICATE_DAYS = 30;

    private const DOCUMENTS = [
        'passport_copy' => 'Passport copy',
        'cv' => 'CV',
    ];

    public function __construct(
        private readonly ApplicationValidator $validator,
        private readonly ApplicationRepository $applications,
        private readonly JobCategoryRepository $categories,
        private readonly DocumentStorage $storage,
        private readonly ApplicationNotifier $notifier,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed>        $input
     * @param array<string, UploadedFile> $files
     * @return array{reference_no: string, submitted_at: string, confirmation_email_sent: bool}
     */
    public function submit(array $input, array $files, string $ip, string $userAgent): array
    {
        $now = $this->clock->now();

        if (trim((string) ($input['website'] ?? '')) !== '') {
            // Honeypot filled → a bot. Pretend success, store nothing.
            $this->logger->info('Application honeypot triggered', ['ip' => $ip]);

            return $this->response(sprintf('RIFA-%s-%05d', $now->format('Ymd'), random_int(1, 99999)), $now, false);
        }

        $data = $this->validator->validate($input + $files);

        $age = $this->ageOn($data['date_of_birth'], $now);
        if ($age < self::MIN_AGE) {
            throw new ValidationException(['date_of_birth' => 'You must be at least 18 years old.']);
        }

        $mobile = '+88' . substr((string) $data['mobile'], -11);
        $category = $this->categories->findActiveBySlug($data['job_category']);
        $since = $now->modify('-' . self::DUPLICATE_DAYS . ' days')->format('Y-m-d H:i:s');

        $record = [
            'full_name' => $this->squash($data['full_name']),
            'father_name' => $this->squash($data['father_name']),
            'date_of_birth' => $data['date_of_birth'],
            'age_at_submission' => $age,
            'gender' => $data['gender'],
            'nid_no' => $data['nid_no'],
            'mobile' => $mobile,
            'email' => $data['email'] !== null ? strtolower($data['email']) : null,
            'present_address' => $this->squash($data['present_address']),
            'job_category_id' => $category['id'],
            'work_experience' => $data['work_experience'],
            'education_level' => $data['education_level'],
            'passport_status' => $data['passport_status'],
            'status' => 'received',
            'is_age_flagged' => $age > self::FLAG_AGE_ABOVE,
            'is_duplicate_flagged' => $this->applications->hasRecentDuplicate($mobile, $data['nid_no'], $since),
            'declaration_accepted_at' => $now->format('Y-m-d H:i:s'),
            'ip_address' => mb_substr($ip, 0, 45),
            'user_agent' => $userAgent,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];

        $reference = sprintf(
            'RIFA-%s-%05d',
            $now->format('Ymd'),
            $this->applications->nextReferenceSequence($now->format('Y-m-d')),
        );
        $record['reference_no'] = $reference;

        $uploaded = $this->uploadDocuments($data, $reference);

        try {
            $applicationId = $this->db->transaction(function () use ($record, $uploaded): int {
                $id = $this->applications->create($record);
                foreach ($uploaded as $docType => [$stored, $originalName]) {
                    $this->applications->addDocument($id, $docType, $stored, $originalName);
                }
                $this->applications->addStatusHistory($id, null, 'received');

                return $id;
            });
        } catch (Throwable $e) {
            $this->deleteUploaded($uploaded);
            throw $e;
        }

        $this->logger->info('Application submitted', ['reference' => $reference, 'id' => $applicationId]);

        $emailSent = $this->notifier->submitted($applicationId, $record + ['category_name' => $category['name']], $now);

        return $this->response($reference, $now, $emailSent);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, array{0: array{public_id: string, resource_type: string, format: string, bytes: int}, 1: string}>
     */
    private function uploadDocuments(array $data, string $reference): array
    {
        $folder = rtrim((string) $this->config->get('cloudinary.applications_folder', 'rifa/applications'), '/') . '/' . $reference;
        $uploaded = [];

        foreach (self::DOCUMENTS as $field => $label) {
            $file = $data[$field] ?? null;
            if (!$file instanceof UploadedFile) {
                continue;
            }

            try {
                $uploaded[$field] = [$this->storage->uploadPrivate($file->path(), $folder, $field), $file->clientName()];
            } catch (InvalidDocumentException) {
                $this->deleteUploaded($uploaded);
                throw new ValidationException([
                    $field => "{$label} could not be read. Please upload a clear PDF, JPG or PNG file.",
                ]);
            } catch (Throwable $e) {
                $this->deleteUploaded($uploaded);
                throw $e;
            }
        }

        return $uploaded;
    }

    /** @param array<string, array{0: array{public_id: string, resource_type: string}, 1: string}> $uploaded */
    private function deleteUploaded(array $uploaded): void
    {
        foreach ($uploaded as [$stored]) {
            try {
                $this->storage->delete($stored['public_id'], $stored['resource_type']);
            } catch (Throwable $e) {
                $this->logger->error('Could not delete uploaded document after failure', [
                    'public_id' => $stored['public_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function ageOn(string $dateOfBirth, DateTimeImmutable $now): int
    {
        return (new DateTimeImmutable($dateOfBirth, $now->getTimezone()))->diff($now)->y;
    }

    private function squash(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }

    /** @return array{reference_no: string, submitted_at: string, confirmation_email_sent: bool} */
    private function response(string $reference, DateTimeImmutable $at, bool $emailSent): array
    {
        return [
            'reference_no' => $reference,
            'submitted_at' => $at->format(DATE_ATOM),
            'confirmation_email_sent' => $emailSent,
        ];
    }
}
