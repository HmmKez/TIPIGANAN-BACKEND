<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Test Student',
            'email'                 => 'student@example.com',
            'password'              => 'Password123',
            'password_confirmation' => 'Password123',
            'role'                  => 'student',
        ], $overrides);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        Role::findOrCreate('student');

        $this->postJson('/api/auth/register', $this->registerPayload([
            'password'              => 'weak',
            'password_confirmation' => 'weak',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_succeeds_with_a_strong_password_and_returns_a_token(): void
    {
        Role::findOrCreate('student');

        $this->postJson('/api/auth/register', $this->registerPayload())
            ->assertStatus(201)
            ->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $this->assertDatabaseHas('users', ['email' => 'student@example.com', 'role' => 'student']);
    }

    public function test_login_returns_a_token_with_correct_credentials(): void
    {
        User::factory()->create([
            'email'    => 'a@example.com',
            'password' => Hash::make('Password123'),
            'role'     => 'student',
        ]);

        $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create([
            'email'    => 'b@example.com',
            'password' => Hash::make('Password123'),
            'role'     => 'student',
        ]);

        $this->postJson('/api/auth/login', ['email' => 'b@example.com', 'password' => 'WrongPass9'])
            ->assertStatus(422);
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'email'    => 'c@example.com',
            'password' => Hash::make('Password123'),
            'role'     => 'student',
        ]);

        // The first five failures must actually be *reached* (422 = credentials
        // rejected). Asserting only that the sixth is 429 would pass even if the
        // limiter blocked everything from the first request onward.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'c@example.com', 'password' => 'nope'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'c@example.com', 'password' => 'nope'])
            ->assertStatus(429);
    }

    public function test_browsing_the_public_api_does_not_consume_the_login_limit(): void
    {
        // Regression: the login throttle used to be an inline `throttle:5,1`
        // nested inside `throttle:120,1`. Inline throttles key on the client IP
        // alone — the route is not part of the key — so both shared ONE counter,
        // and ordinary browsing pushed it past 5 and 429'd the first login.
        User::factory()->create([
            'email'    => 'd@example.com',
            'password' => Hash::make('Password123'),
            'role'     => 'student',
        ]);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/theses')->assertOk();
        }

        $this->postJson('/api/auth/login', ['email' => 'd@example.com', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_a_successful_login_clears_the_failed_attempt_counter(): void
    {
        User::factory()->create([
            'email'    => 'e@example.com',
            'password' => Hash::make('Password123'),
            'role'     => 'student',
        ]);

        // Three typos, a success, then three more typos. Only failures are
        // counted and success resets them, so the total never reaches the limit
        // and the user is never locked out of an account they can log into.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'e@example.com', 'password' => 'nope'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'e@example.com', 'password' => 'Password123'])
            ->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'e@example.com', 'password' => 'nope'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'e@example.com', 'password' => 'Password123'])
            ->assertOk();
    }

    public function test_locking_one_account_does_not_lock_another(): void
    {
        User::factory()->create([
            'email'    => 'victim@example.com',
            'password' => Hash::make('Password123'),
            'role'     => 'student',
        ]);
        User::factory()->create([
            'email'    => 'bystander@example.com',
            'password' => Hash::make('Password123'),
            'role'     => 'student',
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'victim@example.com', 'password' => 'nope']);
        }

        $this->postJson('/api/auth/login', ['email' => 'victim@example.com', 'password' => 'Password123'])
            ->assertStatus(429);

        // Same IP, different account — must be unaffected, otherwise one bad
        // actor on a shared campus network could lock out the whole school.
        $this->postJson('/api/auth/login', ['email' => 'bystander@example.com', 'password' => 'Password123'])
            ->assertOk();
    }
}
