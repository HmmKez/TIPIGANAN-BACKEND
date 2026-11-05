<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SchoolRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The school's list (CSV roster): reading and importing it, and who may sign
 * in - every role. Activation at the library is in AccountActivationTest.
 */
class SchoolRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.directory.driver' => 'csv']);
        foreach (SchoolRoster::ROLES as $r) {
            Role::findOrCreate($r);
        }
    }

    private function roster(array $rows): void
    {
        SchoolRoster::replace(array_map(fn ($r) => array_combine(['id_number', 'first_name', 'last_name', 'role'], $r), $rows));
    }

    private function account(string $id, string $role = 'student', string $password = 'Secret123'): User
    {
        $u = User::create(['id_number' => $id, 'email' => "u{$id}@example.com", 'password' => Hash::make($password),
            'role' => $role, 'status' => 'active']);
        $u->assignRole($role);

        return $u;
    }

    private function csv(string $body, string $name = 'roster.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    // --- Reading the CSV ------------------------------------------------------

    public function test_a_csv_is_read_with_any_column_order_and_header_spelling(): void
    {
        $p = SchoolRoster::parse("\xEF\xBB\xBFRole,First Name,ID Number,Surname\nFaculty,Angela,20001,Lenteria\nstudent,Kent,00042,Concha\n");

        $this->assertNull($p['error']);
        $this->assertSame([
            ['id_number' => '20001', 'first_name' => 'Angela', 'last_name' => 'Lenteria', 'role' => 'teacher'],
            ['id_number' => '00042', 'first_name' => 'Kent', 'last_name' => 'Concha', 'role' => 'student'],
        ], $p['rows']);
    }

    public function test_bad_rows_are_rejected_with_a_reason_not_imported(): void
    {
        $p = SchoolRoster::parse("id_number,first_name,role\n42,Rowena,student\n10001,Kent,student\n10001,Kenneth,student\n10002,123,student\n10003,AJ,janitor\n");

        $this->assertCount(1, $p['rows']);
        $reasons = array_column($p['rejected'], 'reason');
        $this->assertStringContainsString('leading zero was probably lost', $reasons[0]);
        $this->assertStringContainsString('listed twice', $reasons[1]);
        $this->assertStringContainsString('no letters', $reasons[2]);
        $this->assertStringContainsString('not one of', $reasons[3]);
    }

    public function test_a_file_without_the_needed_columns_is_refused(): void
    {
        $this->assertNotNull(SchoolRoster::parse("name,email\nKent,k@x.com\n")['error']);
    }

    // --- Signing in -----------------------------------------------------------

    public function test_no_account_off_the_list_can_sign_in_not_even_the_super_admin(): void
    {
        $this->roster([['10001', 'Kent', 'Concha', 'student']]);
        $this->account('90001', 'super_admin');
        $this->account('90002', 'staff');

        $this->postJson('/api/auth/login', ['id_number' => '90001', 'password' => 'Secret123'])
            ->assertStatus(403)->assertJsonPath('reason', 'not_on_roster');
        $this->postJson('/api/auth/login', ['id_number' => '90002', 'password' => 'Secret123'])->assertStatus(403);
    }

    public function test_a_wrong_password_off_the_list_still_says_only_credentials_are_wrong(): void
    {
        $this->roster([['10001', 'Kent', 'Concha', 'student']]);
        $this->account('90001', 'super_admin');

        $this->postJson('/api/auth/login', ['id_number' => '90001', 'password' => 'wrong'])->assertStatus(422);
    }

    public function test_nothing_is_enforced_until_a_list_is_imported_or_when_the_driver_is_off(): void
    {
        $this->account('90001', 'super_admin');
        $this->postJson('/api/auth/login', ['id_number' => '90001', 'password' => 'Secret123'])->assertOk();

        $this->roster([['10001', 'Kent', 'Concha', 'student']]);
        config(['school.directory.driver' => 'off']);
        $this->postJson('/api/auth/login', ['id_number' => '90001', 'password' => 'Secret123'])->assertOk();
    }

    public function test_someone_signed_in_who_is_no_longer_on_the_list_is_signed_out(): void
    {
        $this->roster([['10001', 'Kent', 'Concha', 'student']]);
        $user = $this->account('10001');
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $this->roster([['10002', 'AJ', 'Reyes', 'student']]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('reason', 'not_on_roster');
        $this->assertSame(0, $user->tokens()->count(), 'the token is deleted');
    }

    public function test_a_password_reset_by_staff_brings_back_the_reminder(): void
    {
        $this->roster([['90001', 'Super', 'Admin', 'super_admin'], ['10001', 'Kent', 'Concha', 'student']]);
        // The real Super Admin gets this from PermissionSeeder.
        \Spatie\Permission\Models\Permission::findOrCreate('reset_passwords');
        Role::findByName('super_admin')->givePermissionTo('reset_passwords');
        $admin = $this->account('90001', 'super_admin');
        $student = $this->account('10001');

        $this->actingAs($admin, 'sanctum')->postJson("/api/users/{$student->id}/reset-password", [
            'password' => 'TempPass9', 'password_confirmation' => 'TempPass9',
        ])->assertOk();

        $this->assertTrue($student->fresh()->must_change_password);
    }

    // --- Importing ------------------------------------------------------------

    public function test_the_super_admin_previews_then_imports_a_list(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $file = "id_number,first_name,last_name,role\n90001,Super,Admin,super_admin\n10001,Kent,Concha,student\n10002,AJ,,stud\n";

        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/preview', ['file' => $this->csv($file)])
            ->assertOk()->assertJsonPath('valid', 2)->assertJsonPath('rejected_total', 1)->assertJsonPath('includes_you', true);
        $this->assertFalse(SchoolRoster::isLoaded(), 'a preview changes nothing');

        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import', ['file' => $this->csv($file)])
            ->assertOk()->assertJsonPath('summary.total', 2);
        $this->assertTrue(SchoolRoster::contains('10001'));
    }

    public function test_an_import_that_leaves_out_the_importer_is_refused(): void
    {
        $admin = $this->account('90001', 'super_admin');

        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import',
            ['file' => $this->csv("id_number,first_name,role\n10001,Kent,student\n")])
            ->assertStatus(422);
        $this->assertFalse(SchoolRoster::isLoaded());
    }

    public function test_a_much_smaller_list_needs_confirming(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $big = "id_number,first_name,role\n90001,Super,super_admin\n".implode("\n", array_map(fn ($i) => (10000 + $i).",Name,student", range(1, 9)))."\n";
        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import', ['file' => $this->csv($big)])->assertOk();

        $small = "id_number,first_name,role\n90001,Super,super_admin\n10001,Name,student\n";
        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import', ['file' => $this->csv($small)])->assertStatus(409);
        $this->assertSame(10, SchoolRoster::summary()['total']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import', ['file' => $this->csv($small), 'confirm_smaller' => 1])->assertOk();
        $this->assertSame(2, SchoolRoster::summary()['total']);
    }

    public function test_only_the_super_admin_can_import(): void
    {
        $this->roster([['90002', 'Library', 'Staff', 'staff']]);
        $staff = $this->account('90002', 'staff');

        $this->actingAs($staff, 'sanctum')->postJson('/api/school-roster/import',
            ['file' => $this->csv("id_number,first_name,role\n90002,Library,staff\n")])->assertForbidden();
    }

    // --- Re-importing: switched off, and back on -------------------------------

    public function test_a_new_list_switches_off_accounts_not_on_it_and_keeps_the_rest(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $kept = $this->account('10001', 'student', 'KentOwnPass1');
        $gone = $this->account('10002');
        $gone->createToken('t');

        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import',
            ['file' => $this->csv("id_number,first_name,role\n90001,Super,super_admin\n10001,Kent,student\n10002,AJ,student\n")])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/preview',
            ['file' => $this->csv("id_number,first_name,role\n90001,Super,super_admin\n10001,Kent,student\n")])
            ->assertJsonPath('would_lose_access.0.id_number', '10002');

        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import',
            ['file' => $this->csv("id_number,first_name,role\n90001,Super,super_admin\n10001,Kent,student\n"), 'confirm_smaller' => 1])
            ->assertOk()->assertJsonPath('switched_off', ['10002']);

        $this->assertSame('deactivated', $gone->fresh()->status);
        $this->assertTrue($gone->fresh()->deactivated_by_roster);
        $this->assertSame(0, $gone->tokens()->count(), 'signed out everywhere');
        $this->assertSame('active', $kept->fresh()->status);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['id_number' => '10001', 'password' => 'KentOwnPass1'])->assertOk();
        $this->postJson('/api/auth/login', ['id_number' => '10002', 'password' => 'Secret123'])
            ->assertStatus(403)->assertJsonPath('reason', 'not_on_roster');
    }

    public function test_an_account_back_on_a_later_list_is_switched_on_with_its_own_password(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $u = $this->account('10002', 'student', 'AjOwnPass1');
        $this->roster([['90001', 'Super', null, 'super_admin']]);
        SchoolRoster::syncAccounts();
        $this->assertSame('deactivated', $u->fresh()->status);

        $file = $this->csv("id_number,first_name,role\n90001,Super,super_admin\n10002,AJ,student\n");
        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/preview', ['file' => $file])
            ->assertJsonPath('would_restore.0.id_number', '10002');
        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import', ['file' => $file])
            ->assertOk()->assertJsonPath('switched_on', ['10002']);

        $this->assertSame('active', $u->fresh()->status);
        $this->assertFalse($u->fresh()->deactivated_by_roster);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['id_number' => '10002', 'password' => 'AjOwnPass1'])->assertOk();
    }

    public function test_an_account_staff_deactivated_by_hand_stays_off_after_an_import(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $u = $this->account('10002');
        $this->roster([['90001', 'Super', null, 'super_admin'], ['10002', 'AJ', null, 'student']]);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/users/{$u->id}/deactivate")->assertOk();
        SchoolRoster::syncAccounts();

        $this->assertSame('deactivated', $u->fresh()->status);
    }

    public function test_an_account_off_the_list_cannot_be_switched_on_by_hand(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $u = $this->account('10002');
        $this->roster([['90001', 'Super', null, 'super_admin']]);
        SchoolRoster::syncAccounts();

        $this->actingAs($admin, 'sanctum')->patchJson("/api/users/{$u->id}/activate")->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$u->id}", ['status' => 'active'])->assertStatus(422);
        $this->assertSame('deactivated', $u->fresh()->status);
    }

    public function test_a_list_stored_while_the_driver_is_off_switches_nobody_off(): void
    {
        config(['school.directory.driver' => 'off']);
        $u = $this->account('10002');
        $this->roster([['90001', 'Super', null, 'super_admin']]);

        $this->assertSame(['switched_off' => [], 'switched_on' => [], 'needs_activation' => [], 'role_changed' => []], SchoolRoster::syncAccounts());
        $this->assertSame('active', $u->fresh()->status);
    }

    // --- Viewing the list -------------------------------------------------------

    public function test_the_list_shows_id_numbers_and_who_is_activated_but_never_names(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $this->account('10001');
        // An old self-registered account waiting for activation does not count.
        $this->account('10003')->update(['needs_activation' => true]);
        $this->roster([['90001', 'Super', 'Admin', 'super_admin'], ['10001', 'Kent', 'Concha', 'student'],
            ['10002', 'Angela', null, 'teacher'], ['10003', 'Rowena', null, 'student']]);

        $r = $this->actingAs($admin, 'sanctum')->getJson('/api/school-roster/people')->assertOk()
            ->assertJsonPath('counts', ['total' => 4, 'activated' => 2, 'not_activated' => 2])
            ->assertJsonPath('data', [
                ['id_number' => '10001', 'activated' => true],
                ['id_number' => '10002', 'activated' => false],
                ['id_number' => '10003', 'activated' => false],
                ['id_number' => '90001', 'activated' => true],
            ]);
        foreach (['Kent', 'Concha', 'Angela', 'Rowena', 'Super', 'teacher', 'student'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $r->getContent(), "the list must not reveal \"{$hidden}\"");
        }

        $this->actingAs($admin, 'sanctum')->getJson('/api/school-roster/people?activated=no')
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id_number', '10002');
        $this->actingAs($admin, 'sanctum')->getJson('/api/school-roster/people?search=100')
            ->assertJsonCount(3, 'data');
    }

    public function test_only_the_super_admin_can_view_the_list(): void
    {
        $this->roster([['90002', 'Library', 'Staff', 'staff']]);
        $staff = $this->account('90002', 'staff');

        $this->actingAs($staff, 'sanctum')->getJson('/api/school-roster/people')->assertForbidden();
    }

    private function download(User $admin, string $section, string $csv)
    {
        return $this->actingAs($admin, 'sanctum')->post('/api/school-roster/preview/download',
            ['file' => $this->csv($csv), 'section' => $section], ['Accept' => 'application/json']);
    }

    public function test_the_full_list_downloads_for_a_list_of_thousands(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $now = now();
        foreach (array_chunk(range(10000, 16999), 500) as $ids) {
            \Illuminate\Support\Facades\DB::table('users')->insert(array_map(fn ($i) => [
                'id_number' => (string) $i, 'name' => "Person {$i}", 'email' => "p{$i}@example.com", 'password' => 'x',
                'role' => 'student', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ], $ids));
        }
        // 1,000 stay on the list; 6,000 leave.
        $file = "id_number,first_name,last_name,role\n90001,Super,Admin,super_admin\n";
        foreach (range(10000, 10999) as $i) {
            $file .= "{$i},Person,{$i},student\n";
        }

        $preview = $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/preview', ['file' => $this->csv($file)])->assertOk();
        $preview->assertJsonPath('would_lose_access_total', 6000);
        $this->assertCount(50, $preview->json('would_lose_access'));

        $response = $this->download($admin, 'switched_off', $file)->assertOk();
        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

        $this->assertCount(6001, $lines, 'Every account, plus the header.');
        $this->assertStringContainsString('"ID number",Name,Role,Account', $lines[0]);
        $this->assertSame('11000,"Person 11000",student,Activated', trim($lines[1]));
        $this->assertSame('16999,"Person 16999",student,Activated', trim($lines[6000]));

        $this->assertSame('active', User::where('id_number', '16999')->first()->status, 'A download changes nothing.');
        $this->assertFalse(SchoolRoster::isLoaded());
    }

    public function test_each_section_downloads_with_its_own_columns(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $admin->update(['name' => 'Super Admin']);
        $this->account('10001')->update(['name' => 'Old Name']);
        $this->account('20001', 'teacher')->update(['name' => 'Ana Reyes']);
        $off = $this->account('10002');
        $off->update(['name' => 'Back Again', 'status' => 'deactivated', 'deactivated_by_roster' => true]);
        $file = "id_number,first_name,last_name,role\n90001,Super,Admin,super_admin\n10001,Niño,Peña,student\n20001,Ana,Reyes,student\n10002,Back,Again,student\n12,Short,Id,student\n";

        $names = $this->download($admin, 'name_changes', $file)->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $names, 'So Excel reads the ñ.');
        $this->assertStringContainsString('10001,"Old Name","Niño Peña",student', $names);

        $this->assertStringContainsString('20001,"Ana Reyes",teacher,student', $this->download($admin, 'role_changes', $file)->streamedContent());
        $this->assertStringContainsString('10002,"Back Again",student,Activated', $this->download($admin, 'switched_back_on', $file)->streamedContent());
        $this->assertStringContainsString('12,', $this->download($admin, 'skipped', $file)->streamedContent());

        $this->download($admin, 'everything', $file)->assertStatus(422);
    }

    public function test_downloads_keep_leading_zeros_and_defuse_formulas(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $this->account('00042')->update(['name' => "=cmd|'/c calc'!A0"]);

        $csv = $this->download($admin, 'switched_off', "id_number,first_name,role\n90001,Super,super_admin\n")->assertOk()->streamedContent();

        $this->assertStringContainsString('"=""00042"""', $csv);
        $this->assertStringContainsString("'=cmd", $csv);
    }

    public function test_the_not_on_list_download_and_who_may_use_them(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $staff = $this->account('90002', 'staff');
        $this->account('10001');
        $this->roster([['90001', 'Super', 'Admin', 'super_admin'], ['90002', 'Library', 'Staff', 'staff']]);

        $csv = $this->actingAs($admin, 'sanctum')->get('/api/school-roster/not-on-list/download')->assertOk()->streamedContent();
        $this->assertStringContainsString('10001,', $csv);
        $this->assertStringNotContainsString('90002,', $csv);

        $this->assertTrue(\App\Models\AuditLog::where('action', 'download_school_list')->exists());

        $this->actingAs($staff, 'sanctum')->getJson('/api/school-roster/not-on-list/download')->assertForbidden();
        $this->download($staff, 'switched_off', "id_number,first_name,role\n90001,Super,super_admin\n")->assertForbidden();
    }

    public function test_the_template_downloads_for_the_super_admin_only(): void
    {
        $admin = $this->account('90001', 'super_admin');
        $staff = $this->account('90002', 'staff');

        $response = $this->actingAs($admin, 'sanctum')->get('/api/school-roster/template')->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="school-list-template.csv"');
        $this->assertSame("\xEF\xBB\xBFid_number,first_name,last_name,role\r\n", $response->getContent());

        $this->actingAs($staff, 'sanctum')->getJson('/api/school-roster/template')->assertForbidden();
    }

    public function test_the_template_is_only_the_columns_the_import_reads(): void
    {
        $admin = $this->account('90001', 'super_admin');

        // Empty, it is refused rather than wiping the list.
        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import', ['file' => $this->csv(SchoolRoster::template())])
            ->assertStatus(422);
        $this->assertFalse(SchoolRoster::isLoaded());

        // Filled in, it imports.
        $filled = SchoolRoster::template()."90001,Super,Admin,super_admin\r\n10001,Sample,Concha,student\r\n";
        $this->actingAs($admin, 'sanctum')->postJson('/api/school-roster/import', ['file' => $this->csv($filled)])
            ->assertOk()->assertJsonPath('summary.total', 2);
        $this->assertSame('Sample', SchoolRoster::find('10001')->first_name);
    }

    public function test_the_sample_file_and_the_command(): void
    {
        $this->account('90001', 'super_admin');

        $this->artisan('school:import-roster', ['file' => database_path('fixtures/school-roster-sample.csv')])->assertSuccessful();

        $this->assertSame(15, SchoolRoster::summary()['total']);
        $this->assertTrue(SchoolRoster::contains('00042'), 'leading zeros survive');
        foreach (['90001', '90002', '90003', '90004', '90005', '90006'] as $demo) {
            $this->assertTrue(SchoolRoster::contains($demo), "demo account {$demo} is on the sample list");
        }
    }
}
