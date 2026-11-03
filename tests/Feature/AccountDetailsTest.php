<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SchoolRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The smaller account rules: a reset ends old sessions, an old directory mark
 * cannot refuse someone just activated, the password length limit, and counts
 * that only include accounts in use.
 */
class AccountDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.directory.driver' => 'csv']);
        foreach (SchoolRoster::ROLES as $r) {
            Role::findOrCreate($r);
        }
        foreach (['activate_accounts', 'reset_passwords'] as $p) {
            Permission::findOrCreate($p);
            Role::findByName('staff')->givePermissionTo($p);
            Role::findByName('super_admin')->givePermissionTo($p);
        }
    }

    private function account(string $id, string $role, array $extra = []): User
    {
        $u = User::create(['id_number' => $id, 'email' => "u{$id}@example.com", 'password' => Hash::make('Secret123'), 'role' => $role]);
        $u->forceFill($extra + ['status' => 'active'])->save();
        $u->assignRole($role);

        return $u;
    }

    private function bearer(string $id, string $password = 'Secret123'): array
    {
        return ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', ['id_number' => $id, 'password' => $password])->assertOk()->json('token')];
    }

    private function reset(User $target, array $headers, string $password = 'NewPass123')
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson("/api/users/{$target->id}/reset-password",
            ['password' => $password, 'password_confirmation' => $password], $headers);
    }

    public function test_a_password_reset_signs_the_person_out_everywhere(): void
    {
        $this->account('90002', 'staff');
        $student = $this->account('10001', 'student');
        $theirs = $this->bearer('10001');
        $staff = $this->bearer('90002');

        $this->getJson('/api/auth/me', $theirs)->assertOk();
        $this->reset($student, $staff)->assertOk();

        $this->assertSame(0, $student->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $theirs)->assertUnauthorized();
        $this->bearer('10001', 'NewPass123');
    }

    public function test_resetting_your_own_password_keeps_your_session_but_ends_the_others(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $elsewhere = $this->bearer('90001');
        $here = $this->bearer('90001');

        $this->reset($admin, $here)->assertOk();

        $this->assertSame(1, $admin->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $here)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $elsewhere)->assertUnauthorized();
    }

    public function test_activation_clears_an_old_directory_mark(): void
    {
        $this->account('90002', 'staff');
        $old = $this->account('10001', 'student', ['needs_activation' => true, 'directory_status' => 'graduated', 'directory_checked_at' => now()]);
        SchoolRoster::replace([
            ['id_number' => '90002', 'first_name' => 'Library', 'last_name' => 'Staff', 'role' => 'staff'],
            ['id_number' => '10001', 'first_name' => 'Kent', 'last_name' => 'Concha', 'role' => 'student'],
        ]);

        $this->postJson('/api/accounts/activation', [
            'id_number' => '10001', 'name_confirmed' => true, 'email' => 'kent@example.com',
            'password' => 'MyOwnPass9', 'password_confirmation' => 'MyOwnPass9',
        ], $this->bearer('90002'))->assertOk();

        $this->assertNull($old->fresh()->directory_status);
        $this->bearer('10001', 'MyOwnPass9');
    }

    public function test_a_password_over_72_bytes_is_refused_everywhere_it_can_be_set(): void
    {
        $this->account('90002', 'staff');
        $student = $this->account('10001', 'student');
        $staff = $this->bearer('90002');

        $tooLong = 'Aa1'.str_repeat('x', 70);
        $this->reset($student, $staff, $tooLong)->assertStatus(422)->assertJsonValidationErrors('password');

        // Fewer than 72 characters, more than 72 bytes: ñ takes two.
        $this->reset($student, $staff, 'Aa1'.str_repeat('ñ', 40))->assertStatus(422);

        $this->reset($student, $staff, 'Aa1'.str_repeat('x', 69))->assertOk();
        $this->bearer('10001', 'Aa1'.str_repeat('x', 69));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/change-password', [
            'current_password' => 'Aa1'.str_repeat('x', 69), 'password' => $tooLong, 'password_confirmation' => $tooLong,
        ], $this->bearer('10001', 'Aa1'.str_repeat('x', 69)))->assertStatus(422);
    }

    public function test_the_list_is_enforced_when_the_setting_is_missing(): void
    {
        $file = file_get_contents(config_path('school.php'));

        $this->assertStringContainsString("env('SCHOOL_DIRECTORY_DRIVER', 'csv')", $file);
    }

    public function test_two_desks_using_one_email_at_once_get_a_clear_message(): void
    {
        $this->account('90002', 'staff');
        SchoolRoster::replace([
            ['id_number' => '90002', 'first_name' => 'Library', 'last_name' => 'Staff', 'role' => 'staff'],
            ['id_number' => '10001', 'first_name' => 'Kent', 'last_name' => 'Concha', 'role' => 'student'],
        ]);
        $headers = $this->bearer('90002');

        // The other desk saves that email after this request has passed its checks.
        User::creating(function (User $u) {
            if ($u->id_number === '10001') {
                User::withoutEvents(fn () => User::create(['id_number' => '10002', 'email' => $u->email, 'password' => 'x', 'role' => 'student', 'status' => 'active']));
            }
        });

        $response = $this->postJson('/api/accounts/activation', [
            'id_number' => '10001', 'name_confirmed' => true, 'email' => 'same@example.com',
            'password' => 'MyOwnPass9', 'password_confirmation' => 'MyOwnPass9',
        ], $headers);
        User::flushEventListeners();

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertFalse(User::where('id_number', '10001')->exists());
    }

    public function test_a_failed_import_leaves_the_old_list_and_every_account_as_they_were(): void
    {
        $student = $this->account('10001', 'student');
        $student->update(['name' => 'Kent Concha']);
        $this->account('10002', 'student');
        $changer = $this->account('10003', 'student');
        $changer->update(['name' => 'Role Changer']);
        $old = ['id_number' => '10001', 'first_name' => 'Kent', 'last_name' => 'Concha', 'role' => 'student'];
        SchoolRoster::replace([$old]);

        // Fails at the last stage, after accounts were switched off and renamed.
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            if (str_contains($query->sql, 'delete from "model_has_roles"')) {
                throw new \RuntimeException('disk full');
            }
        });

        try {
            SchoolRoster::import([
                ['id_number' => '10001', 'first_name' => 'Someone', 'last_name' => 'Else', 'role' => 'student'],
                ['id_number' => '10003', 'first_name' => 'Role', 'last_name' => 'Changer', 'role' => 'teacher'],
                ['id_number' => '20001', 'first_name' => 'New', 'last_name' => 'Person', 'role' => 'teacher'],
            ]);
            $this->fail('The import should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame('student', $changer->fresh()->role);
        $this->assertSame(1, SchoolRoster::summary()['total']);
        $this->assertSame('Kent', SchoolRoster::find('10001')->first_name);
        $this->assertFalse(SchoolRoster::contains('20001'));
        $this->assertSame('active', User::where('id_number', '10002')->first()->status, 'The account switched off first is switched back.');
        $this->assertFalse((bool) $student->fresh()->needs_activation);
    }

    public function test_user_totals_count_only_accounts_in_use(): void
    {
        $this->account('90002', 'staff');
        $this->account('10001', 'student');
        $this->account('10002', 'student', ['needs_activation' => true]);

        $this->getJson('/api/reports/dashboard', $this->bearer('90002'))
            ->assertOk()->assertJsonPath('total_users', 2);
    }
}
