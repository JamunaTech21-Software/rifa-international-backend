<?php

declare(strict_types=1);

namespace App\Modules\Applications\Services;

/**
 * Ready-made emails an admin can send to an applicant (P5-01).
 *
 * {name}, {reference_no} and {job_category} are filled from the application. Text in [CAPITALS]
 * is for the admin to replace before sending (date, time, place …) — sending is refused while any is left.
 * The greeting signature ("Best regards, <admin>, Rifa International") is added by the email layout.
 */
final class ApplicantMessageTemplates
{
    /** Leftover "fill me in" markers, e.g. [DATE] or [MEDICAL CENTRE]. */
    public const BLANK_PATTERN = '/\[[A-Z][A-Z0-9 ,\/\-]*\]/';

    private const TEMPLATES = [
        'shortlisted' => [
            'label' => 'Shortlisted',
            'subject' => 'Your application {reference_no} has been shortlisted',
            'body' => "Dear {name},\n\nGood news! Your application ({reference_no}) for a {job_category} job in Malaysia has been shortlisted.\n\nOur team will contact you soon about the next steps. Please keep your passport, NID and mobile phone ready.",
        ],
        'interview' => [
            'label' => 'Interview invitation',
            'subject' => 'Interview invitation — {reference_no}',
            'body' => "Dear {name},\n\nYou are invited to an interview for the {job_category} job in Malaysia (application {reference_no}).\n\nDate: [DATE]\nTime: [TIME]\nPlace: [PLACE]\n\nPlease bring your passport, NID, two passport-size photos and your educational certificates (if any). If you cannot attend, please call our office.",
        ],
        'medical' => [
            'label' => 'Medical check-up',
            'subject' => 'Medical check-up — {reference_no}',
            'body' => "Dear {name},\n\nThe next step for your application ({reference_no}) is the medical check-up.\n\nDate: [DATE]\nTime: [TIME]\nMedical centre: [MEDICAL CENTRE]\n\nPlease bring your passport and two passport-size photos, and come without eating for 8 hours before the test.",
        ],
        'documents_required' => [
            'label' => 'Documents required',
            'subject' => 'Documents needed for your application {reference_no}',
            'body' => "Dear {name},\n\nTo continue with your application ({reference_no}), we need the following documents:\n\n[LIST OF DOCUMENTS]\n\nPlease bring them to our office or reply to this email with clear copies.",
        ],
        'custom' => [
            'label' => 'Custom message',
            'subject' => 'About your application {reference_no}',
            'body' => "Dear {name},\n\n[YOUR MESSAGE]",
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * Every template with the applicant's details filled in.
     *
     * @param array{name: string, reference_no: string, job_category: string} $values
     * @return list<array{key: string, label: string, subject: string, body: string}>
     */
    public static function filled(array $values): array
    {
        $replace = [
            '{name}' => $values['name'],
            '{reference_no}' => $values['reference_no'],
            '{job_category}' => $values['job_category'],
        ];

        $result = [];
        foreach (self::TEMPLATES as $key => $template) {
            $result[] = [
                'key' => $key,
                'label' => $template['label'],
                'subject' => strtr($template['subject'], $replace),
                'body' => strtr($template['body'], $replace),
            ];
        }

        return $result;
    }
}
