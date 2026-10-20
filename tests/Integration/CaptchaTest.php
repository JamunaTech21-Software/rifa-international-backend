<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Shared\Security\CaptchaVerifier;
use App\Shared\Security\TurnstileVerifier;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeCaptchaVerifier;

/** P5-06: Turnstile check on the public forms. */
final class CaptchaTest extends IntegrationTestCase
{
    private FakeCaptchaVerifier $captcha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captcha = new FakeCaptchaVerifier();
        $this->app->container()->instance(CaptchaVerifier::class, $this->captcha);
    }

    /** @return array<string, string> */
    private function message(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => 'Md. Rahim Uddin',
            'email' => 'rahim@example.com',
            'phone' => '01712345678',
            'subject' => 'general_inquiry',
            'message' => 'Hello, I would like to know more.',
            'website' => '',
        ];
    }

    public function test_message_without_or_with_wrong_token_is_rejected_and_not_stored(): void
    {
        foreach ([[], ['captcha_token' => 'wrong']] as $extra) {
            $response = $this->request('POST', '/api/v1/messages', $this->message($extra));

            $this->assertSame(422, $response->status());
            $this->assertSame(
                ['captcha_token' => 'Please complete the security check and try again.'],
                $response->decodedJson()['error']['fields'],
            );
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contact_messages'));
    }

    public function test_message_with_valid_token_is_stored_and_ip_is_passed_to_cloudflare(): void
    {
        $response = $this->request('POST', '/api/v1/messages', $this->message(['captcha_token' => ' pass ']), [], [], '198.51.100.7');

        $this->assertSame(201, $response->status(), $response->body());
        $this->assertSame([['token' => 'pass', 'ip' => '198.51.100.7']], $this->captcha->calls);
    }

    public function test_application_form_checks_captcha_before_validating_the_form(): void
    {
        $blocked = $this->multipart(['full_name' => 'X']);
        $this->assertSame(['captcha_token'], array_keys($blocked->decodedJson()['error']['fields']));

        // The widget's own hidden field name is accepted too (FormData built from the <form>).
        $passed = $this->multipart(['cf-turnstile-response' => 'pass']);
        $this->assertSame(422, $passed->status());
        $this->assertArrayNotHasKey('captcha_token', $passed->decodedJson()['error']['fields']);
        $this->assertArrayHasKey('full_name', $passed->decodedJson()['error']['fields']);
    }

    /** @param array<string, string> $fields */
    private function multipart(array $fields): Response
    {
        return $this->app->handle(new Request('POST', '/api/v1/applications', [], $fields, [], ['Content-Type' => 'multipart/form-data; boundary=x'], ['REMOTE_ADDR' => '203.0.113.10']));
    }

    public function test_disabled_captcha_lets_forms_through_without_a_token(): void
    {
        $this->app->container()->instance(CaptchaVerifier::class, new TurnstileVerifier(['enabled' => false], $this->app->container()->get(Logger::class)));

        $this->assertSame(201, $this->request('POST', '/api/v1/messages', $this->message())->status());
    }

    /**
     * Calls Cloudflare's real siteverify endpoint with its public test secrets.
     * Needs internet; exclude with: composer test -- --exclude-group turnstile
     */
    #[Group('turnstile')]
    public function test_live_turnstile_with_cloudflare_test_secrets(): void
    {
        $logger = $this->app->container()->get(Logger::class);
        $pass = new TurnstileVerifier(['enabled' => true, 'secret' => '1x0000000000000000000000000000000AA'], $logger);
        $fail = new TurnstileVerifier(['enabled' => true, 'secret' => '2x0000000000000000000000000000000AA'], $logger);

        $this->assertTrue($pass->verify('XXXX.DUMMY.TOKEN.XXXX', '203.0.113.10'));
        $this->assertFalse($fail->verify('XXXX.DUMMY.TOKEN.XXXX', '203.0.113.10'));
        $this->assertFalse($pass->verify('', '203.0.113.10'), 'empty token never reaches Cloudflare');
    }
}
