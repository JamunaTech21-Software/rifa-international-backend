<?php

declare(strict_types=1);

namespace Tests\Integration;

final class PublicEndpointsTest extends IntegrationTestCase
{
    public function test_health_reports_ok_with_dhaka_time(): void
    {
        $response = $this->request('GET', '/api/v1/health');
        $data = $response->decodedJson()['data'];

        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $data['status']);
        $this->assertSame('ok', $data['database']);
        $this->assertStringEndsWith('+06:00', $data['time']);
    }

    public function test_lookups_shape_and_cache_header(): void
    {
        $response = $this->request('GET', '/api/v1/lookups');
        $data = $response->decodedJson()['data'];

        $this->assertSame(200, $response->status());
        $this->assertSame('public, max-age=3600', $response->header('Cache-Control'));
        $this->assertSame(
            ['job_categories', 'genders', 'work_experience', 'education_levels', 'passport_statuses', 'contact_subjects'],
            array_keys($data),
        );
        $this->assertSame(['slug' => 'construction', 'name' => 'Construction'], $data['job_categories'][0]);
        $this->assertCount(6, $data['job_categories']);
        $this->assertSame(['value' => 'have_passport', 'label' => 'Have Passport'], $data['passport_statuses'][0]);
        $this->assertSame(
            ['general_inquiry', 'job_application', 'employer_partnership', 'support'],
            array_column($data['contact_subjects'], 'value'),
        );
    }

    public function test_inactive_categories_are_hidden(): void
    {
        $this->db->execute("UPDATE job_categories SET is_active = 0 WHERE slug = 'hospitality'");

        $slugs = array_column($this->request('GET', '/api/v1/lookups')->decodedJson()['data']['job_categories'], 'slug');

        $this->assertNotContains('hospitality', $slugs);
        $this->assertCount(5, $slugs);
    }

    public function test_unknown_endpoint_is_json_404(): void
    {
        $response = $this->request('GET', '/api/v1/does-not-exist');

        $this->assertSame(404, $response->status());
        $this->assertSame('not_found', $response->decodedJson()['error']['code']);
    }
}
