<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Security\CaptchaVerifier;
use Tests\Support\FakeCaptchaVerifier;

/** P5-04: POST /applications/status — reference number + mobile → current status. */
final class StatusLookupTest extends IntegrationTestCase
{
    private const URL = '/api/v1/applications/status';

    private int $applicationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-20 12:00:00');
        $this->applicationId = $this->db->insert('applications', [
            'reference_no' => 'RIFA-20261015-00042',
            'full_name' => 'Md. Karim',
            'father_name' => 'Father',
            'date_of_birth' => '1995-01-01',
            'age_at_submission' => 31,
            'gender' => 'male',
            'nid_no' => '1234567890',
            'mobile' => '+8801712345678',
            'present_address' => 'Dhaka',
            'job_category_id' => (int) $this->db->fetchValue('SELECT id FROM job_categories WHERE slug = ?', ['construction']),
            'passport_status' => 'have_passport',
            'status' => 'shortlisted',
            'declaration_accepted_at' => '2026-10-15 09:30:00',
            'ip_address' => '127.0.0.1',
            'created_at' => '2026-10-15 09:30:00',
        ]);
        foreach ([['received', null, '2026-10-15 09:30:00'], ['under_review', 'Internal: checked NID', '2026-10-16 11:00:00'], ['shortlisted', 'Internal: good fit', '2026-10-18 15:45:00']] as [$status, $note, $at]) {
            $this->db->insert('application_status_history', [
                'application_id' => $this->applicationId, 'to_status' => $status, 'note' => $note, 'created_at' => $at,
            ]);
        }
    }

    public function test_matching_reference_and_mobile_return_status_and_timeline_only(): void
    {
        // Typed the way people type it: lower case, spaces, dashes, +88 prefix.
        $response = $this->request('POST', self::URL, ['reference_no' => ' rifa-20261015-00042 ', 'mobile' => '+88 017-1234-5678']);

        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame([
            'reference_no' => 'RIFA-20261015-00042',
            'job_category' => (string) $this->db->fetchValue('SELECT name FROM job_categories WHERE slug = ?', ['construction']),
            'submitted_at' => '2026-10-15T09:30:00+06:00',
            'status' => 'shortlisted',
            'status_label' => 'Shortlisted',
            'status_updated_at' => '2026-10-18T15:45:00+06:00',
            'history' => [
                ['status' => 'received', 'status_label' => 'Received', 'changed_at' => '2026-10-15T09:30:00+06:00'],
                ['status' => 'under_review', 'status_label' => 'Under Review', 'changed_at' => '2026-10-16T11:00:00+06:00'],
                ['status' => 'shortlisted', 'status_label' => 'Shortlisted', 'changed_at' => '2026-10-18T15:45:00+06:00'],
            ],
        ], $response->decodedJson()['data']);
        $this->assertStringNotContainsString('Karim', $response->body());
        $this->assertStringNotContainsString('Internal', $response->body());
    }

    public function test_wrong_mobile_wrong_reference_and_deleted_application_all_give_the_same_404(): void
    {
        $this->db->execute('UPDATE applications SET deleted_at = ? WHERE id = ?', ['2026-10-19 10:00:00', $this->applicationId]);

        foreach ([
            ['reference_no' => 'RIFA-20261015-00042', 'mobile' => '01712345678'],   // deleted
            ['reference_no' => 'RIFA-20261015-00043', 'mobile' => '01712345678'],
            ['reference_no' => 'RIFA-20261015-00042', 'mobile' => '01812345678'],
        ] as $body) {
            $response = $this->request('POST', self::URL, $body);

            $this->assertSame(404, $response->status());
            $this->assertSame(
                'No application matches this reference number and mobile number. Please check both and try again.',
                $response->decodedJson()['error']['message'],
            );
        }
    }

    public function test_invalid_input_gets_field_messages(): void
    {
        $empty = $this->request('POST', self::URL, ['reference_no' => '', 'mobile' => '']);
        $this->assertSame([
            'reference_no' => 'Please enter your reference number.',
            'mobile' => 'Please enter your mobile number.',
        ], $empty->decodedJson()['error']['fields']);

        $bad = $this->request('POST', self::URL, ['reference_no' => '42', 'mobile' => '12345']);
        $this->assertSame([
            'reference_no' => 'Enter the reference number from your confirmation, e.g. RIFA-20261015-00042.',
            'mobile' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).',
        ], $bad->decodedJson()['error']['fields']);
    }

    public function test_ten_misses_block_the_ip_for_15_minutes_but_not_other_ips(): void
    {
        $miss = ['reference_no' => 'RIFA-20261015-00099', 'mobile' => '01712345678'];
        $hit = ['reference_no' => 'RIFA-20261015-00042', 'mobile' => '01712345678'];
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(404, $this->request('POST', self::URL, $miss, [], [], '198.51.100.1')->status());
        }

        $blocked = $this->request('POST', self::URL, $hit, [], [], '198.51.100.1');
        $this->assertSame(429, $blocked->status());
        $this->assertSame('Too many unsuccessful lookups. Please try again in 15 minutes.', $blocked->decodedJson()['error']['message']);
        $this->assertSame(200, $this->request('POST', self::URL, $hit, [], [], '198.51.100.2')->status());

        $this->clock()->freeze('2026-10-20 12:15:01');
        $this->assertSame(200, $this->request('POST', self::URL, $hit, [], [], '198.51.100.1')->status());
    }

    public function test_captcha_is_checked_when_enabled(): void
    {
        $this->app->container()->instance(CaptchaVerifier::class, new FakeCaptchaVerifier());
        $body = ['reference_no' => 'RIFA-20261015-00042', 'mobile' => '01712345678'];

        $this->assertSame(422, $this->request('POST', self::URL, $body)->status());
        $this->assertSame(200, $this->request('POST', self::URL, $body + ['captcha_token' => 'pass'])->status());
    }
}
