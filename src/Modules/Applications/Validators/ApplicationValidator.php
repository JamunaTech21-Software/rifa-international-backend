<?php

declare(strict_types=1);

namespace App\Modules\Applications\Validators;

use App\Core\Config;
use App\Core\Validator;
use App\Modules\Lookups\Repositories\JobCategoryRepository;
use App\Modules\Lookups\Services\LookupsService;

/**
 * Rules and user-facing messages for the Apply Now form.
 * Messages must stay identical to backend/docs/api.md and the frontend zod schema.
 */
final class ApplicationValidator
{
    public const NAME_PATTERN = "/^[\\p{L}\\p{M}\\s.'\\-]+$/u";

    public const MOBILE_PATTERN = '/^(?:\+?88)?01[3-9]\d{8}$/';

    public function __construct(
        private readonly LookupsService $lookups,
        private readonly JobCategoryRepository $categories,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $input text fields and UploadedFile values
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $maxKb = (int) $this->config->get('security.upload_max_kb', 5120);
        $file = "file|max_kb:{$maxKb}|mimes:pdf,jpg,png";
        $categorySlugs = implode(',', array_column($this->categories->active(), 'slug'));

        // Allow "017 1234-5678" style input: spaces and dashes are removed before checking.
        if (is_string($input['mobile'] ?? null)) {
            $input['mobile'] = str_replace([' ', '-'], '', $input['mobile']);
        }

        return Validator::make($input, [
            'full_name' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::NAME_PATTERN],
            'father_name' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::NAME_PATTERN],
            'date_of_birth' => 'required|date|before_today',
            'gender' => 'required|in:' . implode(',', $this->lookups->values('genders')),
            'nid_no' => 'digits_in:10,13,17',
            'mobile' => ['required', 'regex:' . self::MOBILE_PATTERN],
            'email' => 'email|max:150',
            'present_address' => 'required|string|min:5|max:255',
            'job_category' => 'required|in:' . $categorySlugs,
            'work_experience' => 'in:' . implode(',', $this->lookups->values('work_experience')),
            'education_level' => 'in:' . implode(',', $this->lookups->values('education_levels')),
            'passport_status' => 'required|in:' . implode(',', $this->lookups->values('passport_statuses')),
            'cv' => $file,
            'passport_copy' => 'required_if:passport_status,have_passport|' . $file,
            'declaration' => 'accepted',
        ], [
            'full_name.required' => 'Please enter your full name.',
            'full_name' => 'Full name must be 2–100 letters.',
            'father_name.required' => "Please enter your father's name.",
            'father_name' => "Father's name must be 2–100 letters.",
            'date_of_birth.required' => 'Please enter your date of birth.',
            'date_of_birth.date' => 'Enter a valid date (YYYY-MM-DD).',
            'date_of_birth.before_today' => 'Date of birth must be in the past.',
            'gender' => 'Please select your gender.',
            'nid_no' => 'NID number must be 10, 13 or 17 digits.',
            'mobile.required' => 'Please enter your mobile number.',
            'mobile' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).',
            'email' => 'Enter a valid email address.',
            'present_address.required' => 'Please enter your present address.',
            'present_address' => 'Present address must be 5–255 characters.',
            'job_category.required' => 'Please select a job category.',
            'job_category' => 'Please select a valid job category.',
            'work_experience' => 'Please select a valid option.',
            'education_level' => 'Please select a valid option.',
            'passport_status' => 'Please select your passport status.',
            'cv.max_kb' => 'CV must be 5 MB or smaller.',
            'cv.file' => 'Please upload a valid CV file.',
            'cv' => 'CV must be a PDF, JPG or PNG file.',
            'passport_copy.required_if' => 'Please upload your passport copy.',
            'passport_copy.max_kb' => 'Passport copy must be 5 MB or smaller.',
            'passport_copy.file' => 'Please upload a valid passport copy file.',
            'passport_copy' => 'Passport copy must be a PDF, JPG or PNG file.',
            'declaration' => 'Please confirm that the information is true and correct.',
        ])->validate();
    }
}
