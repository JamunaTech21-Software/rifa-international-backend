<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\UploadedFile;
use App\Core\Response;
use App\Shared\Security\CsrfTokenManager;
use Tests\Support\Fixtures;

/** P5-02: public GET /jobs and the admin job listing CRUD + photo. */
final class JobsTest extends IntegrationTestCase
{
    private const BASE = '/api/v1/admin/jobs';

    private int $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-20 12:00:00');
        $this->superAdmin = $this->createAdmin('super_admin');
        $this->signIn($this->superAdmin);
    }

    private function signIn(int $id): void
    {
        $this->loginAs($id);
        $this->app->container()->get(CsrfTokenManager::class)->token();
    }

    /** @return array<string, string> */
    private function csrf(): array
    {
        return ['X-CSRF-Token' => (string) $this->session()->get('csrf_token')];
    }

    /** @return array<string, mixed> */
    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Construction Worker',
            'employer_type' => 'Construction Company',
            'job_category' => 'construction',
            'location' => 'Kuala Lumpur',
            'salary_min' => 1800,
            'salary_max' => 2200,
        ];
    }

    /** @return array<string, mixed> */
    private function create(array $overrides = []): array
    {
        $response = $this->request('POST', self::BASE, $this->valid($overrides), $this->csrf());
        $this->assertSame(201, $response->status(), $response->body());

        return $response->decodedJson()['data'];
    }

    private function upload(int $id, string $contents, string $name = 'photo.png'): Response
    {
        $path = Fixtures::tempFile($contents);
        $file = new UploadedFile($name, $path, strlen($contents), UPLOAD_ERR_OK, true);

        return $this->request('POST', self::BASE . "/{$id}/image", ['x' => '1'], $this->csrf() + ['Content-Type' => 'multipart/form-data; boundary=x'], ['image' => $file]);
    }

    public function test_create_returns_the_job_with_slug_and_defaults(): void
    {
        $job = $this->create(['description' => '  Free accommodation.  ']);

        $this->assertSame([
            'slug' => 'construction-worker',
            'title' => 'Construction Worker',
            'employer_type' => 'Construction Company',
            'category' => ['slug' => 'construction', 'name' => 'Construction'],
            'location' => 'Kuala Lumpur',
            'salary_min' => 1800,
            'salary_max' => 2200,
            'currency' => 'MYR',
            'description' => 'Free accommodation.',
            'image_public_id' => null,
            'is_published' => true,
            'sort_order' => 1,
            'created_at' => '2026-10-20T12:00:00+06:00',
        ], array_diff_key($job, ['id' => 1, 'updated_at' => 1]));

        // Same title again → unique slug; next position in the list.
        $second = $this->create();
        $this->assertSame('construction-worker-2', $second['slug']);
        $this->assertSame(2, $second['sort_order']);
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'jobs.created'"));
    }

    public function test_create_validation_messages(): void
    {
        $empty = $this->request('POST', self::BASE, ['description' => ''], $this->csrf());
        $this->assertSame(422, $empty->status());
        $this->assertSame([
            'title' => 'Please enter the job title.',
            'employer_type' => 'Please enter the employer type.',
            'job_category' => 'Please select a job category.',
            'location' => 'Please enter the location.',
            'salary_min' => 'Please enter the minimum salary.',
            'salary_max' => 'Please enter the maximum salary.',
        ], $empty->decodedJson()['error']['fields']);

        $category = $this->request('POST', self::BASE, $this->valid(['job_category' => 'astronaut']), $this->csrf());
        $this->assertSame(['job_category' => 'Please select a valid job category.'], $category->decodedJson()['error']['fields']);

        $range = $this->request('POST', self::BASE, $this->valid(['salary_min' => 2500, 'salary_max' => 2000]), $this->csrf());
        $this->assertSame(['salary_max' => 'Maximum salary must be at least the minimum salary.'], $range->decodedJson()['error']['fields']);

        $number = $this->request('POST', self::BASE, $this->valid(['salary_min' => '1.5k']), $this->csrf());
        $this->assertSame(['salary_min' => 'Minimum salary must be a whole number from 0 to 100000.'], $number->decodedJson()['error']['fields']);
    }

    public function test_update_changes_only_sent_fields_and_keeps_the_slug(): void
    {
        $job = $this->create(['description' => 'Old text']);

        $response = $this->request('PATCH', self::BASE . "/{$job['id']}", [
            'title' => 'Senior Construction Worker',
            'salary_max' => 2600,
            'is_published' => false,
            'description' => '',
        ], $this->csrf());

        $this->assertSame(200, $response->status(), $response->body());
        $updated = $response->decodedJson()['data'];
        $this->assertSame('construction-worker', $updated['slug']);
        $this->assertSame('Senior Construction Worker', $updated['title']);
        $this->assertSame(1800, $updated['salary_min']);
        $this->assertSame(2600, $updated['salary_max']);
        $this->assertFalse($updated['is_published']);
        $this->assertNull($updated['description']);
        $this->assertSame('Kuala Lumpur', $updated['location']);

        $meta = json_decode((string) $this->db->fetchValue("SELECT meta FROM audit_logs WHERE action = 'jobs.updated'"), true);
        $this->assertEqualsCanonicalizing(['title', 'salary_max', 'is_published', 'description'], $meta['changed']);
    }

    public function test_update_rejects_empty_required_fields_and_a_range_against_the_stored_salary(): void
    {
        $job = $this->create();

        $empty = $this->request('PATCH', self::BASE . "/{$job['id']}", ['title' => ''], $this->csrf());
        $this->assertSame(['title' => 'Please enter the job title.'], $empty->decodedJson()['error']['fields']);

        $range = $this->request('PATCH', self::BASE . "/{$job['id']}", ['salary_max' => 1000], $this->csrf());
        $this->assertSame(['salary_max' => 'Maximum salary must be at least the minimum salary.'], $range->decodedJson()['error']['fields']);

        $this->assertSame(404, $this->request('PATCH', self::BASE . '/999999', ['title' => 'X job'], $this->csrf())->status());
    }

    public function test_public_list_shows_only_published_jobs_in_order_without_admin_fields(): void
    {
        $late = $this->create(['title' => 'Forklift Driver', 'sort_order' => 5]);
        $early = $this->create(['title' => 'Kitchen Helper', 'job_category' => 'hospitality', 'sort_order' => 1]);
        $this->create(['title' => 'Draft Job', 'is_published' => false]);

        $response = $this->request('GET', '/api/v1/jobs');

        $this->assertSame(200, $response->status());
        $this->assertSame('public, max-age=300', $response->header('Cache-Control'));
        $jobs = $response->decodedJson()['data'];
        $this->assertSame([$early['id'], $late['id']], array_column($jobs, 'id'));
        $this->assertSame(['slug' => 'hospitality', 'name' => 'Hospitality'], $jobs[0]['category']);
        $this->assertArrayNotHasKey('is_published', $jobs[0]);
        $this->assertArrayNotHasKey('created_at', $jobs[0]);
    }

    public function test_admin_list_filters(): void
    {
        $this->create(['title' => 'Forklift Driver', 'job_category' => 'logistics-warehouse', 'location' => 'Johor']);
        $this->create(['title' => 'Kitchen Helper', 'job_category' => 'hospitality', 'is_published' => false]);

        $titles = fn (string $query) => array_column($this->request('GET', self::BASE . $query)->decodedJson()['data'], 'title');

        $this->assertSame(['Forklift Driver', 'Kitchen Helper'], $titles(''));
        $this->assertSame(['Kitchen Helper'], $titles('?status=draft'));
        $this->assertSame(['Forklift Driver'], $titles('?status=published'));
        $this->assertSame(['Kitchen Helper'], $titles('?category=hospitality'));
        $this->assertSame(['Forklift Driver'], $titles('?q=johor'));
        $this->assertSame([], $titles('?q=100%'));
        $this->assertSame(422, $this->request('GET', self::BASE . '?status=deleted')->status());
    }

    public function test_photo_upload_replaces_the_old_photo_and_can_be_removed(): void
    {
        $job = $this->create();

        $first = $this->upload($job['id'], Fixtures::png());
        $this->assertSame(200, $first->status(), $first->body());
        $firstId = $first->decodedJson()['data']['image_public_id'];
        $this->assertMatchesRegularExpression('#^rifa/jobs/construction-worker-[0-9a-f]{8}$#', $firstId);

        $secondId = $this->upload($job['id'], Fixtures::png())->decodedJson()['data']['image_public_id'];
        $this->assertNotSame($firstId, $secondId, 'a new public id on every upload (no stale CDN copy)');
        $this->assertSame([$firstId], $this->images->deleted);

        $removed = $this->request('DELETE', self::BASE . "/{$job['id']}/image", [], $this->csrf());
        $this->assertNull($removed->decodedJson()['data']['image_public_id']);
        $this->assertSame([$firstId, $secondId], $this->images->deleted);
    }

    public function test_photo_validation(): void
    {
        $job = $this->create();

        $pdf = $this->upload($job['id'], Fixtures::pdf(), 'photo.pdf');
        $this->assertSame(['image' => 'Photo must be a JPG, PNG or WebP image.'], $pdf->decodedJson()['error']['fields']);

        $this->images->rejectNext = true;
        $broken = $this->upload($job['id'], Fixtures::png());
        $this->assertSame(['image' => 'This file is not a valid image.'], $broken->decodedJson()['error']['fields']);
        $this->assertNull($this->db->fetchValue('SELECT image_public_id FROM jobs WHERE id = ?', [$job['id']]));
    }

    public function test_delete_is_super_admin_only_and_removes_the_photo(): void
    {
        $job = $this->create();
        $photo = $this->upload($job['id'], Fixtures::png())->decodedJson()['data']['image_public_id'];

        $this->signIn($this->createAdmin('staff'));
        $this->assertSame(403, $this->request('DELETE', self::BASE . "/{$job['id']}", [], $this->csrf())->status());
        $this->assertSame(200, $this->request('PATCH', self::BASE . "/{$job['id']}", ['is_published' => false], $this->csrf())->status(), 'staff can unpublish');

        $this->signIn($this->superAdmin);
        $this->assertSame(204, $this->request('DELETE', self::BASE . "/{$job['id']}", [], $this->csrf())->status());
        $this->assertSame(404, $this->request('GET', self::BASE . "/{$job['id']}")->status());
        $this->assertContains($photo, $this->images->deleted);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'jobs.deleted'"));
    }

    public function test_admin_routes_need_login_and_csrf(): void
    {
        $this->session()->forget('admin_id');
        $this->assertSame(401, $this->request('GET', self::BASE)->status());

        $this->signIn($this->superAdmin);
        $this->assertSame(419, $this->request('POST', self::BASE, $this->valid())->status());
    }

    public function test_starter_seed_fills_an_empty_table_once(): void
    {
        $seed = dirname(__DIR__, 2) . '/database/seeds/jobs.sql';
        $migrator = new \App\Core\Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations');

        $migrator->runFile($seed);
        $this->assertSame(12, (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs'));

        $this->db->execute("DELETE FROM jobs WHERE slug = 'scaffolder'");
        $migrator->runFile($seed);
        $this->assertSame(11, (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs'), 'a deleted job does not come back');
    }
}
