<?php

declare(strict_types=1);

namespace App\Modules\Applications\Services;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\RateLimitedException;
use App\Core\Validator;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Applications\Validators\ApplicationValidator;
use App\Modules\Lookups\Services\LookupsService;
use App\Shared\Clock;
use App\Shared\Security\RateLimiter;

/**
 * P5-04: an applicant checks their status with reference number + mobile number.
 *
 * Only non-sensitive fields are returned (no name, NID, address, notes). Wrong combinations are
 * counted per IP so reference numbers cannot be guessed: after MAX_MISSES misses the IP waits.
 */
final class StatusLookupService
{
    public const REFERENCE_PATTERN = '/^RIFA-\d{8}-\d{5}$/';
    private const MAX_MISSES = 10;
    private const MISS_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly LookupsService $lookups,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function lookup(array $input, string $ip): array
    {
        $missKey = 'status-miss|' . $ip;
        if ($this->limiter->attempts($missKey, self::MISS_WINDOW_SECONDS) >= self::MAX_MISSES) {
            $wait = $this->limiter->availableIn($missKey, self::MISS_WINDOW_SECONDS);
            throw new RateLimitedException($wait, 'Too many unsuccessful lookups. Please try again in ' . (int) ceil($wait / 60) . ' minutes.');
        }

        $data = $this->validate($input);
        $mobile = '+88' . substr($data['mobile'], -11);
        $application = $this->applications->findForStatusLookup($data['reference_no'], $mobile);

        if ($application === null) {
            $this->limiter->hit($missKey, self::MISS_WINDOW_SECONDS);
            throw new NotFoundException('No application matches this reference number and mobile number. Please check both and try again.');
        }

        $timeline = array_map(fn (array $row) => [
            'status' => $row['to_status'],
            'status_label' => $this->lookups->label('application_statuses', $row['to_status']),
            'changed_at' => $this->clock->toIso($row['created_at']),
        ], $this->applications->statusTimeline((int) $application['id']));

        return [
            'reference_no' => $application['reference_no'],
            'job_category' => $application['job_category'],
            'submitted_at' => $this->clock->toIso($application['created_at']),
            'status' => $application['status'],
            'status_label' => $this->lookups->label('application_statuses', $application['status']),
            'status_updated_at' => $timeline === [] ? $this->clock->toIso($application['created_at']) : end($timeline)['changed_at'],
            'history' => $timeline,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{reference_no: string, mobile: string}
     */
    private function validate(array $input): array
    {
        // Accept "rifa-20261015-00042" and "017 1234-5678" as typed.
        if (is_string($input['reference_no'] ?? null)) {
            $input['reference_no'] = strtoupper(trim($input['reference_no']));
        }
        if (is_string($input['mobile'] ?? null)) {
            $input['mobile'] = str_replace([' ', '-'], '', $input['mobile']);
        }

        return Validator::make($input, [
            'reference_no' => ['required', 'regex:' . self::REFERENCE_PATTERN],
            'mobile' => ['required', 'regex:' . ApplicationValidator::MOBILE_PATTERN],
        ], [
            'reference_no.required' => 'Please enter your reference number.',
            'reference_no' => 'Enter the reference number from your confirmation, e.g. RIFA-20261015-00042.',
            'mobile.required' => 'Please enter your mobile number.',
            'mobile' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).',
        ])->validate();
    }
}
