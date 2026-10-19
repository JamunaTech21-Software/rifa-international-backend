<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Security\CsrfTokenManager;

/** Dashboard stats and CSV export (BE-14). */
final class AdminReportsTest extends IntegrationTestCase
{
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-21 15:00:00'); // a Wednesday; week started Sunday 18 Oct
        $this->loginAs($this->createAdmin('staff'));
        $this->app->container()->get(CsrfTokenManager::class)->token();
    }

    /** @param array<string, mixed> $overrides */
    private function application(array $overrides = []): int
    {
        $this->n++;
        $category = $overrides['category'] ?? 'construction';
        unset($overrides['category']);

        return $this->db->insert('applications', $overrides + [
            'reference_no' => sprintf('RIFA-20261021-%05d', $this->n),
            'full_name' => "Applicant {$this->n}",
            'father_name' => 'Father',
            'date_of_birth' => '1995-01-01',
            'age_at_submission' => 31,
            'gender' => 'male',
            'mobile' => '+88017' . str_pad((string) $this->n, 8, '0', STR_PAD_LEFT),
            'present_address' => 'Dhaka',
            'job_category_id' => (int) $this->db->fetchValue('SELECT id FROM job_categories WHERE slug = ?', [$category]),
            'passport_status' => 'have_passport',
            'status' => 'received',
            'declaration_accepted_at' => '2026-10-21 10:00:00',
            'ip_address' => '127.0.0.1',
            'created_at' => '2026-10-21 10:00:00',
        ]);
    }

    private function message(string $status, string $createdAt = '2026-10-21 09:00:00'): int
    {
        return $this->db->insert('contact_messages', [
            'full_name' => 'Sender', 'email' => 's@example.com', 'phone' => '01712345678', 'subject' => 'support',
            'message' => 'Hello there, need help.', 'status' => $status, 'ip_address' => '127.0.0.1', 'created_at' => $createdAt,
        ]);
    }

    public function test_dashboard_stats(): void
    {
        $this->application(['created_at' => '2026-10-21 08:00:00', 'category' => 'hospitality']);          // today
        $this->application(['created_at' => '2026-10-18 00:00:00', 'status' => 'shortlisted']);             // Sunday — this week
        $this->application(['created_at' => '2026-10-17 23:59:59', 'status' => 'shortlisted']);             // Saturday — last week
        $this->application(['created_at' => '2026-10-21 09:00:00', 'deleted_at' => '2026-10-21 09:30:00']); // deleted — ignored
        $this->message('new');
        $this->message('read');
        $this->message('archived');

        $response = $this->request('GET', '/api/v1/admin/dashboard');
        $this->assertSame(200, $response->status(), $response->body());
        $data = $response->decodedJson()['data'];

        $this->assertSame(['today' => 1, 'this_week' => 2, 'total' => 3], $data['applications']);
        $this->assertSame(['unread' => 1, 'open' => 2], $data['messages']);
        $this->assertSame(['status' => 'received', 'label' => 'Received', 'count' => 1], $data['by_status'][0]);
        $this->assertSame(['status' => 'shortlisted', 'label' => 'Shortlisted', 'count' => 2], $data['by_status'][2]);
        $this->assertCount(9, $data['by_status']);
        $this->assertSame(['slug' => 'construction', 'name' => 'Construction', 'count' => 2], $data['by_category'][0]);
        $this->assertSame(['slug' => 'hospitality', 'name' => 'Hospitality', 'count' => 1], $data['by_category'][3]);
        $this->assertSame('Applicant 1', $data['latest_applications'][0]['full_name']);
        $this->assertCount(3, $data['latest_applications']);
        $this->assertCount(2, $data['latest_messages']);
        $this->assertSame('Support', $data['latest_messages'][0]['subject_label']);
    }

    public function test_dashboard_on_a_sunday_counts_from_that_morning(): void
    {
        $this->clock()->freeze('2026-10-18 12:00:00');
        $this->application(['created_at' => '2026-10-18 01:00:00']);
        $this->application(['created_at' => '2026-10-17 12:00:00']);

        $this->assertSame(1, $this->request('GET', '/api/v1/admin/dashboard')->decodedJson()['data']['applications']['this_week']);
    }

    public function test_csv_export_uses_filters_labels_bom_and_blocks_formula_injection(): void
    {
        $this->application(['full_name' => 'Rahim Uddin', 'email' => 'rahim@example.com', 'work_experience' => '1_2', 'is_age_flagged' => 1]);
        $this->application(['full_name' => '=HYPERLINK("http://evil","x")', 'present_address' => '@SUM(A1)', 'category' => 'hospitality']);
        $this->application(['full_name' => 'Hidden', 'deleted_at' => '2026-10-21 11:00:00']);

        $response = $this->request('GET', '/api/v1/admin/applications/export');

        $this->assertSame(200, $response->status());
        $this->assertSame('text/csv; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('attachment; filename="rifa-applications-20261021-1500.csv"', $response->header('Content-Disposition'));

        $body = $response->body();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $rows = array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), explode("\n", trim(substr($body, 3))));
        $this->assertSame('Reference', $rows[0][0]);
        $this->assertCount(3, $rows, 'header + 2 rows; deleted excluded');

        $rahim = $rows[2];
        $this->assertSame(['RIFA-20261021-00001', '2026-10-21 10:00', 'Rahim Uddin'], array_slice($rahim, 0, 3));
        $this->assertSame('+8801700000001', $rahim[8], 'phone numbers are not prefixed');
        $this->assertSame('1–2 years', $rahim[12]);
        $this->assertSame(['Have Passport', 'Received', 'Yes', 'No'], array_slice($rahim, 14));

        $evil = $rows[1];
        $this->assertSame('\'=HYPERLINK("http://evil","x")', $evil[2]);
        $this->assertSame("'@SUM(A1)", $evil[10]);

        $filtered = $this->request('GET', '/api/v1/admin/applications/export?category=hospitality')->body();
        $this->assertSame(2, substr_count(trim($filtered), "\n") + 1);
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'applications.exported'"));
    }
}
