<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use App\Shared\Security\Password;

final class AdminAuthTest extends IntegrationTestCase
{
    private const PASSWORD = 'Correct-Horse-9';

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-20 10:00:00');
        $this->adminId = $this->db->insert('admins', [
            'name' => 'Samin',
            'email' => 'admin@rifaint.com',
            'password_hash' => Password::hash(self::PASSWORD),
            'role' => 'super_admin',
        ]);
    }

    private function login(string $email = 'admin@rifaint.com', string $password = self::PASSWORD, string $ip = '203.0.113.5'): Response
    {
        return $this->request('POST', '/api/v1/admin/auth/login', ['email' => $email, 'password' => $password], [], [], $ip);
    }

    private function csrfHeader(): array
    {
        return ['X-CSRF-Token' => (string) $this->session()->get('csrf_token')];
    }

    public function test_successful_login_returns_admin_and_csrf_and_starts_session(): void
    {
        $response = $this->login(' Admin@RifaInt.com ');

        $this->assertSame(200, $response->status(), $response->body());
        $data = $response->decodedJson()['data'];
        $this->assertSame(['id' => $this->adminId, 'name' => 'Samin', 'email' => 'admin@rifaint.com', 'role' => 'super_admin'], $data['admin']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['csrf_token']);
        $this->assertSame($this->adminId, $this->session()->get('admin_id'));
        $this->assertSame(1, $this->session()->get('session_version'));
        $this->assertSame('2026-10-20 10:00:00', $this->db->fetchValue('SELECT last_login_at FROM admins WHERE id = ?', [$this->adminId]));
        $this->assertSame('auth.login', $this->db->fetchValue('SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1'));

        $me = $this->request('GET', '/api/v1/admin/auth/me')->decodedJson()['data'];
        $this->assertSame('admin@rifaint.com', $me['admin']['email']);
        $this->assertSame($data['csrf_token'], $me['csrf_token']);
    }

    public function test_wrong_password_unknown_email_and_inactive_get_the_same_generic_error(): void
    {
        $inactive = $this->db->insert('admins', [
            'name' => 'Old', 'email' => 'old@rifaint.com', 'password_hash' => Password::hash(self::PASSWORD), 'is_active' => 0,
        ]);

        foreach ([['admin@rifaint.com', 'wrong-password'], ['nobody@rifaint.com', self::PASSWORD], ['old@rifaint.com', self::PASSWORD]] as [$email, $password]) {
            $response = $this->login($email, $password);
            $this->assertSame(401, $response->status());
            $this->assertSame(['code' => 'invalid_credentials', 'message' => 'Invalid email or password.'], $response->decodedJson()['error']);
        }

        $this->assertSame(1, $this->db->fetchValue('SELECT failed_logins FROM admins WHERE id = ?', [$this->adminId]));
        $this->assertNull($this->session()->get('admin_id'));
        $this->assertGreaterThan(0, $inactive);
    }

    public function test_validation_messages(): void
    {
        $response = $this->request('POST', '/api/v1/admin/auth/login', ['email' => 'nope']);

        $this->assertSame([
            'email' => 'Enter a valid email address.',
            'password' => 'Please enter your password.',
        ], $response->decodedJson()['error']['fields']);
    }

    public function test_five_wrong_passwords_lock_the_account_for_15_minutes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->login(password: 'wrong')->status());
        }

        $locked = $this->login();
        $this->assertSame(429, $locked->status());
        $this->assertSame('Too many failed attempts. Please try again in 15 minutes.', $locked->decodedJson()['error']['message']);
        $this->assertSame('900', $locked->header('Retry-After'));

        $this->clock()->freeze('2026-10-20 10:15:01');
        $this->assertSame(200, $this->login()->status());
        $this->assertSame(0, $this->db->fetchValue('SELECT failed_logins FROM admins WHERE id = ?', [$this->adminId]));
    }

    public function test_too_many_failures_from_one_ip_are_blocked(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->login("user{$i}@example.com", 'whatever');
        }

        $this->assertSame(429, $this->login()->status());
        $this->assertSame(200, $this->login(ip: '198.51.100.1')->status());
    }

    public function test_me_requires_a_session(): void
    {
        $response = $this->request('GET', '/api/v1/admin/auth/me');

        $this->assertSame(401, $response->status());
        $this->assertSame('unauthenticated', $response->decodedJson()['error']['code']);
    }

    public function test_logout_requires_csrf_and_ends_the_session(): void
    {
        $this->login();

        $this->assertSame(419, $this->request('POST', '/api/v1/admin/auth/logout')->status());

        $response = $this->request('POST', '/api/v1/admin/auth/logout', [], $this->csrfHeader());
        $this->assertSame(204, $response->status());
        $this->assertSame(401, $this->request('GET', '/api/v1/admin/auth/me')->status());
    }

    public function test_change_password(): void
    {
        $this->login();
        $url = '/api/v1/admin/auth/change-password';

        $wrong = $this->request('POST', $url, ['current_password' => 'nope', 'new_password' => 'Another-Pass-1'], $this->csrfHeader());
        $this->assertSame(['current_password' => 'Current password is incorrect.'], $wrong->decodedJson()['error']['fields']);

        $short = $this->request('POST', $url, ['current_password' => self::PASSWORD, 'new_password' => 'short'], $this->csrfHeader());
        $this->assertSame(['new_password' => 'Password must be 10–200 characters.'], $short->decodedJson()['error']['fields']);

        $same = $this->request('POST', $url, ['current_password' => self::PASSWORD, 'new_password' => self::PASSWORD], $this->csrfHeader());
        $this->assertSame(['new_password' => 'New password must be different from the current password.'], $same->decodedJson()['error']['fields']);

        $ok = $this->request('POST', $url, ['current_password' => self::PASSWORD, 'new_password' => 'Another-Pass-1'], $this->csrfHeader());
        $this->assertSame(200, $ok->status(), $ok->body());

        // This session stays valid…
        $this->assertSame(200, $this->request('GET', '/api/v1/admin/auth/me')->status());
        // …but a session that logged in before the change does not.
        $this->session()->set('session_version', 1);
        $this->assertSame(401, $this->request('GET', '/api/v1/admin/auth/me')->status());

        $this->assertSame(401, $this->login()->status());
        $this->assertSame(200, $this->login(password: 'Another-Pass-1')->status());
    }

    public function test_forgot_password_for_unknown_email_looks_the_same_but_sends_nothing(): void
    {
        $response = $this->request('POST', '/api/v1/admin/auth/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame(200, $response->status());
        $this->assertSame('If that email belongs to an admin account, a reset link has been sent.', $response->decodedJson()['data']['message']);
        $this->assertSame([], $this->mailer()->outbox());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM password_resets'));
    }

    public function test_reset_password_flow(): void
    {
        $this->login(); // an existing session that must be logged out by the reset

        $this->request('POST', '/api/v1/admin/auth/forgot-password', ['email' => 'admin@rifaint.com']);
        $token = $this->tokenFromEmail();
        $this->assertNotSame($token, $this->db->fetchValue('SELECT token_hash FROM password_resets'), 'only the hash is stored');

        $reset = $this->request('POST', '/api/v1/admin/auth/reset-password', ['token' => $token, 'password' => 'Brand-New-Pass-7']);
        $this->assertSame(200, $reset->status(), $reset->body());

        $this->assertSame(401, $this->request('GET', '/api/v1/admin/auth/me')->status(), 'old session invalidated');
        $this->assertSame(200, $this->login(password: 'Brand-New-Pass-7')->status());

        $again = $this->request('POST', '/api/v1/admin/auth/reset-password', ['token' => $token, 'password' => 'Another-Pass-77']);
        $this->assertSame(['token' => 'This reset link is invalid or has expired.'], $again->decodedJson()['error']['fields']);
        $this->assertSame('auth.password_reset', $this->db->fetchValue("SELECT action FROM audit_logs WHERE action = 'auth.password_reset'"));
    }

    public function test_reset_link_expires_after_30_minutes_and_newer_link_replaces_older(): void
    {
        $this->request('POST', '/api/v1/admin/auth/forgot-password', ['email' => 'admin@rifaint.com']);
        $first = $this->tokenFromEmail();
        $this->request('POST', '/api/v1/admin/auth/forgot-password', ['email' => 'admin@rifaint.com']);
        $second = $this->tokenFromEmail(1);

        $old = $this->request('POST', '/api/v1/admin/auth/reset-password', ['token' => $first, 'password' => 'Brand-New-Pass-7']);
        $this->assertSame(422, $old->status());

        $this->clock()->freeze('2026-10-20 10:30:01');
        $expired = $this->request('POST', '/api/v1/admin/auth/reset-password', ['token' => $second, 'password' => 'Brand-New-Pass-7']);
        $this->assertSame(422, $expired->status());
    }

    private function tokenFromEmail(int $index = 0): string
    {
        $mail = $this->mailer()->outbox()[$index];
        $this->assertSame(['admin@rifaint.com'], $mail['to']);
        $this->assertSame(1, preg_match('/reset-password\?token=([a-f0-9]{64})/', $mail['html'], $m));

        return $m[1];
    }
}
