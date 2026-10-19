<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Logger;
use App\Shared\Mail\Mailer;
use App\Shared\Security\CsrfTokenManager;

final class AdminMessagesTest extends IntegrationTestCase
{
    private const BASE = '/api/v1/admin/messages';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-20 12:00:00');
        $this->admin = $this->db->insert('admins', [
            'name' => 'Nusrat Staff', 'email' => 'staff@rifaint.com', 'password_hash' => 'x', 'role' => 'staff',
        ]);
        $this->loginAs($this->admin);
        $this->app->container()->get(CsrfTokenManager::class)->token();
    }

    /** @param array<string, mixed> $overrides */
    private function message(array $overrides = []): int
    {
        return $this->db->insert('contact_messages', $overrides + [
            'full_name' => 'Md. Rahim Uddin',
            'email' => 'rahim@example.com',
            'phone' => '01712345678',
            'subject' => 'general_inquiry',
            'message' => "Hello,\nI want to know about construction jobs in Johor.",
            'status' => 'new',
            'ip_address' => '127.0.0.1',
            'created_at' => '2026-10-19 09:00:00',
        ]);
    }

    /** @return array<string, string> */
    private function csrf(): array
    {
        return ['X-CSRF-Token' => (string) $this->session()->get('csrf_token')];
    }

    public function test_requires_login_and_csrf_for_writes(): void
    {
        $id = $this->message();
        $this->assertSame(419, $this->request('PATCH', self::BASE . "/{$id}", ['status' => 'archived'])->status());

        $this->session()->destroy();
        $this->assertSame(401, $this->request('GET', self::BASE)->status());
    }

    public function test_list_hides_archived_by_default_and_reports_unread_count(): void
    {
        $new = $this->message(['created_at' => '2026-10-19 09:00:00']);
        $read = $this->message(['status' => 'read', 'full_name' => 'Karim Mia', 'subject' => 'employer_partnership', 'created_at' => '2026-10-19 10:00:00']);
        $archived = $this->message(['status' => 'archived', 'created_at' => '2026-10-19 11:00:00']);
        $this->message(['status' => 'new', 'message' => str_repeat('Long message text. ', 20), 'created_at' => '2026-10-18 08:00:00']);

        $response = $this->request('GET', self::BASE)->decodedJson();
        $this->assertSame([$read, $new], array_slice(array_column($response['data'], 'id'), 0, 2));
        $this->assertNotContains($archived, array_column($response['data'], 'id'));
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 3, 'unread_count' => 2], $response['meta']);

        $first = $response['data'][0];
        $this->assertSame('Employer Partnership', $first['subject_label']);
        $this->assertSame('Read', $first['status_label']);
        $this->assertSame('Hello, I want to know about construction jobs in Johor.', $response['data'][1]['excerpt']);
        $this->assertSame(118, mb_strlen($response['data'][2]['excerpt']));
        $this->assertStringEndsWith('…', $response['data'][2]['excerpt']);

        $ids = fn (string $q) => array_column($this->request('GET', self::BASE . $q)->decodedJson()['data'], 'id');
        $this->assertSame([$archived], $ids('?status=archived'));
        $this->assertSame([$read], $ids('?subject=employer_partnership'));
        $this->assertSame([$read], $ids('?q=karim'));
        $this->assertSame(422, $this->request('GET', self::BASE . '?status=spam')->status());
    }

    public function test_opening_a_new_message_marks_it_read(): void
    {
        $id = $this->message();

        $data = $this->request('GET', self::BASE . "/{$id}")->decodedJson()['data'];

        $this->assertSame('read', $data['status']);
        $this->assertSame('2026-10-20T12:00:00+06:00', $data['read_at']);
        $this->assertSame("Hello,\nI want to know about construction jobs in Johor.", $data['message']);
        $this->assertSame([], $data['replies']);
        $this->assertSame(404, $this->request('GET', self::BASE . '/999999')->status());
    }

    public function test_mark_unread_and_archive(): void
    {
        $id = $this->message(['status' => 'read', 'read_at' => '2026-10-19 10:00:00']);

        $unread = $this->request('PATCH', self::BASE . "/{$id}", ['status' => 'new'], $this->csrf())->decodedJson()['data'];
        $this->assertSame('new', $unread['status']);
        $this->assertNull($unread['read_at']);

        $archived = $this->request('PATCH', self::BASE . "/{$id}", ['status' => 'archived'], $this->csrf())->decodedJson()['data'];
        $this->assertSame('archived', $archived['status']);

        $bad = $this->request('PATCH', self::BASE . "/{$id}", ['status' => 'replied'], $this->csrf());
        $this->assertSame(['status' => 'Please choose new (unread), read or archived.'], $bad->decodedJson()['error']['fields']);
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'messages.status_changed'"));
    }

    public function test_reply_emails_the_sender_and_stores_the_thread(): void
    {
        $id = $this->message();

        $response = $this->request('POST', self::BASE . "/{$id}/replies", ['body' => "Dear sir,\r\nPlease visit our office on Sunday."], $this->csrf());

        $this->assertSame(201, $response->status(), $response->body());
        $data = $response->decodedJson()['data'];
        $this->assertSame('replied', $data['status']);
        $this->assertSame(1, $data['replies_count']);
        $this->assertSame([
            'id' => $data['replies'][0]['id'],
            'body' => "Dear sir,\nPlease visit our office on Sunday.",
            'admin' => ['id' => $this->admin, 'name' => 'Nusrat Staff'],
            'sent_to' => 'rahim@example.com',
            'delivery_status' => 'sent',
            'sent_at' => '2026-10-20T12:00:00+06:00',
            'created_at' => '2026-10-20T12:00:00+06:00',
        ], $data['replies'][0]);

        $mail = $this->mailer()->outbox()[0];
        $this->assertSame(['rahim@example.com'], $mail['to']);
        $this->assertSame('info@rifaint.com', $mail['reply_to']);
        $this->assertSame('Re: General Inquiry — Rifa International', $mail['subject']);
        $this->assertStringContainsString('Please visit our office on Sunday.', $mail['html']);
        $this->assertStringContainsString('Nusrat Staff', $mail['html']);
        $this->assertStringContainsString('On 19 October 2026, 9:00 AM you wrote:', $mail['html']);

        $empty = $this->request('POST', self::BASE . "/{$id}/replies", ['body' => ''], $this->csrf());
        $this->assertSame(['body' => 'Please write a reply.'], $empty->decodedJson()['error']['fields']);
    }

    public function test_failed_reply_is_stored_as_failed_and_status_unchanged(): void
    {
        $id = $this->message(['status' => 'read']);
        $config = $this->app->container()->get(Config::class);
        $this->app->container()->instance(Mailer::class, new Mailer(
            ['driver' => 'smtp', 'host' => ''] + $config->get('mail'),
            dirname(__DIR__, 2) . '/templates/emails',
            $this->app->container()->get(Logger::class),
        ));

        $data = $this->request('POST', self::BASE . "/{$id}/replies", ['body' => 'Hello'], $this->csrf())->decodedJson()['data'];

        $this->assertSame('read', $data['status']);
        $this->assertSame('failed', $data['replies'][0]['delivery_status']);
        $this->assertNull($data['replies'][0]['sent_at']);
    }
}
