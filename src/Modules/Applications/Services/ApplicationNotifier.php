<?php

declare(strict_types=1);

namespace App\Modules\Applications\Services;

use App\Core\Config;
use App\Modules\Lookups\Services\LookupsService;
use App\Shared\Mail\Mailer;
use DateTimeImmutable;

/**
 * Emails sent after an application is stored (story BE-09):
 *  - confirmation to the applicant (only when an email address was given)
 *  - notification to the office inbox (MAIL_OFFICE_TO)
 * Mail failures never fail the submission.
 */
final class ApplicationNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly LookupsService $lookups,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $application stored row values + category_name
     * @return bool whether the applicant confirmation was sent
     */
    public function submitted(int $applicationId, array $application, DateTimeImmutable $submittedAt): bool
    {
        $common = [
            'reference' => $application['reference_no'],
            'fullName' => $application['full_name'],
            'submittedDate' => $submittedAt->format('j F Y'),
            'submittedTime' => $submittedAt->format('g:i A') . ' (GMT+6)',
            'category' => $application['category_name'],
        ];

        $confirmationSent = false;
        if (!empty($application['email'])) {
            $confirmationSent = $this->mailer->send(
                $application['email'],
                "Application received — {$application['reference_no']}",
                'application-confirmation',
                $common,
            );
        }

        $this->mailer->send(
            $this->mailer->officeAddress(),
            "New application {$application['reference_no']} — {$application['category_name']}",
            'application-office',
            $common + [
                'mobile' => $application['mobile'],
                'email' => $application['email'],
                'age' => $application['age_at_submission'],
                'gender' => $this->lookups->label('genders', $application['gender']),
                'passportStatus' => $this->lookups->label('passport_statuses', $application['passport_status']),
                'experience' => $this->lookups->label('work_experience', $application['work_experience']),
                'education' => $this->lookups->label('education_levels', $application['education_level']),
                'ageFlagged' => (bool) $application['is_age_flagged'],
                'duplicateFlagged' => (bool) $application['is_duplicate_flagged'],
                'adminUrl' => rtrim((string) $this->config->get('app.url'), '/') . '/admin/applications/' . $applicationId,
            ],
            $application['email'] ?: null,
        );

        return $confirmationSent;
    }
}
