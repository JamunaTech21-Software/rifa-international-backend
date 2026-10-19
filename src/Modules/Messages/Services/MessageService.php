<?php

declare(strict_types=1);

namespace App\Modules\Messages\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Modules\Lookups\Services\LookupsService;
use App\Modules\Messages\Repositories\MessageRepository;
use App\Modules\Messages\Validators\MessageValidator;
use App\Shared\Clock;
use App\Shared\Mail\Mailer;

/** Contact Us messages (plan.md §4.3, story BE-10). */
final class MessageService
{
    public function __construct(
        private readonly MessageValidator $validator,
        private readonly MessageRepository $messages,
        private readonly LookupsService $lookups,
        private readonly Mailer $mailer,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, received_at: string}
     */
    public function receive(array $input, string $ip, string $userAgent): array
    {
        $now = $this->clock->now();

        if (trim((string) ($input['website'] ?? '')) !== '') {
            // Honeypot filled → a bot. Pretend success, store nothing.
            $this->logger->info('Message honeypot triggered', ['ip' => $ip]);

            return ['id' => random_int(1000, 999999), 'received_at' => $now->format(DATE_ATOM)];
        }

        $data = $this->validator->validate($input);

        $record = [
            'full_name' => $this->squash($data['full_name']),
            'email' => strtolower($data['email']),
            'phone' => $this->squash($data['phone']),
            'subject' => $data['subject'],
            'message' => trim(str_replace("\r\n", "\n", $data['message'])),
            'status' => 'new',
            'ip_address' => mb_substr($ip, 0, 45),
            'user_agent' => $userAgent,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];

        $id = $this->messages->create($record);
        $this->logger->info('Contact message received', ['id' => $id]);

        $subjectLabel = (string) $this->lookups->label('contact_subjects', $record['subject']);
        $this->mailer->send(
            $this->mailer->officeAddress(),
            "New message: {$subjectLabel} — {$record['full_name']}",
            'message-office',
            [
                'subjectLabel' => $subjectLabel,
                'fullName' => $record['full_name'],
                'email' => $record['email'],
                'phone' => $record['phone'],
                'message' => $record['message'],
                'receivedAt' => $now->format('j F Y, g:i A') . ' (GMT+6)',
                'adminUrl' => rtrim((string) $this->config->get('app.url'), '/') . '/admin/messages/' . $id,
            ],
            $record['email'],
        );

        return ['id' => $id, 'received_at' => $now->format(DATE_ATOM)];
    }

    private function squash(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }
}
