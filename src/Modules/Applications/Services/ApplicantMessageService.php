<?php

declare(strict_types=1);

namespace App\Modules\Applications\Services;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use App\Modules\Applications\Repositories\AdminApplicationRepository;
use App\Modules\Applications\Repositories\ApplicantMessageRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Clock;
use App\Shared\Mail\Mailer;

/**
 * Admin → applicant emails from the application page (P5-01, email only).
 *
 * The admin picks a template (already filled with the applicant's name, reference and category),
 * edits it, and sends. Every attempt is logged on the application — a failed email too, so the admin
 * sees it and can send again. Replies go to the office inbox (Reply-To).
 */
final class ApplicantMessageService
{
    public function __construct(
        private readonly AdminApplicationRepository $applications,
        private readonly ApplicantMessageRepository $messages,
        private readonly Mailer $mailer,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{can_email: bool, templates: list<array{key: string, label: string, subject: string, body: string}>} */
    public function templates(int $applicationId): array
    {
        $application = $this->findOrFail($applicationId);

        return [
            'can_email' => $application['email'] !== null,
            'templates' => ApplicantMessageTemplates::filled([
                'name' => $application['full_name'],
                'reference_no' => $application['reference_no'],
                'job_category' => $application['category_name'],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function log(int $applicationId): array
    {
        $this->findOrFail($applicationId);

        return array_map(fn (array $row) => $this->present($row), $this->messages->forApplication($applicationId));
    }

    /**
     * @param array{id: int, name: string} $admin
     * @param array<string, mixed>         $input template, subject, body
     * @return array<string, mixed> the logged message (delivery_status tells whether the email went out)
     */
    public function send(int $applicationId, array $admin, array $input, string $ip): array
    {
        $application = $this->findOrFail($applicationId);
        if ($application['email'] === null) {
            throw new ValidationException(['email' => 'This applicant did not give an email address. Please call them instead.']);
        }

        $data = Validator::make($input, [
            'template' => 'required|in:' . implode(',', ApplicantMessageTemplates::keys()),
            'subject' => 'required|string|min:3|max:200',
            'body' => 'required|string|min:10|max:5000',
        ], [
            'template.required' => 'Please choose a template.',
            'template' => 'Please choose a valid template.',
            'subject.required' => 'Please enter a subject.',
            'subject' => 'Subject must be 3–200 characters.',
            'body.required' => 'Please write the message.',
            'body' => 'Message must be 10–5000 characters.',
        ])->validate();

        $subject = (string) preg_replace('/\s+/', ' ', $data['subject']); // no header injection via newlines
        $body = trim(str_replace(["\r\n", "\r"], "\n", $data['body']));
        $blanks = [];
        foreach (['subject' => $subject, 'body' => $body] as $field => $text) {
            if (preg_match(ApplicantMessageTemplates::BLANK_PATTERN, $text, $match) === 1) {
                $blanks[$field] = "Replace {$match[0]} with the real details before sending.";
            }
        }
        if ($blanks !== []) {
            throw new ValidationException($blanks);
        }

        $sent = $this->mailer->send(
            $application['email'],
            $subject . ' — Rifa International',
            'applicant-message',
            ['body' => $body, 'adminName' => $admin['name'], 'reference' => $application['reference_no']],
            $this->mailer->officeAddress(),
        );

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $id = $this->messages->create([
            'application_id' => $applicationId,
            'admin_id' => $admin['id'],
            'channel' => 'email',
            'template' => $data['template'],
            'subject' => $subject,
            'body' => $body,
            'sent_to' => $application['email'],
            'delivery_status' => $sent ? 'sent' : 'failed',
            'error' => $sent ? null : 'Email could not be sent. Check the mail settings and try again.',
            'sent_at' => $sent ? $now : null,
            'created_at' => $now,
        ]);
        $this->audit->log($admin['id'], 'applications.message_sent', 'application', $applicationId, $ip, [
            'message_id' => $id,
            'template' => $data['template'],
            'delivered' => $sent,
        ]);

        return $this->present($this->messages->find($id) ?? []);
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id): array
    {
        return $this->applications->find($id) ?? throw new NotFoundException('Application not found.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'channel' => $row['channel'],
            'template' => $row['template'],
            'subject' => $row['subject'],
            'body' => $row['body'],
            'sent_to' => $row['sent_to'],
            'delivery_status' => $row['delivery_status'],
            'error' => $row['error'],
            'admin' => $row['admin_id'] === null ? null : ['id' => (int) $row['admin_id'], 'name' => $row['admin_name']],
            'sent_at' => $this->clock->toIso($row['sent_at']),
            'created_at' => $this->clock->toIso($row['created_at']),
        ];
    }
}
