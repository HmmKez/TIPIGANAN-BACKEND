<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Admins no longer create accounts; everyone self-registers with their school
// ID number and a Super Admin promotes an existing account. The guards below
// are the reason this endpoint is safe to expose, and each one protects against
// a mistake that cannot be undone from inside the app.
class UserRoleChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'teacher', 'staff', 'super_admin'] as $role) {
            Role::findOrCreate($role);
        }

        $this->superAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->superAdmin->assignRole('super_admin');
    }

    private function makeUser(string $role): User
    {
        $u = User::factory()->create(['role' => $role]);
        $u->assignRole($role);

        return $u;
    }

    public function test_a_super_admin_can_promote_a_student_to_staff(): void
    {
        $student = $this->makeUser('student');

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/users/{$student->id}/role", ['role' => 'staff'])
            ->assertOk();

        $student->refresh();

        $this->assertSame('staff', $student->role);
        // Both stores must move together: the column is what the app reads,
        // Spatie's tables are what the permission middleware checks. Updating
        // one alone yields an account that looks promoted but is refused at
        // every gate.
        $this->assertTrue($student->hasRole('staff'));
        $this->assertFalse($student->hasRole('student'));
    }

    public function test_a_promotion_can_be_undone(): void
    {
        $user = $this->makeUser('student');

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/users/{$user->id}/role", ['role' => 'super_admin'])->assertOk();
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/users/{$user->id}/role", ['role' => 'student'])->assertOk();

        $this->assertSame('student', $user->fresh()->role);
    }

    public function test_a_super_admin_cannot_change_their_own_role(): void
    {
        // Demoting yourself removes the permission needed to reverse it, so the
        // account would be stuck at the lower role permanently.
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/users/{$this->superAdmin->id}/role", ['role' => 'student'])
            ->assertStatus(403);

        $this->assertSame('super_admin', $this->superAdmin->fresh()->role);
    }

    public function test_the_system_can_never_be_left_without_a_super_admin(): void
    {
        // This is the property that matters, and it is the SELF-CHANGE refusal
        // that actually delivers it - not the "last super admin" count check.
        //
        // Reaching this endpoint requires the super_admin role, and a change to
        // one's own role is refused. So whenever the target is a Super Admin,
        // the actor is a DIFFERENT Super Admin who survives the change, and at
        // least one always remains. The count check inside changeRole() is
        // therefore unreachable through the API today; it is kept as a
        // second line of defence in case the self-refusal is ever relaxed.
        //
        // Demonstrated rather than asserted in the abstract: drive the count
        // down as far as the API permits and show it floors at one.
        $second = $this->makeUser('super_admin');

        $this->actingAs($second)
            ->patchJson("/api/users/{$this->superAdmin->id}/role", ['role' => 'staff'])
            ->assertOk();

        $this->assertSame(1, User::where('role', 'super_admin')->count());

        // The survivor is now the only one, and cannot demote themselves.
        $this->actingAs($second)
            ->patchJson("/api/users/{$second->id}/role", ['role' => 'student'])
            ->assertStatus(403);

        $this->assertSame(1, User::where('role', 'super_admin')->count(),
            'There must always be at least one Super Admin left to administer the system.');
        $this->assertTrue($second->fresh()->hasRole('super_admin'));
    }

    public function test_a_staff_member_cannot_change_roles_at_all(): void
    {
        $staff   = $this->makeUser('staff');
        $student = $this->makeUser('student');

        $this->actingAs($staff)
            ->patchJson("/api/users/{$student->id}/role", ['role' => 'super_admin'])
            ->assertStatus(403);

        $this->assertSame('student', $student->fresh()->role);
    }

    public function test_the_generic_update_endpoint_can_no_longer_change_a_role(): void
    {
        // It used to accept `role` with no guards at all, which made every
        // check above bypassable by calling a different endpoint.
        $student = $this->makeUser('student');

        $this->actingAs($this->superAdmin)
            ->putJson("/api/users/{$student->id}", ['role' => 'super_admin', 'name' => 'Renamed'])
            ->assertOk();

        $student->refresh();

        $this->assertSame('student', $student->role, 'PUT /users/{id} must not be a back door for privilege escalation.');
        $this->assertSame('Renamed', $student->name, 'It should still update the fields it is meant to.');
    }

    public function test_creating_an_account_as_an_admin_is_no_longer_possible(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/users', [
                'id_number' => '55555',
                'email'     => 'invented@example.com',
                'password'  => 'Password123',
                'role'      => 'staff',
            ])
            ->assertStatus(405);   // route removed, not merely hidden in the UI

        $this->assertDatabaseMissing('users', ['email' => 'invented@example.com']);
    }

    public function test_a_role_change_is_recorded_in_the_audit_trail(): void
    {
        $student = $this->makeUser('student');

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/users/{$student->id}/role", ['role' => 'staff'])->assertOk();

        // The old generic "updated account" wording could not tell a role
        // change from an email edit - precisely the distinction an auditor
        // cares about.
        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'change_user_role',
            'target_type' => 'user',
            'target_id'   => $student->id,
        ]);

        $this->assertStringContainsString(
            'from student to staff',
            \App\Models\AuditLog::where('action', 'change_user_role')->latest('id')->first()->description
        );
    }
}
