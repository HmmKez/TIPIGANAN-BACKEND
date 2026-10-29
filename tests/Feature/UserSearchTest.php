<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * User Management search: by ID number, name or email, across every account
 * (it used to filter only the 15 users already on the page).
 */
class UserSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['student', 'teacher', 'staff', 'super_admin'] as $role) {
            Role::findOrCreate($role);
        }
        Permission::findOrCreate('reset_passwords');
        Role::findByName('super_admin')->givePermissionTo('reset_passwords');

        $this->admin = User::factory()->create(['id_number' => '90001', 'role' => 'super_admin', 'name' => 'Super Admin', 'email' => 'admin@example.com']);
        $this->admin->assignRole('super_admin');
    }

    private function search(string $term): array
    {
        return collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/users?search='.urlencode($term))
            ->assertOk()->json('data'))->pluck('id_number')->sort()->values()->all();
    }

    public function test_users_can_be_found_by_id_number_name_or_email(): void
    {
        User::factory()->create(['id_number' => '10001', 'name' => 'Kent Concha', 'email' => 'kent@example.com']);
        User::factory()->create(['id_number' => '10002', 'name' => null, 'email' => 'aj@school.edu']);
        User::factory()->create(['id_number' => '20001', 'name' => 'Angela Lenteria', 'email' => 'angela@example.com']);

        $this->assertSame(['10001'], $this->search('10001'), 'full ID number');
        $this->assertSame(['10001', '10002'], $this->search('100'), 'the start of an ID number');
        $this->assertSame([], $this->search('001'), 'an ID matches from its start, not its middle');
        $this->assertSame(['10001'], $this->search('concha'), 'name, any case');
        $this->assertSame(['10002'], $this->search('school.edu'), 'email');
        $this->assertSame(['10001', '10002', '20001', '90001'], $this->search(''), 'no search lists everyone');
    }

    public function test_the_search_covers_every_account_not_just_the_first_page(): void
    {
        User::factory()->count(20)->create();
        $late = User::factory()->create(['id_number' => '00042', 'name' => 'Rowena Mae']);
        // Older than everyone else, so it would sit on the last page of the list.
        $late->forceFill(['created_at' => now()->subYear()])->save();

        $this->assertSame(['00042'], $this->search('00042'));
    }

    public function test_accounts_waiting_for_activation_are_not_listed_until_activated(): void
    {
        $waiting = User::factory()->create(['id_number' => '10001', 'name' => 'Kent Concha', 'needs_activation' => true]);
        User::factory()->create(['id_number' => '10002', 'name' => 'AJ Reyes']);

        $this->assertSame(['10002', '90001'], $this->search(''));
        $this->assertSame([], $this->search('10001'), 'not even by searching for it');

        $waiting->update(['needs_activation' => false]);
        $this->assertSame(['10001'], $this->search('10001'), 'listed once activated');
    }

    public function test_a_percent_sign_is_searched_for_literally(): void
    {
        User::factory()->create(['id_number' => '10001', 'name' => 'Kent']);

        $this->assertSame([], $this->search('%'));
    }
}
