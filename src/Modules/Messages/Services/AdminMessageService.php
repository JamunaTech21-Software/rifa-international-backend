<?php

declare(strict_types=1);

namespace App\Modules\Messages\Services;

use App\Core\Exceptions\NotFoundException;
use App\Core\Validator;
use App\Modules\Lookups\Services\LookupsService;
use App\Modules\Messages\Repositories\AdminMessageRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Clock;
use App\Shared\Mail\Mailer;

/**
 * Admin inbox for Contact Us messages (story BE-13).
 *
 * - The list hides archived messages unless status=archived is requested.
 * - Opening a "new" message marks it "read".
 * - A reply is emailed to the sender and stored; the message becomes "replied" only if the email was sent.
 */
final class AdminMessageService
{
    /** Statuses an admin can set by hand ("replied" is set by sending a reply). */
    private const SETTABLE = ['new', 'read', 'archived'];

    private const STATUS_LABELS = ['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'archived' => 'Archived'];

    public function __construct(
        private readonly AdminMessageRepository $messages,
        private readonly LookupsService $lookups,
        private readonly Mailer $mailer,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(array $query): array
    {
        $filters = Validator::make($query, [
            'q' => 'string|max:100',
            'status' => 'in:' . implode(',', array_keys(self::STATUS_LABELS)),
            'subject' => 'in:' . implode(',', $this->lookups->values('contact_subjects')),
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ])->validate();

        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 20;
        $result = $this->messages->search($filters, $page, $perPage);

        return [
            'data' => array_map(fn (array $row) => $this->summary($row), $result['rows']),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'unread_count' => $this->messages->unreadCount(),
            ],
        ];
    }

    /** @return array<string, mixed> detail; marks a new message as read */
    public function open(int $id): array
    {
        $row = $this->findOrFail($id);
        if ($row['status'] === 'new') {
            $this->messages->setStatus($id, 'read', $this->clock->now()->format('Y-m-d H:i:s'));
        }

        return $this->detail($id);
    }

    /**
     * @param array{id: int}       $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function changeStatus(int $id, array $admin, array $input, string $ip): array
    {
        $row = $this->findOrFail($id);
        $data = Validator::make($input, ['status' => 'required|in:' . implode(',', self::SETTABLE)], [
            'status' => 'Please choose new (unread), read or archived.',
        ])->validate();

        $readAt = match ($data['status']) {
            'new' => null,
            default => $row['read_at'] ?? $this->clock->now()->format('Y-m-d H:i:s'),
        };
        $this->messages->setStatus($id, $data['status'], $readAt);
        $this->audit->log($admin['id'], 'messages.status_changed', 'contact_message', $id, $ip, [
            'from' => $row['status'],
            'to' => $data['status'],
        ]);

        return $this->detail($id);
    }

    /**
     * @param array{id: int, name: string} $admin
     * @param array<string, mixed>         $input
     * @return array<string, mixed> the message detail including the new reply
     */
    public function reply(int $id, array $admin, array $input, string $ip): array
    {
        $row = $this->findOrFail($id);
        $data = Validator::make($input, ['body' => 'required|string|max:5000'], [
            'body.required' => 'Please write a reply.',
            'body' => 'Reply may not be longer than 5000 characters.',
        ])->validate();

        $now = $this->clock->now();
        $subjectLabel = (string) $this->lookups->label('contact_subjects', $row['subject']);
        $body = trim(str_replace("\r\n", "\n", $data['body']));

        $sent = $this->mailer->send(
            $row['email'],
            "Re: {$subjectLabel} — Rifa International",
            'message-reply',
            [
                'fullName' => $row['full_name'],
                'reply' => $body,
                'adminName' => $admin['name'],
                'originalMessage' => $row['message'],
                'originalDate' => (new \DateTimeImmutable($row['created_at'], $this->clock->timezone()))->format('j F Y, g:i A'),
            ],
            $this->mailer->officeAddress(),
        );

        $replyId = $this->messages->addReply([
            'contact_message_id' => $id,
            'admin_id' => $admin['id'],
            'body' => $body,
            'sent_to' => $row['email'],
            'sent_at' => $sent ? $now->format('Y-m-d H:i:s') : null,
            'delivery_status' => $sent ? 'sent' : 'failed',
            'error' => $sent ? null : 'Email could not be sent. Check the mail settings and try again.',
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        if ($sent) {
            $this->messages->setStatus($id, 'replied', $row['read_at'] ?? $now->format('Y-m-d H:i:s'));
        }
        $this->audit->log($admin['id'], 'messages.replied', 'contact_message', $id, $ip, [
            'reply_id' => $replyId,
            'delivered' => $sent,
        ]);

        return $this->detail($id);
    }

    /** @return array<string, mixed> */
    private function detail(int $id): array
    {
        $row = $this->findOrFail($id);

        return $this->summary($row) + [
            'message' => $row['message'],
            'updated_at' => $this->clock->toIso($row['updated_at']),
            'replies' => array_map(fn (array $r) => [
                'id' => (int) $r['id'],
                'body' => $r['body'],
                'admin' => $r['admin_id'] === null ? null : ['id' => (int) $r['admin_id'], 'name' => $r['admin_name']],
                'sent_to' => $r['sent_to'],
                'delivery_status' => $r['delivery_status'],
                'sent_at' => $this->clock->toIso($r['sent_at']),
                'created_at' => $this->clock->toIso($r['created_at']),
            ], $this->messages->replies($id)),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summary(array $row): array
    {
        $text = preg_replace('/\s+/u', ' ', (string) $row['message']) ?? '';

        return [
            'id' => (int) $row['id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'subject' => $row['subject'],
            'subject_label' => $this->lookups->label('contact_subjects', $row['subject']),
            'excerpt' => mb_strlen($text) > 120 ? rtrim(mb_substr($text, 0, 117)) . '…' : $text,
            'status' => $row['status'],
            'status_label' => self::STATUS_LABELS[$row['status']] ?? $row['status'],
            'replies_count' => (int) $row['replies_count'],
            'read_at' => $this->clock->toIso($row['read_at']),
            'created_at' => $this->clock->toIso($row['created_at']),
        ];
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id): array
    {
        return $this->messages->find($id) ?? throw new NotFoundException('Message not found.');
    }
}
