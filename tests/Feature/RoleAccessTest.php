<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $permissions = []): User
    {
        Role::findOrCreate($role);
        $user = User::factory()->create(['role' => $role]);
        $user->assignRole($role);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $user->givePermissionTo($permission);
        }
        return $user;
    }

    public function test_guest_cannot_list_users(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
    }

    public function test_student_cannot_list_users(): void
    {
        $student = $this->user('student');

        $this->actingAs($student, 'sanctum')->getJson('/api/users')->assertStatus(403);
    }

    public function test_staff_with_permission_can_list_users(): void
    {
        $staff = $this->user('staff', ['reset_passwords']);

        $this->actingAs($staff, 'sanctum')->getJson('/api/users')->assertOk();
    }

    public function test_staff_cannot_delete_a_peer_staff_account(): void
    {
        $staff = $this->user('staff', ['delete_accounts']);
        $peer  = $this->user('staff');

        $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/users/{$peer->id}")
            ->assertStatus(403);
    }

    public function test_staff_can_delete_a_student_account(): void
    {
        $staff   = $this->user('staff', ['delete_accounts']);
        $student = $this->user('student');

        $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/users/{$student->id}")
            ->assertOk();
    }
}
