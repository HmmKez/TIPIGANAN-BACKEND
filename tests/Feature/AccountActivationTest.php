<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SchoolRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Accounts are activated at the library: staff look the ID number up on the
 * school's list, confirm the name against the school ID card, and the person
 * types their own email and password. There is no self-registration.
 */
class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.directory.driver' => 'csv']);
        foreach (SchoolRoster::ROLES as $r) {
            Role::findOrCreate($r);
        }
        // As PermissionSeeder and the migration set it up.
        Permission::findOrCreate('activate_accounts');
        Role::findByName('staff')->givePermissionTo('activate_accounts');
        Role::findByName('super_admin')->givePermissionTo('activate_accounts');

        SchoolRoster::replace([
            ['id_number' => '90001', 'first_name' => 'Super', 'last_name' => 'Admin', 'role' => 'super_admin'],
            ['id_number' => '90002', 'first_name' => 'Library', 'last_name' => 'Staff', 'role' => 'staff'],
            ['id_number' => '10001', 'first_name' => 'Kent', 'last_name' => 'Concha', 'role' => 'student'],
            ['id_number' => '20001', 'first_name' => 'Angela', 'last_name' => 'Lenteria', 'role' => 'teacher'],
            ['id_number' => '20002', 'first_name' => 'Josefina', 'last_name' => null, 'role' => 'staff'],
            ['id_number' => '00042', 'first_name' => 'Rowena', 'last_name' => 'Mae', 'role' => 'student'],
        ]);
    }

    private function account(string $id, string $role = 'student', string $password = 'Secret123'): User
    {
        $u = User::create(['id_number' => $id, 'email' => "u{$id}@example.com", 'password' => Hash::make($password),
            'role' => $role, 'status' => 'active']);
        $u->assignRole($role);

        return $u;
    }

    private function staff(): User
    {
        return $this->account('90002', 'staff');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id_number'             => '10001',
            'name_confirmed'        => true,
            'email'                 => 'kent@example.com',
            'password'              => 'MyOwnPass9',
            'password_confirmation' => 'MyOwnPass9',
        ], $overrides);
    }

    public function test_there_is_no_self_registration_any_more(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertNotFound();
        $this->postJson('/api/auth/register/verify', ['id_number' => '10001'])->assertNotFound();
        $this->getJson('/api/auth/register/options')->assertNotFound();
        $this->assertDatabaseMissing('users', ['id_number' => '10001']);
    }

    public function test_staff_look_up_an_id_and_see_the_name_on_the_list(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])
            ->assertOk()
            ->assertJsonPath('full_name', 'Kent Concha')
            ->assertJsonPath('role', 'student')
            ->assertJsonPath('earlier_account', false);
    }

    public function test_an_id_not_on_the_list_cannot_be_activated(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '55555'])
            ->assertStatus(422)->assertJsonValidationErrors('id_number');
        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->payload(['id_number' => '55555']))
            ->assertStatus(422)->assertJsonValidationErrors('id_number');
        $this->assertDatabaseMissing('users', ['id_number' => '55555']);
    }

    public function test_nothing_can_be_activated_before_a_list_is_imported(): void
    {
        $staff = $this->staff();
        SchoolRoster::replace([]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])
            ->assertStatus(422)->assertJsonValidationErrors('id_number');
    }

    public function test_activating_creates_the_account_and_the_person_signs_in_with_their_own_password(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->payload())
            ->assertCreated()->assertJsonPath('user.role', 'student');

        $user = User::where('id_number', '10001')->first();
        $this->assertSame('Kent Concha', $user->name, 'the name comes from the school list');
        $this->assertSame('kent@example.com', $user->email);
        $this->assertTrue($user->hasRole('student'));
        $this->assertFalse($user->must_change_password, 'they chose the password themselves');
        $this->assertTrue(AuditLog::where('action', 'activate_account')->where('user_id', $staff->id)->where('target_id', $user->id)->exists(),
            'the audit trail records which staff member activated it');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['id_number' => '10001', 'password' => 'MyOwnPass9'])->assertOk();
    }

    public function test_the_name_must_be_confirmed_against_the_school_id_card(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/accounts/activation', $this->payload(['name_confirmed' => false]))
            ->assertStatus(422)->assertJsonValidationErrors('name_confirmed');
        $this->assertDatabaseMissing('users', ['id_number' => '10001']);
    }

    public function test_the_password_must_be_strong_and_typed_twice(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->payload([
            'password' => 'weak', 'password_confirmation' => 'weak',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->payload([
            'password_confirmation' => 'Different9',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        $this->account('20001', 'teacher')->update(['email' => 'taken@example.com']);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/accounts/activation', $this->payload(['email' => 'taken@example.com']))
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_an_id_that_already_has_an_active_account_cannot_be_activated_again(): void
    {
        $this->account('10001');

        $this->actingAs($this->staff(), 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])
            ->assertStatus(422)->assertJsonValidationErrors('id_number');
    }

    public function test_an_account_deactivated_by_staff_is_not_reactivated_here(): void
    {
        $this->account('10001')->update(['status' => 'deactivated']);

        $r = $this->actingAs($this->staff(), 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])
            ->assertStatus(422);
        $this->assertStringContainsString('User Management', $r->json('errors.id_number.0'));
    }

    public function test_staff_and_super_admin_rows_become_teacher_accounts_without_staff_powers(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/accounts/activation', $this->payload(['id_number' => '20002', 'email' => 'jo@example.com']))
            ->assertCreated()->assertJsonPath('user.role', 'teacher');

        $this->assertFalse(User::where('id_number', '20002')->first()->hasRole('staff'));
    }

    public function test_an_id_with_a_leading_zero_is_activated_and_signs_in(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/accounts/activation', $this->payload(['id_number' => '00042', 'email' => 'ro@example.com']))
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['id_number' => '00042', 'password' => 'MyOwnPass9'])->assertOk();
    }

    public function test_the_id_number_must_be_five_digits(): void
    {
        $staff = $this->staff();
        foreach (['1234', '123456', 'abcde', ''] as $bad) {
            $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('id_number');
        }
    }

    public function test_only_staff_with_the_permission_can_activate(): void
    {
        $student = $this->account('10001');
        $this->actingAs($student, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '20001'])
            ->assertForbidden();

        $staff = $this->staff();
        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '20001'])->assertOk();

        // Without the permission (taken off the staff role), the endpoint refuses.
        Role::findByName('staff', 'web')->revokePermissionTo('activate_accounts');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff->fresh(), 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '20001'])
            ->assertForbidden();
    }

    // --- Accounts from the old self-registration ---------------------------------

    public function test_an_account_waiting_for_activation_cannot_sign_in_and_is_signed_out(): void
    {
        $user = $this->account('10001');
        $token = $user->createToken('t')->plainTextToken;
        $user->update(['needs_activation' => true]);

        $this->postJson('/api/auth/login', ['id_number' => '10001', 'password' => 'Secret123'])
            ->assertStatus(403)->assertJsonPath('reason', 'not_activated');

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('reason', 'not_activated');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_activating_an_earlier_account_keeps_it_and_gives_it_a_new_email_and_password(): void
    {
        $old = $this->account('10001', 'student', 'OldPass99');
        $old->update(['needs_activation' => true]);

        $staff = $this->staff();
        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])
            ->assertOk()->assertJsonPath('earlier_account', true);
        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->payload())
            ->assertOk();

        $this->assertSame(1, User::where('id_number', '10001')->count(), 'the same account, not a second one');
        $user = $old->fresh();
        $this->assertFalse($user->needs_activation);
        $this->assertSame('kent@example.com', $user->email);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['id_number' => '10001', 'password' => 'OldPass99'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['id_number' => '10001', 'password' => 'MyOwnPass9'])->assertOk();
    }

    public function test_the_command_requires_activation_for_everyone_except_the_kept_accounts(): void
    {
        $keep = $this->account('90001', 'super_admin');
        $other = $this->account('10001');
        $other->createToken('t');

        $this->artisan('accounts:require-activation')->assertFailed();   // nobody named to keep
        $this->artisan('accounts:require-activation', ['--keep' => ['90001'], '--force' => true])->assertSuccessful();

        $this->assertFalse($keep->fresh()->needs_activation);
        $this->assertTrue($other->fresh()->needs_activation);
        $this->assertSame(0, $other->tokens()->count());
    }
}
