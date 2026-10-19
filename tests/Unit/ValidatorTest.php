<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\UploadedFile;
use App\Core\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tmpFiles, 'is_file'));
    }

    private function file(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rifa');
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return new UploadedFile($name, $path, strlen($contents), UPLOAD_ERR_OK, true);
    }

    public function test_returns_trimmed_values_and_nulls_for_empty_optionals(): void
    {
        $data = Validator::make(
            ['full_name' => '  Rahim Uddin  ', 'email' => '   '],
            ['full_name' => 'required|string|min:2|max:100', 'email' => 'email|max:150'],
        )->validate();

        $this->assertSame(['full_name' => 'Rahim Uddin', 'email' => null], $data);
    }

    public function test_required_and_first_error_only(): void
    {
        $errors = Validator::make(
            ['full_name' => 'A'],
            ['full_name' => 'required|string|min:2|max:100', 'father_name' => 'required'],
        )->errors();

        $this->assertSame([
            'full_name' => 'The full name must be at least 2 characters.',
            'father_name' => 'The father name field is required.',
        ], $errors);
    }

    public function test_custom_messages_and_attribute_names(): void
    {
        $errors = Validator::make(
            ['mobile' => '0123', 'nid_no' => ''],
            ['mobile' => ['required', 'regex:/^01[3-9]\d{8}$/'], 'nid_no' => 'required'],
            ['mobile.regex' => 'Enter a valid mobile number.'],
            ['nid_no' => 'NID number'],
        )->errors();

        $this->assertSame('Enter a valid mobile number.', $errors['mobile']);
        $this->assertSame('The NID number field is required.', $errors['nid_no']);
    }

    public function test_validate_throws_validation_exception(): void
    {
        try {
            Validator::make([], ['email' => 'required|email'])->validate();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame(['email' => 'The email field is required.'], $e->fields());
        }
    }

    /** @return iterable<string, array{0: string, 1: mixed, 2: bool}> */
    public static function ruleCases(): iterable
    {
        yield 'email ok' => ['email', 'a@b.co', true];
        yield 'email bad' => ['email', 'a@', false];
        yield 'in ok' => ['in:male,female', 'female', true];
        yield 'in bad' => ['in:male,female', 'other', false];
        yield 'date ok' => ['date', '1995-02-28', true];
        yield 'date impossible' => ['date', '1995-02-30', false];
        yield 'date wrong format' => ['date', '28/02/1995', false];
        yield 'before_today past' => ['before_today', '2000-01-01', true];
        yield 'before_today future' => ['before_today', '2999-01-01', false];
        yield 'digits_in 10' => ['digits_in:10,13,17', '1234567890', true];
        yield 'digits_in 13' => ['digits_in:10,13,17', '1234567890123', true];
        yield 'digits_in 11' => ['digits_in:10,13,17', '12345678901', false];
        yield 'digits_in letters' => ['digits_in:10,13,17', '12345abcde', false];
        yield 'integer' => ['integer', '42', true];
        yield 'integer bad' => ['integer', '4.2', false];
        yield 'boolean' => ['boolean', 'false', true];
        yield 'boolean bad' => ['boolean', 'maybe', false];
        yield 'accepted' => ['accepted', '1', true];
        yield 'accepted no' => ['accepted', '0', false];
        yield 'max chars multibyte' => ['max:3', 'আমার', false];
    }

    #[DataProvider('ruleCases')]
    public function test_rules(string $rule, mixed $value, bool $passes): void
    {
        $this->assertSame($passes, !Validator::make(['f' => $value], ['f' => $rule])->fails());
    }

    public function test_numeric_min_max_with_integer_rule(): void
    {
        $this->assertTrue(Validator::make(['age' => '17'], ['age' => 'integer|min:18'])->fails());
        $this->assertSame(['age' => 30], Validator::make(['age' => '30'], ['age' => 'integer|min:18|max:45'])->validate());
    }

    public function test_required_if(): void
    {
        $rules = ['passport_copy' => 'required_if:passport_status,have_passport|file'];

        $this->assertSame(
            ['passport_copy' => 'The passport copy field is required.'],
            Validator::make(['passport_status' => 'have_passport'], $rules)->errors(),
        );
        $this->assertSame([], Validator::make(['passport_status' => 'will_apply'], $rules)->errors());
    }

    public function test_accepted_is_required_and_cast_to_true(): void
    {
        $rules = ['declaration' => 'accepted'];

        $this->assertArrayHasKey('declaration', Validator::make([], $rules)->errors());
        $this->assertSame(['declaration' => true], Validator::make(['declaration' => '1'], $rules)->validate());
    }

    public function test_files_check_size_extension_and_real_mime(): void
    {
        $rules = ['cv' => 'file|max_kb:1|mimes:pdf,jpg,png'];

        $png = $this->file('photo.png', (string) base64_decode(self::PNG));
        $this->assertSame([], Validator::make(['cv' => $png], $rules)->errors());

        $pdf = $this->file('cv.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->assertSame([], Validator::make(['cv' => $pdf], $rules)->errors());

        $fakePdf = $this->file('cv.pdf', '<?php echo "x"; ?>');
        $this->assertSame(
            ['cv' => 'The cv must be a file of type: PDF, JPG, PNG.'],
            Validator::make(['cv' => $fakePdf], $rules)->errors(),
        );

        $big = $this->file('big.png', (string) base64_decode(self::PNG) . str_repeat('0', 2048));
        $this->assertSame(
            ['cv' => 'The cv may not be larger than 1 KB.'],
            Validator::make(['cv' => $big], $rules)->errors(),
        );
    }

    public function test_missing_optional_file_is_null_and_oversized_upload_has_clear_message(): void
    {
        $missing = new UploadedFile('', '', 0, UPLOAD_ERR_NO_FILE);
        $this->assertSame(['cv' => null], Validator::make(['cv' => $missing], ['cv' => 'file'])->validate());

        $tooLarge = new UploadedFile('cv.pdf', '', 0, UPLOAD_ERR_INI_SIZE);
        $this->assertSame(['cv' => 'The cv is too large.'], Validator::make(['cv' => $tooLarge], ['cv' => 'file'])->errors());
    }
}
