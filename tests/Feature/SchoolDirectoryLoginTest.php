<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Signing in when the school's records say the person has left.
 *
 * The check reads only the directory_status column recorded by the last
 * reconciliation - login never calls the school's system. These tests exist
 * mainly to pin down the two cases where it must NOT lock someone out.
 */
class SchoolDirectoryLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('student');
    }

    private function makeUser(array $overrides = []): User
    {
        $user = User::create(array_merge([
            'id_number' => '10234',
            'email'     => 'student@example.com',
            'password'  => Hash::make('Password123'),
            'role'      => 'student',
            'status'    => 'active',
        ], $overrides));

        $user->assignRole('student');

        return $user;
    }

    private function login(string $idNumber = '10234')
    {
        return $this->postJson('/api/auth/login', [
            'id_number' => $idNumber,
            'password'  => 'Password123',
        ]);
    }

    public function test_an_active_account_can_still_sign_in(): void
    {
        $this->makeUser(['directory_status' => 'enrolled']);

        $this->login()->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_an_account_the_school_no_longer_lists_cannot_sign_in(): void
    {
        $this->makeUser(['directory_status' => 'graduated']);

        $this->login()
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your ID number is no longer active in the school records, so this account has been closed. Please visit the library if you think this is a mistake.']);
    }

    /**
     * Every account that predates this feature has a null directory_status.
     * Treating null as "not active" would lock out the entire existing user
     * base the moment the migration ran.
     */
    public function test_an_account_that_has_never_been_checked_can_sign_in(): void
    {
        $this->makeUser(['directory_status' => null]);

        $this->login()->assertStatus(200);
    }

    /**
     * Library staff are often not on the student/faculty roster at all, so a
     * roster comparison would mark them as departed and lock the administrators
     * out of their own system.
     */
    public function test_an_exempt_account_signs_in_even_when_marked_departed(): void
    {
        $this->makeUser([
            'directory_status' => 'resigned',
            'directory_exempt' => true,
        ]);

        $this->login()->assertStatus(200);
    }

    /**
     * Signing in must never depend on the school's system being up. Simulating
     * a total outage and asserting a normal login proves nothing in the login
     * path reaches for it.
     */
    public function test_login_works_while_the_school_system_is_unreachable(): void
    {
        config([
            'school.directory.driver'          => 'fake',
            'school.directory.simulate_outage' => true,
        ]);

        $this->makeUser(['directory_status' => 'enrolled']);

        $this->login()->assertStatus(200)->assertJsonStructure(['token']);
    }
}
