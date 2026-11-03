<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Favorite;
use App\Models\User;
use App\Support\SchoolRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Going live, and what a new school list does to accounts already in use: the
 * first Super Admin, name and role changes, reused ID numbers, and the smaller
 * rules around activation and User Management.
 */
class SchoolListChangesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.directory.driver' => 'csv']);
        foreach (SchoolRoster::ROLES as $r) {
            Role::findOrCreate($r);
        }
        foreach (['activate_accounts', 'reset_passwords', 'delete_accounts'] as $p) {
            Permission::findOrCreate($p);
            Role::findByName('staff')->givePermissionTo($p);
            Role::findByName('super_admin')->givePermissionTo($p);
        }
    }

    private function account(string $id, string $role, ?string $name = null, array $extra = []): User
    {
        $u = User::create(['id_number' => $id, 'name' => $name, 'email' => "u{$id}@example.com",
            'password' => Hash::make('Secret123'), 'role' => $role]);
        $u->forceFill($extra + ['status' => 'active'])->save();
        $u->assignRole($role);

        return $u;
    }

    private function roster(array $rows): void
    {
        SchoolRoster::replace(array_map(
            fn ($r) => array_combine(['id_number', 'first_name', 'last_name', 'role'], $r), $rows));
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn ($r) => implode(',', $r), $rows);

        return UploadedFile::fake()->createWithContent('list.csv', "id_number,first_name,last_name,role\n".implode("\n", $lines)."\n");
    }

    private function csvPath(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roster');
        file_put_contents($path, $this->csv($rows)->getContent());

        return $path;
    }

    private function import(User $admin, array $rows)
    {
        return $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import', ['file' => $this->csv($rows), 'confirm_smaller' => 1]);
    }

    private function details(string $id, array $extra = []): array
    {
        return $extra + ['id_number' => $id, 'name_confirmed' => true, 'email' => "new{$id}@example.com",
            'password' => 'MyOwnPass9', 'password_confirmation' => 'MyOwnPass9'];
    }

    private function runCreateCommand(string $id, ?string $name, string $email = 'lib@example.com', string $password = 'Librarian9', string $confirm = 'Librarian9')
    {
        $command = $this->artisan('accounts:create-super-admin')->expectsQuestion('ID number', $id);
        if ($name !== null) {
            $command->expectsQuestion('Name', $name);
        }

        return $command->expectsQuestion('Email', $email)
            ->expectsQuestion('Password (nothing shows while typing)', $password)
            ->expectsQuestion('Confirm password', $confirm);
    }

    // --- The first Super Admin -------------------------------------------------

    public function test_the_command_creates_the_first_super_admin_who_can_then_import(): void
    {
        $this->runCreateCommand('54321', 'Maria Santos')->assertSuccessful();

        $admin = User::where('id_number', '54321')->firstOrFail();
        $this->assertSame('super_admin', $admin->role);
        $this->assertTrue($admin->hasRole('super_admin'));

        $this->postJson('/api/auth/login', ['id_number' => '54321', 'password' => 'Librarian9'])->assertOk();

        $this->import($admin, [['54321', 'Maria', 'Santos', 'super_admin'], ['10001', 'Kent', 'Concha', 'student']])->assertOk();
        $this->assertFalse((bool) $admin->fresh()->needs_activation);
    }

    public function test_the_command_refuses_once_an_active_super_admin_exists(): void
    {
        $this->account('90001', 'super_admin');

        $this->artisan('accounts:create-super-admin')->assertFailed();
        $this->assertSame(1, User::where('role', 'super_admin')->count());
    }

    public function test_the_command_refuses_bad_details_and_creates_nothing(): void
    {
        $this->account('10001', 'student');

        $this->runCreateCommand('123', 'Maria')->assertFailed();
        $this->runCreateCommand('10001', 'Maria')->assertFailed();
        $this->runCreateCommand('54321', 'Maria', 'u10001@example.com')->assertFailed();
        $this->runCreateCommand('54321', 'Maria', 'lib@example.com', 'weak')->assertFailed();
        $this->runCreateCommand('54321', 'Maria', 'lib@example.com', 'Librarian9', 'Different9')->assertFailed();

        $this->assertSame(0, User::where('role', 'super_admin')->count());
    }

    public function test_with_a_list_loaded_the_id_must_be_on_it_and_the_name_comes_from_it(): void
    {
        $this->roster([['54321', 'Maria', 'Santos', 'staff']]);

        $this->artisan('accounts:create-super-admin')->expectsQuestion('ID number', '99999')->assertFailed();

        $this->runCreateCommand('54321', null)->assertSuccessful();
        $this->assertSame('Maria Santos', User::where('id_number', '54321')->first()->name);
    }

    public function test_the_import_command_refuses_a_list_that_leaves_no_super_admin(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');

        // Not on the list.
        $this->artisan('school:import-roster', ['file' => $this->csvPath([['10001', 'Kent', 'Concha', 'student']])])->assertFailed();
        // On it, under another name, so they would have to be activated again.
        $this->artisan('school:import-roster', ['file' => $this->csvPath([['90001', 'Someone', 'Else', 'super_admin']])])->assertFailed();

        $this->assertFalse(SchoolRoster::isLoaded());
        $this->assertSame('active', $admin->fresh()->status);

        $this->artisan('school:import-roster', ['file' => $this->csvPath([['90001', 'Super', 'Admin', 'super_admin']])])->assertSuccessful();
    }

    public function test_demo_accounts_are_not_created_on_a_live_system(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => \Database\Seeders\UserSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertSame(0, User::count());

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, User::count());
        $this->assertTrue(Role::where('name', 'super_admin')->exists());
    }

    // --- A new list: names and roles -------------------------------------------

    public function test_a_changed_name_requires_activation_again_and_signs_the_person_out(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $person = $this->account('10001', 'student', 'Old Graduate');
        $token = $person->createToken('t')->plainTextToken;

        $this->import($admin, [['90001', 'Super', 'Admin', 'super_admin'], ['10001', 'Brand', 'New', 'student']])
            ->assertOk()->assertJsonPath('needs_activation', ['10001']);

        $person->refresh();
        $this->assertTrue($person->needs_activation);
        $this->assertSame(0, $person->tokens()->count());
        $this->assertSame('Old Graduate', $person->name, 'The name updates at activation.');

        $this->postJson('/api/auth/login', ['id_number' => '10001', 'password' => 'Secret123'])
            ->assertForbidden()->assertJsonPath('reason', 'not_activated');
    }

    public function test_capitals_and_spacing_are_not_a_changed_name(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $person = $this->account('10001', 'student', 'kent   CONCHA');

        $this->import($admin, [['90001', 'Super', 'Admin', 'super_admin'], ['10001', 'Kent', 'Concha', 'student']])
            ->assertJsonPath('needs_activation', []);

        $this->assertFalse((bool) $person->fresh()->needs_activation);
    }

    public function test_an_account_with_no_name_yet_just_takes_the_lists_name(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $person = $this->account('10001', 'student');

        $this->import($admin, [['90001', 'Super', 'Admin', 'super_admin'], ['10001', 'Kent', 'Concha', 'student']])->assertOk();

        $this->assertSame('Kent Concha', $person->fresh()->name);
        $this->assertFalse((bool) $person->fresh()->needs_activation);
    }

    public function test_the_person_importing_keeps_access_and_their_name_updates(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Maria Santos');

        $rows = [['90001', 'Maria', 'Santos-Cruz', 'super_admin']];
        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/preview', ['file' => $this->csv($rows)])
            ->assertJsonPath('your_new_name', 'Maria Santos-Cruz')
            ->assertJsonPath('name_changes_total', 0);

        $this->import($admin, $rows)->assertOk();

        $this->assertSame('Maria Santos-Cruz', $admin->fresh()->name);
        $this->assertFalse((bool) $admin->fresh()->needs_activation);
    }

    public function test_an_account_back_on_the_list_under_another_name_is_not_switched_back_on(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $person = $this->account('10001', 'student', 'Old Graduate');
        $self = [['90001', 'Super', 'Admin', 'super_admin']];

        $this->import($admin, $self)->assertJsonPath('switched_off', ['10001']);

        $this->import($admin, [...$self, ['10001', 'Brand', 'New', 'student']])
            ->assertJsonPath('switched_on', [])
            ->assertJsonPath('needs_activation', ['10001']);

        $this->postJson('/api/auth/login', ['id_number' => '10001', 'password' => 'Secret123'])
            ->assertForbidden()->assertJsonPath('reason', 'not_activated');
    }

    public function test_student_and_teacher_roles_follow_the_list_but_staff_powers_never_change(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $student = $this->account('10001', 'student', 'Kent Concha');
        $teacher = $this->account('20001', 'teacher', 'Angela Lenteria');
        $staff = $this->account('90002', 'staff', 'Library Staff');
        $promoted = $this->account('20002', 'teacher', 'Jo Reyes');

        $this->import($admin, [
            ['90001', 'Super', 'Admin', 'student'],
            ['10001', 'Kent', 'Concha', 'teacher'],
            ['20001', 'Angela', 'Lenteria', 'student'],
            ['90002', 'Library', 'Staff', 'student'],
            ['20002', 'Jo', 'Reyes', 'staff'],
        ])->assertOk();

        $this->assertSame('teacher', $student->fresh()->role);
        $this->assertTrue($student->fresh()->hasRole('teacher'));
        $this->assertFalse($student->fresh()->hasRole('student'));
        $this->assertSame('student', $teacher->fresh()->role);
        $this->assertSame('staff', $staff->fresh()->role, 'A promotion is not undone by a list.');
        $this->assertSame('super_admin', $admin->fresh()->role);
        $this->assertSame('teacher', $promoted->fresh()->role, 'A list never gives staff powers.');
    }

    public function test_a_large_import_changes_only_the_accounts_it_should(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $bystander = $this->account('10000', 'student', 'Stays Same');
        $bystanderToken = $bystander->createToken('t');

        $leaving = $this->account('10001', 'student', 'Leaving Person');
        $renamed = $this->account('10002', 'student', 'Old Name');
        $changer = $this->account('10003', 'student', 'Role Changer');
        $returning = $this->account('10004', 'student', 'Back Again', ['status' => 'deactivated', 'deactivated_by_roster' => true]);
        foreach ([$leaving, $renamed, $changer] as $u) {
            $u->createToken('t');
        }
        $oldStamp = $changer->fresh()->updated_at;
        $this->travel(1)->minutes();

        $this->import($admin, [
            ['90001', 'Super', 'Admin', 'super_admin'], ['10000', 'Stays', 'Same', 'student'],
            ['10002', 'New', 'Name', 'student'], ['10003', 'Role', 'Changer', 'teacher'], ['10004', 'Back', 'Again', 'student'],
        ])->assertOk()
            ->assertJsonPath('switched_off', ['10001'])
            ->assertJsonPath('needs_activation', ['10002'])
            ->assertJsonPath('role_changed', ['10003'])
            ->assertJsonPath('switched_on', ['10004']);

        $leaving->refresh();
        $this->assertSame('deactivated', $leaving->status);
        $this->assertTrue($leaving->deactivated_by_roster);
        $this->assertSame(0, $leaving->tokens()->count());

        $renamed->refresh();
        $this->assertTrue($renamed->needs_activation);
        $this->assertSame(0, $renamed->tokens()->count());

        // Role column and permission table together, and only the one role.
        $changer = User::find($changer->id);
        $this->assertSame('teacher', $changer->role);
        $this->assertSame(['teacher'], $changer->getRoleNames()->all());
        $this->assertSame(1, $changer->tokens()->count(), 'A role change does not sign the person out.');
        $this->assertTrue($changer->updated_at->gt($oldStamp));

        $this->assertSame('active', $returning->fresh()->status);
        $this->assertFalse($returning->fresh()->deactivated_by_roster);

        $this->assertSame(1, $bystander->tokens()->count());
        $this->assertSame(['student'], User::find($bystander->id)->getRoleNames()->all());
    }

    public function test_a_hand_deactivated_account_stays_deactivated_when_its_name_changes(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $person = $this->account('10001', 'student', 'Old Name', ['status' => 'deactivated']);

        $this->import($admin, [['90001', 'Super', 'Admin', 'super_admin'], ['10001', 'New', 'Name', 'student']])->assertOk();

        $this->assertSame('deactivated', $person->fresh()->status);
        $this->assertTrue($person->fresh()->needs_activation);
    }

    public function test_the_preview_gives_true_totals_beyond_the_fifty_it_lists(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        for ($i = 0; $i < 60; $i++) {
            $this->account((string) (10000 + $i), 'student', 'Leaving Person');
        }
        $this->roster([['90001', 'Super', 'Admin', 'super_admin']]);

        $preview = $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/preview', [
            'file' => $this->csv([['90001', 'Super', 'Admin', 'super_admin']]),
        ])->assertOk();

        $preview->assertJsonPath('would_lose_access_total', 60);
        $this->assertCount(50, $preview->json('would_lose_access'));

        $this->actingAs($admin, 'sanctum')->getJson('/api/school-roster')
            ->assertJsonPath('accounts_not_on_list_total', 60);
    }

    // --- At the desk -----------------------------------------------------------

    public function test_a_changed_name_must_be_answered_at_the_desk(): void
    {
        $staff = $this->account('90002', 'staff', 'Library Staff');
        $this->account('10001', 'student', 'Old Name', ['needs_activation' => true]);
        $this->roster([['90002', 'Library', 'Staff', 'staff'], ['10001', 'New', 'Name', 'student']]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])
            ->assertOk()->assertJsonPath('previous_name', 'Old Name')->assertJsonPath('full_name', 'New Name');

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->details('10001'))
            ->assertStatus(422)->assertJsonValidationErrors('same_person');
    }

    public function test_same_person_keeps_the_account_bookmarks_and_takes_the_new_name(): void
    {
        $staff = $this->account('90002', 'staff', 'Library Staff');
        $person = $this->account('10001', 'student', 'Old Name', ['needs_activation' => true]);
        $this->roster([['90002', 'Library', 'Staff', 'staff'], ['10001', 'New', 'Name', 'student']]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->details('10001', ['same_person' => true]))->assertOk();

        $this->assertSame($person->id, User::where('id_number', '10001')->first()->id);
        $this->assertSame('New Name', $person->fresh()->name);
        $this->assertFalse($person->fresh()->needs_activation);
    }

    public function test_different_person_starts_new_and_the_old_account_keeps_its_records_but_is_closed(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $old = $this->account('90002', 'staff', 'Old Staff', ['needs_activation' => true]);
        $old->createToken('t');
        AuditLog::create(['user_id' => $old->id, 'action' => 'login', 'target_type' => 'user', 'target_id' => $old->id, 'description' => 'old login']);
        $this->roster([['90001', 'Super', 'Admin', 'super_admin'], ['90002', 'New', 'Person', 'student']]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/accounts/activation', $this->details('90002', ['same_person' => false]))
            ->assertCreated();

        $new = User::where('id_number', '90002')->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('New Person', $new->name);
        $this->assertSame('student', $new->role, 'The old powers do not pass to the new person.');
        $this->assertSame(0, AuditLog::where('user_id', $new->id)->where('action', 'login')->count());

        $old->refresh();
        $this->assertNull($old->id_number);
        $this->assertNull($old->email);
        $this->assertSame('Old Staff', $old->name);
        $this->assertSame('deactivated', $old->status);
        $this->assertFalse($old->hasRole('staff'));
        $this->assertSame(0, $old->tokens()->count());
        $this->assertSame(1, AuditLog::where('user_id', $old->id)->where('action', 'login')->count());
        $this->assertTrue(AuditLog::where('action', 'activate_account')->where('description', 'like', '%different person%')->exists());

        $this->actingAs($admin, 'sanctum')->getJson('/api/users?search=Old')->assertJsonCount(0, 'data');
        $this->postJson('/api/auth/login', ['id_number' => '90002', 'password' => 'Secret123'])->assertStatus(422);
    }

    public function test_staff_cannot_activate_a_waiting_account_staff_deactivated(): void
    {
        $staff = $this->account('90002', 'staff', 'Library Staff');
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $off = $this->account('10001', 'student', 'Kent Concha', ['needs_activation' => true, 'status' => 'deactivated']);
        $this->roster([['90001', 'Super', 'Admin', 'super_admin'], ['90002', 'Library', 'Staff', 'staff'], ['10001', 'Kent', 'Concha', 'student']]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])->assertStatus(422);
        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->details('10001'))->assertStatus(422);
        $this->assertSame('deactivated', $off->fresh()->status);

        $this->actingAs($admin, 'sanctum')->postJson('/api/accounts/activation', $this->details('10001'))->assertOk();
        $this->assertSame('active', $off->fresh()->status);
    }

    public function test_a_waiting_account_the_list_switched_off_can_still_be_activated_by_staff(): void
    {
        $staff = $this->account('90002', 'staff', 'Library Staff');
        $this->account('10001', 'student', 'Kent Concha', ['needs_activation' => true, 'status' => 'deactivated', 'deactivated_by_roster' => true]);
        $this->roster([['90002', 'Library', 'Staff', 'staff'], ['10001', 'Kent', 'Concha', 'student']]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->details('10001'))->assertOk();
    }

    public function test_activation_gives_an_earlier_account_the_lists_role(): void
    {
        $staff = $this->account('90002', 'staff', 'Library Staff');
        $person = $this->account('10001', 'teacher', 'Kent Concha', ['needs_activation' => true]);
        $this->roster([['90002', 'Library', 'Staff', 'staff'], ['10001', 'Kent', 'Concha', 'student']]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])
            ->assertJsonPath('role', 'student');
        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->details('10001'))->assertOk();

        $this->assertSame('student', $person->fresh()->role);
        $this->assertTrue($person->fresh()->hasRole('student'));
        $this->assertFalse($person->fresh()->hasRole('teacher'));
    }

    public function test_an_email_held_by_a_hidden_waiting_account_is_given_up(): void
    {
        $staff = $this->account('90002', 'staff', 'Library Staff');
        $holder = $this->account('10001', 'student', 'Holder', ['needs_activation' => true]);
        $this->account('10003', 'student', 'In Use');
        $this->roster([['90002', 'Library', 'Staff', 'staff'], ['10002', 'Kent', 'Concha', 'student'], ['10003', 'In', 'Use', 'student']]);

        // An account in use still blocks it.
        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->details('10002', ['email' => 'u10003@example.com']))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation', $this->details('10002', ['email' => 'u10001@example.com']))
            ->assertCreated();

        $this->assertNull($holder->fresh()->email);
    }

    public function test_lookups_are_recorded_and_limited(): void
    {
        $staff = $this->account('90002', 'staff', 'Library Staff');
        $this->roster([['90002', 'Library', 'Staff', 'staff'], ['10001', 'Kent', 'Concha', 'student']]);
        RateLimiter::clear('activation-lookup');

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])->assertOk();
        }
        $this->actingAs($staff, 'sanctum')->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])->assertStatus(429);

        $logged = AuditLog::where('action', 'activation_lookup')->where('user_id', $staff->id);
        $this->assertSame(30, $logged->count());
        $this->assertStringContainsString('10001', $logged->first()->description);
        $this->assertStringNotContainsString('Concha', $logged->first()->description);
    }

    // --- My Profile and User Management ----------------------------------------

    public function test_people_cannot_rename_themselves_but_can_change_their_email(): void
    {
        $person = $this->account('10001', 'student', 'Kent Concha');

        $this->actingAs($person, 'sanctum')->putJson('/api/profile', ['name' => 'Someone Else', 'email' => 'mine@example.com'])->assertOk();

        $this->assertSame('Kent Concha', $person->fresh()->name);
        $this->assertSame('mine@example.com', $person->fresh()->email);
    }

    public function test_the_tabs_and_counts_cover_every_account_not_just_one_page(): void
    {
        $admin = $this->account('90001', 'super_admin', 'Super Admin');
        $this->account('90002', 'staff', 'Library Staff');
        $this->account('10000', 'student', 'Off Person', ['status' => 'deactivated']);
        // Pushes the accounts above off the first page, which lists the newest.
        for ($i = 1; $i <= 20; $i++) {
            $this->account((string) (10000 + $i), 'student', "Person {$i}");
        }
        $this->account('10099', 'student', 'Waiting Person', ['needs_activation' => true]);

        $ids = fn (string $tab) => collect($this->actingAs($admin, 'sanctum')->getJson("/api/users?tab={$tab}")->json('data'))->pluck('id_number')->all();

        $this->assertSame(['90002'], $ids('staff'));
        $this->assertSame(['90001'], $ids('admins'));
        $this->assertSame(['10000'], $ids('deactivated'));

        $this->actingAs($admin, 'sanctum')->getJson('/api/users')
            ->assertJsonPath('counts.total', 23)
            ->assertJsonPath('counts.active', 22)
            ->assertJsonPath('counts.deactivated', 1)
            ->assertJsonPath('counts.staff_admins', 2);

        $this->actingAs($admin, 'sanctum')->getJson('/api/users?tab=students')->assertJsonPath('total', 21);
    }
}
