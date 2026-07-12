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

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        User::factory()->create([
            'email'    => 'c@example.com',
            'password' => Hash::make('Password123'),
            'role'     => 'student',
        ]);

        // throttle:5,1 — the first five are allowed (wrong password → 422),
        // the sixth is blocked with 429.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'c@example.com', 'password' => 'nope']);
        }

        $this->postJson('/api/auth/login', ['email' => 'c@example.com', 'password' => 'nope'])
            ->assertStatus(429);
    }
}
