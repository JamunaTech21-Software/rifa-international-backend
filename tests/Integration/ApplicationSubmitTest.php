<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\UploadedFile;
use App\Core\Response;
use Tests\Support\Fixtures;

final class ApplicationSubmitTest extends IntegrationTestCase
{
    private const URL = '/api/v1/applications';

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-20 10:32:00');
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tmpFiles, 'is_file'));
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function validFields(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => '  Md.   Rahim  Uddin ',
            'father_name' => 'Md. Karim Uddin',
            'date_of_birth' => '1998-05-14',
            'gender' => 'male',
            'nid_no' => '1234567890',
            'mobile' => '01712345678',
            'email' => 'Rahim@Example.com',
            'present_address' => 'Village X,  Upazila Y, Cumilla',
            'job_category' => 'construction',
            'work_experience' => '1_2',
            'education_level' => 'ssc',
            'passport_status' => 'have_passport',
            'declaration' => '1',
            'website' => '',
        ];
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = Fixtures::tempFile($contents);
        $this->tmpFiles[] = $path;

        return new UploadedFile($name, $path, strlen($contents), UPLOAD_ERR_OK, true);
    }

    /** @return array<string, UploadedFile> */
    private function validFiles(): array
    {
        return [
            'passport_copy' => $this->upload('passport.pdf', Fixtures::pdf()),
            'cv' => $this->upload('cv.png', Fixtures::png()),
        ];
    }

    /**
     * @param array<string, string>       $fields
     * @param array<string, UploadedFile> $files
     */
    private function submit(array $fields, array $files = [], string $ip = '203.0.113.10'): Response
    {
        // Always multipart, like the browser.
        return $this->app->handle(new \App\Core\Request(
            'POST', self::URL, [], $fields, $files, ['Content-Type' => 'multipart/form-data; boundary=x'], ['REMOTE_ADDR' => $ip],
        ));
    }

    public function test_happy_path_stores_normalised_application_documents_and_history(): void
    {
        $response = $this->submit($this->validFields(), $this->validFiles());

        $this->assertSame(201, $response->status(), $response->body());
        $data = $response->decodedJson()['data'];
        $this->assertSame('RIFA-20261020-00001', $data['reference_no']);
        $this->assertSame('2026-10-20T10:32:00+06:00', $data['submitted_at']);
        $this->assertTrue($data['confirmation_email_sent']);

        $row = $this->db->fetchOne('SELECT * FROM applications WHERE reference_no = ?', [$data['reference_no']]);
        $this->assertSame('Md. Rahim Uddin', $row['full_name']);
        $this->assertSame('+8801712345678', $row['mobile']);
        $this->assertSame('rahim@example.com', $row['email']);
        $this->assertSame('Village X, Upazila Y, Cumilla', $row['present_address']);
        $this->assertSame(28, $row['age_at_submission']);
        $this->assertSame('received', $row['status']);
        $this->assertSame(0, $row['is_age_flagged']);
        $this->assertSame(0, $row['is_duplicate_flagged']);
        $this->assertSame('203.0.113.10', $row['ip_address']);
        $this->assertSame('2026-10-20 10:32:00', $row['created_at']);
        $this->assertSame('construction', $this->db->fetchValue('SELECT slug FROM job_categories WHERE id = ?', [$row['job_category_id']]));

        $docs = $this->db->fetchAll('SELECT doc_type, cloudinary_public_id, format, original_filename FROM application_documents WHERE application_id = ? ORDER BY doc_type', [$row['id']]);
        $this->assertSame([
            ['doc_type' => 'cv', 'cloudinary_public_id' => 'rifa/applications/RIFA-20261020-00001/cv', 'format' => 'png', 'original_filename' => 'cv.png'],
            ['doc_type' => 'passport_copy', 'cloudinary_public_id' => 'rifa/applications/RIFA-20261020-00001/passport_copy', 'format' => 'pdf', 'original_filename' => 'passport.pdf'],
        ], $docs);

        $history = $this->db->fetchAll('SELECT from_status, to_status, changed_by FROM application_status_history WHERE application_id = ?', [$row['id']]);
        $this->assertSame([['from_status' => null, 'to_status' => 'received', 'changed_by' => null]], $history);

        $second = $this->submit($this->validFields(['mobile' => '01812345678', 'nid_no' => '']), $this->validFiles());
        $this->assertSame('RIFA-20261020-00002', $second->decodedJson()['data']['reference_no']);
    }

    public function test_will_apply_without_files_or_email(): void
    {
        $response = $this->submit($this->validFields([
            'passport_status' => 'will_apply', 'email' => '', 'nid_no' => '', 'work_experience' => '', 'education_level' => '',
        ]));

        $this->assertSame(201, $response->status(), $response->body());
        $this->assertFalse($response->decodedJson()['data']['confirmation_email_sent']);
        $row = $this->db->fetchOne('SELECT id, email, nid_no, work_experience FROM applications');
        $this->assertNull($row['email']);
        $this->assertNull($row['nid_no']);
        $this->assertNull($row['work_experience']);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM application_documents'));
        $this->assertSame([], $this->storage->stored);
    }

    public function test_empty_submission_lists_every_required_field_with_exact_messages(): void
    {
        $response = $this->submit(['passport_status' => 'have_passport']);

        $this->assertSame(422, $response->status());
        $this->assertSame([
            'full_name' => 'Please enter your full name.',
            'father_name' => "Please enter your father's name.",
            'date_of_birth' => 'Please enter your date of birth.',
            'gender' => 'Please select your gender.',
            'mobile' => 'Please enter your mobile number.',
            'present_address' => 'Please enter your present address.',
            'job_category' => 'Please select a job category.',
            'passport_copy' => 'Please upload your passport copy.',
            'declaration' => 'Please confirm that the information is true and correct.',
        ], $response->decodedJson()['error']['fields']);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM applications'));
    }

    public function test_format_errors(): void
    {
        $response = $this->submit($this->validFields([
            'full_name' => 'R2D2',
            'date_of_birth' => '14/05/1998',
            'gender' => 'other',
            'nid_no' => '12345',
            'mobile' => '01212345678',
            'email' => 'not-an-email',
            'present_address' => 'abc',
            'job_category' => 'astronaut',
            'work_experience' => 'lots',
            'passport_status' => 'maybe',
        ]), $this->validFiles());

        $this->assertSame([
            'full_name' => 'Full name must be 2–100 letters.',
            'date_of_birth' => 'Enter a valid date (YYYY-MM-DD).',
            'gender' => 'Please select your gender.',
            'nid_no' => 'NID number must be 10, 13 or 17 digits.',
            'mobile' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).',
            'email' => 'Enter a valid email address.',
            'present_address' => 'Present address must be 5–255 characters.',
            'job_category' => 'Please select a valid job category.',
            'work_experience' => 'Please select a valid option.',
            'passport_status' => 'Please select your passport status.',
        ], $response->decodedJson()['error']['fields']);
    }

    public function test_mobile_formats_and_bangla_names_are_accepted(): void
    {
        foreach (['017 1234-5678', '+8801712345678', '8801712345678'] as $i => $mobile) {
            $response = $this->submit($this->validFields([
                'mobile' => $mobile, 'nid_no' => '', 'full_name' => 'মোঃ রহিম উদ্দিন',
            ]), $this->validFiles(), "10.0.0.{$i}");
            $this->assertSame(201, $response->status(), $response->body());
        }

        $this->assertSame(['+8801712345678'], array_values(array_unique(array_column(
            $this->db->fetchAll('SELECT mobile FROM applications'), 'mobile',
        ))));
        $this->assertSame('মোঃ রহিম উদ্দিন', $this->db->fetchValue('SELECT full_name FROM applications LIMIT 1'));

        $curly = $this->submit($this->validFields(['full_name' => 'Md. Rahim O’Neil', 'mobile' => '01812345678', 'nid_no' => '']), $this->validFiles(), '10.0.1.1');
        $this->assertSame(201, $curly->status(), 'curly apostrophe allowed, like the frontend');
    }

    public function test_wrong_file_type_and_oversized_files(): void
    {
        $response = $this->submit($this->validFields(), [
            'passport_copy' => $this->upload('passport.pdf', '<?php echo 1;'),
            'cv' => new UploadedFile('cv.pdf', '', 0, UPLOAD_ERR_INI_SIZE),
        ]);

        $this->assertSame([
            'cv' => 'CV must be 5 MB or smaller.',
            'passport_copy' => 'Passport copy must be a PDF, JPG or PNG file.',
        ], $response->decodedJson()['error']['fields']);
    }

    public function test_age_rules(): void
    {
        $under = $this->submit($this->validFields(['date_of_birth' => '2008-10-21']), $this->validFiles());
        $this->assertSame(['date_of_birth' => 'You must be at least 18 years old.'], $under->decodedJson()['error']['fields']);

        $future = $this->submit($this->validFields(['date_of_birth' => '2030-01-01']), $this->validFiles());
        $this->assertSame(['date_of_birth' => 'Date of birth must be in the past.'], $future->decodedJson()['error']['fields']);

        $exactly18 = $this->submit($this->validFields(['date_of_birth' => '2008-10-20']), $this->validFiles());
        $this->assertSame(201, $exactly18->status());

        $older = $this->submit($this->validFields(['date_of_birth' => '1975-01-01', 'mobile' => '01912345678', 'nid_no' => '']), $this->validFiles(), '10.1.1.1');
        $this->assertSame(201, $older->status());
        $this->assertSame(1, $this->db->fetchValue('SELECT is_age_flagged FROM applications WHERE mobile = ?', ['+8801912345678']));
    }

    public function test_duplicate_flag_within_30_days_by_mobile_or_nid(): void
    {
        $this->clock()->freeze('2026-09-01 09:00:00');
        $this->submit($this->validFields(), $this->validFiles());

        $this->clock()->freeze('2026-10-20 10:00:00'); // 49 days later — not a duplicate
        $this->submit($this->validFields(), $this->validFiles(), '10.0.0.2');

        $this->clock()->freeze('2026-10-25 10:00:00'); // same NID, different mobile, 5 days later
        $this->submit($this->validFields(['mobile' => '01512345678']), $this->validFiles(), '10.0.0.3');

        $this->assertSame([0, 0, 1], array_map('intval', array_column(
            $this->db->fetchAll('SELECT is_duplicate_flagged FROM applications ORDER BY id'), 'is_duplicate_flagged',
        )));
    }

    public function test_honeypot_pretends_success_and_stores_nothing(): void
    {
        $response = $this->submit($this->validFields(['website' => 'http://spam.example']), $this->validFiles());

        $this->assertSame(201, $response->status());
        $this->assertMatchesRegularExpression('/^RIFA-20261020-\d{5}$/', $response->decodedJson()['data']['reference_no']);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM applications'));
        $this->assertSame([], $this->storage->stored);
        $this->assertSame([], $this->mailer()->outbox());
    }

    public function test_rate_limit_counts_only_successful_submissions(): void
    {
        $this->submit([]); // 422 — does not count
        for ($i = 0; $i < 5; $i++) {
            $mobile = '0171234567' . $i;
            $this->assertSame(201, $this->submit($this->validFields(['mobile' => $mobile, 'nid_no' => '']), $this->validFiles())->status());
        }

        $blocked = $this->submit($this->validFields(['mobile' => '01812345670']), $this->validFiles());

        $this->assertSame(429, $blocked->status());
        $this->assertSame('rate_limited', $blocked->decodedJson()['error']['code']);
        $this->assertNotNull($blocked->header('Retry-After'));
    }

    public function test_rejected_document_becomes_field_error_and_cleans_up(): void
    {
        $this->storage->failOn['cv'] = 'invalid';

        $response = $this->submit($this->validFields(), $this->validFiles());

        $this->assertSame(422, $response->status());
        $this->assertSame(
            ['cv' => 'CV could not be read. Please upload a clear PDF, JPG or PNG file.'],
            $response->decodedJson()['error']['fields'],
        );
        $this->assertSame(['rifa/applications/RIFA-20261020-00001/passport_copy'], $this->storage->deleted);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM applications'));
    }

    public function test_storage_outage_is_500_and_cleans_up(): void
    {
        $this->storage->failOn['cv'] = 'error';

        $response = $this->submit($this->validFields(), $this->validFiles());

        $this->assertSame(500, $response->status());
        $this->assertSame('server_error', $response->decodedJson()['error']['code']);
        $this->assertSame([], $this->storage->stored);
    }

    public function test_database_failure_after_upload_deletes_uploaded_documents(): void
    {
        $this->storage->failOn['cv'] = 'long_id';

        $response = $this->submit($this->validFields(), $this->validFiles());

        $this->assertSame(500, $response->status());
        $this->assertSame([], $this->storage->stored);
        $this->assertCount(2, $this->storage->deleted);
        $this->db->beginTransaction(); // the failed transaction rolled back the test transaction too
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM applications'));
    }
}
