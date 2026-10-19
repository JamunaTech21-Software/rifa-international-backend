<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;

final class AdminApplicationsTest extends IntegrationTestCase
{
    private const BASE = '/api/v1/admin/applications';

    private int $superAdmin;

    private int $staff;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-20 12:00:00');
        $this->superAdmin = $this->createAdmin('super_admin');
        $this->staff = $this->createAdmin('staff');
    }

    /** @param array<string, mixed> $overrides */
    private function application(array $overrides = []): int
    {
        $this->counter++;
        $category = $overrides['category'] ?? 'construction';
        unset($overrides['category']);

        return $this->db->insert('applications', $overrides + [
            'reference_no' => sprintf('RIFA-20261020-%05d', $this->counter),
            'full_name' => "Applicant {$this->counter}",
            'father_name' => 'Father',
            'date_of_birth' => '1995-01-01',
            'age_at_submission' => 31,
            'gender' => 'male',
            'nid_no' => '123456789' . $this->counter,
            'mobile' => '+88017' . str_pad((string) $this->counter, 8, '0', STR_PAD_LEFT),
            'email' => null,
            'present_address' => 'Dhaka',
            'job_category_id' => (int) $this->db->fetchValue('SELECT id FROM job_categories WHERE slug = ?', [$category]),
            'passport_status' => 'have_passport',
            'status' => 'received',
            'declaration_accepted_at' => '2026-10-20 10:00:00',
            'ip_address' => '127.0.0.1',
            'created_at' => '2026-10-20 10:00:00',
        ]);
    }

    private function as(int $adminId): void
    {
        $this->loginAs($adminId);
        $this->app->container()->get(\App\Shared\Security\CsrfTokenManager::class)->token();
    }

    /** @return array<string, string> */
    private function csrf(): array
    {
        return ['X-CSRF-Token' => (string) $this->session()->get('csrf_token')];
    }

    private function get(string $path): Response
    {
        return $this->request('GET', self::BASE . $path);
    }

    public function test_requires_login(): void
    {
        $this->assertSame(401, $this->get('')->status());
    }

    public function test_list_filters_search_sort_and_pagination(): void
    {
        $this->as($this->staff);
        $a = $this->application(['full_name' => 'Karim Mia', 'category' => 'hospitality', 'created_at' => '2026-10-01 09:00:00']);
        $b = $this->application(['full_name' => 'Rahim Uddin', 'status' => 'shortlisted', 'is_age_flagged' => 1, 'created_at' => '2026-10-10 09:00:00']);
        $c = $this->application(['full_name' => 'Zara Begum', 'gender' => 'female', 'passport_status' => 'will_apply', 'is_duplicate_flagged' => 1, 'created_at' => '2026-10-15 09:00:00']);
        $deleted = $this->application(['full_name' => 'Deleted Person', 'deleted_at' => '2026-10-16 00:00:00']);

        $all = $this->get('')->decodedJson();
        $this->assertSame([$c, $b, $a], array_column($all['data'], 'id'), 'newest first, deleted hidden');
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 3], $all['meta']);
        $this->assertSame([
            'id' => $c, 'reference_no' => 'RIFA-20261020-00003', 'full_name' => 'Zara Begum', 'mobile' => '+8801700000003',
            'email' => null, 'category' => ['slug' => 'construction', 'name' => 'Construction'], 'passport_status' => 'will_apply',
            'status' => 'received', 'is_age_flagged' => false, 'is_duplicate_flagged' => true, 'created_at' => '2026-10-15T09:00:00+06:00',
        ], $all['data'][0]);

        $ids = fn (string $query) => array_column($this->get($query)->decodedJson()['data'], 'id');
        $this->assertSame([$a], $ids('?q=karim'));
        $this->assertSame([$b], $ids('?q=RIFA-20261020-00002'));
        $this->assertSame([$b], $ids('?q=01700000002'));
        $this->assertSame([$c], $ids('?q=1234567893'));
        $this->assertSame([$b], $ids('?status=shortlisted'));
        $this->assertSame([$a], $ids('?category=hospitality'));
        $this->assertSame([$c], $ids('?gender=female'));
        $this->assertSame([$c], $ids('?passport_status=will_apply'));
        $this->assertSame([$b], $ids('?flag=age'));
        $this->assertSame([$c], $ids('?flag=duplicate'));
        $this->assertSame([$b, $a], $ids('?to=2026-10-10'));
        $this->assertSame([$c, $b], $ids('?from=2026-10-10'));
        $this->assertSame([$a, $b, $c], $ids('?sort=full_name'));
        $this->assertSame([], $ids('?q=Deleted'));
        $this->assertSame([], $ids('?q=100%25'), 'LIKE wildcards are escaped');

        $page2 = $this->get('?per_page=2&page=2')->decodedJson();
        $this->assertSame([$a], array_column($page2['data'], 'id'));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 3], $page2['meta']);

        $bad = $this->get('?status=flying&per_page=500');
        $this->assertSame(422, $bad->status());
        $this->assertSame(['status', 'per_page'], array_keys($bad->decodedJson()['error']['fields']));
    }

    public function test_detail_includes_documents_notes_history_and_allowed_statuses(): void
    {
        $this->as($this->staff);
        $id = $this->application(['work_experience' => '1_2', 'education_level' => 'ssc']);
        $this->db->insert('application_documents', [
            'application_id' => $id, 'doc_type' => 'passport_copy', 'cloudinary_public_id' => 'rifa/applications/x/passport_copy',
            'resource_type' => 'image', 'format' => 'pdf', 'bytes' => 1234, 'original_filename' => 'passport.pdf',
            'created_at' => '2026-10-20 10:00:00',
        ]);
        $this->db->insert('application_status_history', ['application_id' => $id, 'to_status' => 'received', 'created_at' => '2026-10-20 10:00:00']);

        $data = $this->get("/{$id}")->decodedJson()['data'];

        $this->assertSame('Father', $data['father_name']);
        $this->assertSame('1995-01-01', $data['date_of_birth']);
        $this->assertSame(['gender' => 'Male', 'work_experience' => '1–2 years', 'education_level' => 'SSC/Dakhil', 'passport_status' => 'Have Passport', 'status' => 'Received'], $data['labels']);
        $this->assertSame(['under_review', 'shortlisted', 'interview', 'medical', 'visa_processing', 'departed', 'on_hold', 'rejected'], $data['allowed_next_statuses']);
        $this->assertSame('passport_copy', $data['documents'][0]['doc_type']);
        $this->assertArrayNotHasKey('cloudinary_public_id', $data['documents'][0]);
        $this->assertSame([['id' => $data['history'][0]['id'], 'from_status' => null, 'to_status' => 'received', 'note' => null, 'changed_by' => null, 'created_at' => '2026-10-20T10:00:00+06:00']], $data['history']);
        $this->assertSame([], $data['notes']);

        $this->assertSame(404, $this->get('/999999')->status());
        $this->assertSame(404, $this->get('/' . $this->application(['deleted_at' => '2026-10-20 00:00:00']))->status());
    }

    public function test_change_status_records_history_and_audit(): void
    {
        $this->as($this->staff);
        $id = $this->application();

        $noCsrf = $this->request('PATCH', self::BASE . "/{$id}/status", ['status' => 'shortlisted']);
        $this->assertSame(419, $noCsrf->status());

        $response = $this->request('PATCH', self::BASE . "/{$id}/status", ['status' => 'shortlisted', 'note' => 'Good candidate'], $this->csrf());
        $this->assertSame(200, $response->status(), $response->body());
        $data = $response->decodedJson()['data'];
        $this->assertSame('shortlisted', $data['status']);
        $this->assertSame('received', $data['history'][0]['from_status']);
        $this->assertSame('shortlisted', $data['history'][0]['to_status']);
        $this->assertSame('Good candidate', $data['history'][0]['note']);
        $this->assertSame($this->staff, $data['history'][0]['changed_by']['id']);

        $audit = $this->db->fetchOne("SELECT admin_id, meta FROM audit_logs WHERE action = 'applications.status_changed'");
        $this->assertSame($this->staff, $audit['admin_id']);
        $this->assertEquals(['from' => 'received', 'to' => 'shortlisted'], json_decode($audit['meta'], true));

        $back = $this->request('PATCH', self::BASE . "/{$id}/status", ['status' => 'received'], $this->csrf());
        $this->assertSame(['status' => 'Cannot change status from Shortlisted to Received.'], $back->decodedJson()['error']['fields']);

        $invalid = $this->request('PATCH', self::BASE . "/{$id}/status", ['status' => 'teleported'], $this->csrf());
        $this->assertSame(['status' => 'Please select a valid status.'], $invalid->decodedJson()['error']['fields']);
    }

    public function test_only_super_admin_can_reopen_final_status(): void
    {
        $id = $this->application(['status' => 'rejected']);

        $this->as($this->staff);
        $this->assertSame(422, $this->request('PATCH', self::BASE . "/{$id}/status", ['status' => 'under_review'], $this->csrf())->status());
        $this->assertSame([], $this->get("/{$id}")->decodedJson()['data']['allowed_next_statuses']);

        $this->as($this->superAdmin);
        $this->assertSame(200, $this->request('PATCH', self::BASE . "/{$id}/status", ['status' => 'under_review'], $this->csrf())->status());
    }

    public function test_notes(): void
    {
        $this->as($this->staff);
        $id = $this->application();

        $response = $this->request('POST', self::BASE . "/{$id}/notes", ['body' => 'Called, will send passport tomorrow.'], $this->csrf());
        $this->assertSame(201, $response->status(), $response->body());
        $note = $response->decodedJson()['data'];
        $this->assertSame('Called, will send passport tomorrow.', $note['body']);
        $this->assertSame($this->staff, $note['admin']['id']);
        $this->assertSame('2026-10-20T12:00:00+06:00', $note['created_at']);

        $this->assertSame([$note], $this->get("/{$id}")->decodedJson()['data']['notes']);

        $empty = $this->request('POST', self::BASE . "/{$id}/notes", ['body' => '  '], $this->csrf());
        $this->assertSame(['body' => 'Please write a note.'], $empty->decodedJson()['error']['fields']);
    }

    public function test_document_url_is_temporary_and_audited(): void
    {
        $this->as($this->staff);
        $id = $this->application();
        $docId = $this->db->insert('application_documents', [
            'application_id' => $id, 'doc_type' => 'cv', 'cloudinary_public_id' => 'rifa/applications/x/cv',
            'resource_type' => 'image', 'format' => 'pdf', 'bytes' => 10, 'original_filename' => 'cv.pdf',
        ]);
        $otherApp = $this->application();

        $data = $this->get("/{$id}/documents/{$docId}/url")->decodedJson()['data'];

        $this->assertSame('https://fake.local/rifa/applications/x/cv.pdf?ttl=300', $data['url']);
        $this->assertStringEndsWith('+06:00', $data['expires_at']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'applications.document_viewed'"));
        $this->assertSame(404, $this->get("/{$otherApp}/documents/{$docId}/url")->status(), 'document must belong to the application');
    }

    public function test_delete_is_super_admin_only_and_soft(): void
    {
        $id = $this->application();

        $this->as($this->staff);
        $this->assertSame(403, $this->request('DELETE', self::BASE . "/{$id}", [], $this->csrf())->status());

        $this->as($this->superAdmin);
        $this->assertSame(204, $this->request('DELETE', self::BASE . "/{$id}", [], $this->csrf())->status());
        $this->assertNotNull($this->db->fetchValue('SELECT deleted_at FROM applications WHERE id = ?', [$id]));
        $this->assertSame(404, $this->get("/{$id}")->status());
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'applications.deleted'"));
    }
}
