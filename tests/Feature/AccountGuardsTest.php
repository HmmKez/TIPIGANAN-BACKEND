<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SchoolRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who may take over or keep using an account: password resets and activation
 * stay within a staff member's authority, a deactivated account stops working
 * at once, and names with an ñ survive Excel's CSV formats.
 */
class AccountGuardsTest extends TestCase
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

        SchoolRoster::replace(array_map(
            fn ($r) => array_combine(['id_number', 'first_name', 'last_name', 'role'], $r),
            [
                ['90001', 'Super', 'Admin', 'super_admin'],
                ['90002', 'Library', 'Staff', 'staff'],
                ['90003', 'Other', 'Staff', 'staff'],
                ['90004', 'Second', 'Admin', 'super_admin'],
                ['10001', 'Kent', 'Concha', 'student'],
                ['20001', 'Angela', 'Lenteria', 'teacher'],
            ],
        ));
    }

    private function account(string $id, string $role, array $extra = []): User
    {
        $u = User::create(['id_number' => $id, 'email' => "u{$id}@example.com", 'password' => Hash::make('Secret123'),
            'role' => $role]);
        $u->forceFill($extra + ['status' => 'active'])->save();
        $u->assignRole($role);

        return $u;
    }

    private function login(string $id, string $password = 'Secret123')
    {
        return $this->postJson('/api/auth/login', ['id_number' => $id, 'password' => $password]);
    }

    /** Sign in for real, so these tests use a real session token. */
    private function bearer(string $id): array
    {
        return ['Authorization' => 'Bearer '.$this->login($id)->assertOk()->json('token')];
    }

    private function resetTo(User $target, string $password = 'Taken0ver9')
    {
        return $this->postJson("/api/users/{$target->id}/reset-password", [
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    private function activationPayload(string $id): array
    {
        return ['id_number' => $id, 'name_confirmed' => true, 'email' => "new{$id}@example.com",
            'password' => 'MyOwnPass9', 'password_confirmation' => 'MyOwnPass9'];
    }

    // --- Resetting passwords ----------------------------------------------------

    public function test_staff_cannot_reset_a_super_admin_or_staff_password(): void
    {
        $staff = $this->account('90002', 'staff');
        $admin = $this->account('90001', 'super_admin');
        $peer = $this->account('90003', 'staff');

        foreach ([$admin, $peer, $staff] as $target) {
            $this->actingAs($staff)->resetTo($target)->assertForbidden();
            $this->assertTrue(Hash::check('Secret123', $target->fresh()->password));
            $this->assertFalse((bool) $target->fresh()->must_change_password);
        }
    }

    public function test_staff_can_still_reset_students_and_teachers(): void
    {
        $staff = $this->account('90002', 'staff');

        foreach ([$this->account('10001', 'student'), $this->account('20001', 'teacher')] as $target) {
            $this->actingAs($staff)->resetTo($target)->assertOk();
            $this->assertTrue(Hash::check('Taken0ver9', $target->fresh()->password));
        }
    }

    public function test_a_super_admin_can_reset_anyone(): void
    {
        $admin = $this->account('90001', 'super_admin');

        foreach ([$this->account('90002', 'staff'), $this->account('90004', 'super_admin'), $this->account('10001', 'student')] as $target) {
            $this->actingAs($admin)->resetTo($target)->assertOk();
        }
    }

    // --- Activating earlier staff and Super Admin accounts ---------------------

    public function test_staff_cannot_look_up_or_activate_a_waiting_privileged_account(): void
    {
        $staff = $this->account('90002', 'staff');

        foreach ([['90001', 'super_admin'], ['90003', 'staff']] as [$id, $role]) {
            $waiting = $this->account($id, $role, ['needs_activation' => true]);

            $this->actingAs($staff)->postJson('/api/accounts/activation/lookup', ['id_number' => $id])
                ->assertStatus(422)
                ->assertJsonMissingPath('full_name')
                ->assertJsonValidationErrors('id_number');

            $this->actingAs($staff)->postJson('/api/accounts/activation', $this->activationPayload($id))
                ->assertStatus(422);

            $this->assertTrue((bool) $waiting->fresh()->needs_activation);
            $this->assertTrue(Hash::check('Secret123', $waiting->fresh()->password));
        }
    }

    public function test_a_super_admin_can_activate_a_waiting_privileged_account(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $waiting = $this->account('90003', 'staff', ['needs_activation' => true]);

        $this->actingAs($admin)->postJson('/api/accounts/activation', $this->activationPayload('90003'))->assertOk();

        $this->assertFalse((bool) $waiting->fresh()->needs_activation);
        $this->assertSame('staff', $waiting->fresh()->role);
    }

    public function test_staff_can_still_activate_students_teachers_and_new_accounts(): void
    {
        $staff = $this->account('90002', 'staff');
        $waiting = $this->account('10001', 'student', ['needs_activation' => true]);

        $this->actingAs($staff)->postJson('/api/accounts/activation', $this->activationPayload('10001'))->assertOk();
        $this->assertFalse((bool) $waiting->fresh()->needs_activation);

        // A staff row with no account yet: still only a teacher-level account.
        $this->actingAs($staff)->postJson('/api/accounts/activation', $this->activationPayload('90003'))->assertCreated();
        $this->assertSame('teacher', User::where('id_number', '90003')->first()->role);
    }

    // --- Deactivating -----------------------------------------------------------

    public function test_deactivating_ends_every_session_at_once(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $student = $this->account('10001', 'student');
        $headers = $this->bearer('10001');

        $this->getJson('/api/auth/me', $headers)->assertOk();
        $this->actingAs($admin)->patchJson("/api/users/{$student->id}/deactivate")->assertOk();

        $this->assertSame(0, $student->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $headers)->assertUnauthorized();
        $this->login('10001')->assertForbidden();
    }

    public function test_deactivating_through_the_account_edit_also_ends_sessions(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $student = $this->account('10001', 'student');
        $this->bearer('10001');

        $this->actingAs($admin)->putJson("/api/users/{$student->id}", ['status' => 'deactivated'])->assertOk();

        $this->assertSame(0, $student->tokens()->count());
    }

    public function test_a_leftover_session_of_a_deactivated_account_stops_working(): void
    {
        $student = $this->account('10001', 'student');
        $headers = $this->bearer('10001');

        // As for anyone deactivated before sessions were ended on deactivating.
        $student->update(['status' => 'deactivated']);
        $this->assertSame(1, $student->tokens()->count());

        $this->getJson('/api/auth/me', $headers)
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'deactivated');
        $this->assertSame(0, $student->tokens()->count());
    }

    public function test_an_account_switched_on_again_can_sign_in(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $student = $this->account('10001', 'student');

        $this->actingAs($admin)->patchJson("/api/users/{$student->id}/deactivate")->assertOk();
        $this->actingAs($admin)->patchJson("/api/users/{$student->id}/activate")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $this->bearer('10001'))->assertOk();
    }

    // --- Names in the CSV -------------------------------------------------------

    public function test_names_with_an_n_tilde_are_read_from_every_csv_format(): void
    {
        $utf8 = "id_number,first_name,last_name,role\n10001,Niño,Peña,student\n";

        $files = [
            'Excel CSV (Comma delimited)' => mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'),
            'Excel CSV UTF-8'             => "\xEF\xBB\xBF".$utf8,
            'plain UTF-8'                 => $utf8,
            'UTF-16 little endian'        => "\xFF\xFE".mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8'),
            'UTF-16 big endian'           => "\xFE\xFF".mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'),
        ];

        foreach ($files as $format => $contents) {
            $parsed = SchoolRoster::parse($contents);

            $this->assertNull($parsed['error'], $format);
            $this->assertSame([], $parsed['rejected'], $format);
            $this->assertSame('Niño', $parsed['rows'][0]['first_name'], $format);
            $this->assertSame('Peña', $parsed['rows'][0]['last_name'], $format);
        }
    }

    public function test_an_excel_csv_imports_and_shows_the_name_correctly_at_the_desk(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $csv = mb_convert_encoding(
            "id_number,first_name,last_name,role\n90001,Super,Admin,super_admin\n10001,Niño,Peña,student\n",
            'Windows-1252', 'UTF-8',
        );

        $this->actingAs($admin)->post('/api/school-roster/import', [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv),
            'confirm_smaller' => 1,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($admin)->postJson('/api/accounts/activation/lookup', ['id_number' => '10001'])
            ->assertOk()
            ->assertJsonPath('full_name', 'Niño Peña');
    }
}
