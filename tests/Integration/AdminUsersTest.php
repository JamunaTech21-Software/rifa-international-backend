<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Security\CsrfTokenManager;
use App\Shared\Security\Password;

final class AdminUsersTest extends IntegrationTestCase
{
    private const BASE = '/api/v1/admin/users';

    private int $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze('2026-10-21 15:00:00');
        $this->me = $this->db->insert('admins', [
            'name' => 'Samin', 'email' => 'samin@rifaint.com', 'password_hash' => 'x', 'role' => 'super_admin',
        ]);
        $this->signIn($this->me);
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

    private function create(array $body): \App\Core\Response
    {
        return $this->request('POST', self::BASE, $body, $this->csrf());
    }

    public function test_staff_cannot_manage_users(): void
    {
        $staff = $this->createAdmin('staff');
        $this->signIn($staff);

        $this->assertSame(403, $this->request('GET', self::BASE)->status());
    }

    public function test_create_and_list(): void
    {
        $response = $this->create(['name' => 'Nusrat', 'email' => 'Nusrat@RifaInt.com', 'role' => 'staff', 'password' => 'Strong-Pass-1']);

        $this->assertSame(201, $response->status(), $response->body());
        $user = $response->decodedJson()['data'];
        $this->assertSame([
            'id' => $user['id'], 'name' => 'Nusrat', 'email' => 'nusrat@rifaint.com', 'role' => 'staff', 'is_active' => true,
            'is_locked' => false, 'last_login_at' => null, 'created_at' => '2026-10-21T15:00:00+06:00',
        ], $user);
        $this->assertTrue(Password::verify('Strong-Pass-1', (string) $this->db->fetchValue('SELECT password_hash FROM admins WHERE id = ?', [$user['id']])));

        $emails = array_column($this->request('GET', self::BASE)->decodedJson()['data'], 'email');
        $this->assertSame(['nusrat@rifaint.com', 'samin@rifaint.com'], $emails);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'users.created'"));

        $dupe = $this->create(['name' => 'Again', 'email' => 'nusrat@rifaint.com', 'role' => 'staff', 'password' => 'Strong-Pass-1']);
        $this->assertSame(['email' => 'An admin with this email already exists.'], $dupe->decodedJson()['error']['fields']);

        $bad = $this->create(['name' => '', 'email' => 'x', 'role' => 'owner', 'password' => 'short']);
        $this->assertSame([
            'name' => 'Please enter a name.',
            'email' => 'Enter a valid email address.',
            'role' => 'Role must be super admin or staff.',
            'password' => 'Password must be 10–200 characters.',
        ], $bad->decodedJson()['error']['fields']);
    }

    public function test_update_name_role_active_and_password_logs_user_out(): void
    {
        $id = $this->createAdmin('staff');

        $updated = $this->request('PATCH', self::BASE . "/{$id}", ['name' => 'Renamed', 'role' => 'super_admin'], $this->csrf())->decodedJson()['data'];
        $this->assertSame('Renamed', $updated['name']);
        $this->assertSame('super_admin', $updated['role']);

        $version = (int) $this->db->fetchValue('SELECT session_version FROM admins WHERE id = ?', [$id]);
        $this->request('PATCH', self::BASE . "/{$id}", ['password' => 'New-Strong-Pass'], $this->csrf());
        $this->assertSame($version + 1, (int) $this->db->fetchValue('SELECT session_version FROM admins WHERE id = ?', [$id]));

        $off = $this->request('PATCH', self::BASE . "/{$id}", ['is_active' => false], $this->csrf())->decodedJson()['data'];
        $this->assertFalse($off['is_active']);

        $audit = $this->db->fetchAll("SELECT meta FROM audit_logs WHERE action = 'users.updated' ORDER BY id");
        $this->assertSame(['name', 'role'], json_decode($audit[0]['meta'], true)['changed']);
        $this->assertStringNotContainsString('New-Strong-Pass', $audit[1]['meta']);
    }

    public function test_safety_rules(): void
    {
        $self = $this->request('PATCH', self::BASE . "/{$this->me}", ['is_active' => false], $this->csrf());
        $this->assertSame(['is_active' => 'You cannot deactivate your own account.'], $self->decodedJson()['error']['fields']);

        $ownPassword = $this->request('PATCH', self::BASE . "/{$this->me}", ['password' => 'Whatever-123'], $this->csrf());
        $this->assertSame(['password' => 'Use "Change password" to change your own password.'], $ownPassword->decodedJson()['error']['fields']);

        $demoteLast = $this->request('PATCH', self::BASE . "/{$this->me}", ['role' => 'staff'], $this->csrf());
        $this->assertSame(['role' => 'There must be at least one active super admin.'], $demoteLast->decodedJson()['error']['fields']);

        $this->assertSame(404, $this->request('PATCH', self::BASE . '/999999', ['name' => 'X Y'], $this->csrf())->status());

        $this->createAdmin('super_admin');
        $this->assertSame(200, $this->request('PATCH', self::BASE . "/{$this->me}", ['role' => 'staff'], $this->csrf())->status());
        $this->assertSame(403, $this->request('GET', self::BASE)->status(), 'demoted admin loses access immediately');
    }
}
