<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActiveTermTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every role is created up front, before any request runs. Spatie caches the
     * role table the first time it is read, so a role created *after* a request
     * has warmed that cache is invisible to `assignRole` — which is exactly what
     * the loop below (student → teacher → staff) would otherwise trip over.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'teacher', 'staff', 'super_admin'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->assignRole($role);

        return $user;
    }

    public function test_anyone_including_guests_can_read_the_active_term(): void
    {
        // The term is rendered in the navbar, which guests see, so the read
        // must not require auth.
        $this->getJson('/api/settings/active-term')
            ->assertOk()
            ->assertJsonStructure(['term' => ['semester', 'school_year', 'label'], 'semesters']);
    }

    public function test_the_term_falls_back_to_a_sensible_default_when_never_set(): void
    {
        // A fresh install has no settings row. It should still render a term
        // rather than a blank navbar or a stale hardcoded year.
        $this->assertSame(0, Setting::count());

        $term = $this->getJson('/api/settings/active-term')->assertOk()->json('term');

        $this->assertContains($term['semester'], Setting::SEMESTERS);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $term['school_year']);
    }

    public function test_a_super_admin_can_change_the_active_term(): void
    {
        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->putJson('/api/settings/active-term', [
                'semester'    => '2nd Semester',
                'school_year' => '2030-2031',
            ])
            ->assertOk()
            ->assertJsonPath('term.label', '2nd Semester AY 2030-2031');

        // Persisted, and visible to everyone else — including guests.
        $this->getJson('/api/settings/active-term')
            ->assertOk()
            ->assertJsonPath('term.label', '2nd Semester AY 2030-2031');
    }

    public function test_changing_the_term_is_recorded_in_the_audit_log(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin, 'sanctum')->putJson('/api/settings/active-term', [
            'semester'    => 'Summer',
            'school_year' => '2028-2029',
        ])->assertOk();

        $log = AuditLog::where('action', 'update_active_term')->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringContainsString('Summer AY 2028-2029', $log->description);
    }

    public function test_a_guest_cannot_change_the_active_term(): void
    {
        $this->putJson('/api/settings/active-term', [
            'semester'    => '1st Semester',
            'school_year' => '2030-2031',
        ])->assertStatus(401);
    }

    /**
     * The whole point of the feature is that ONLY a Super Admin may edit it.
     */
    public function test_non_super_admin_roles_cannot_change_the_active_term(): void
    {
        foreach (['student', 'teacher', 'staff'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->putJson('/api/settings/active-term', [
                    'semester'    => '1st Semester',
                    'school_year' => '2030-2031',
                ])
                ->assertStatus(403, "role [{$role}] must not be able to change the active term");
        }
    }

    public function test_the_school_year_must_span_two_consecutive_years(): void
    {
        $admin = $this->userWithRole('super_admin');

        // A regex alone would accept all of these — the format is right but the
        // span is nonsense, so they are rejected on the consecutive-year rule.
        foreach (['2026-2029', '2027-2026', '2026-2026'] as $bad) {
            $this->actingAs($admin, 'sanctum')
                ->putJson('/api/settings/active-term', ['semester' => '1st Semester', 'school_year' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('school_year');
        }

        // Malformed shapes are caught by the format rule.
        foreach (['2026', '26-27', 'next year', ''] as $bad) {
            $this->actingAs($admin, 'sanctum')
                ->putJson('/api/settings/active-term', ['semester' => '1st Semester', 'school_year' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('school_year');
        }
    }

    public function test_the_semester_must_be_one_of_the_offered_options(): void
    {
        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->putJson('/api/settings/active-term', [
                'semester'    => 'Trimester',
                'school_year' => '2026-2027',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('semester');
    }
}
