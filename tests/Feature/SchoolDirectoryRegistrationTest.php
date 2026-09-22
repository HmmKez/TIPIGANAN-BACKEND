<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Registration verified against the school's student/faculty records.
 *
 * Runs against FakeSchoolDirectory and the checked-in fixture rather than any
 * real system - which is the point of the fixture, and why it stays in the
 * project permanently even after the school's own API is connected.
 */
class SchoolDirectoryRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Verification is off by default so the feature can ship without
        // changing anything for anyone; these tests opt in explicitly.
        config(['school.directory.driver' => 'fake']);

        Role::findOrCreate('student');
        Role::findOrCreate('teacher');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id_number'             => '10234',
            'email'                 => 'jdelacruz@example.com',
            'password'              => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    /**
     * THE test for this feature.
     *
     * Before the school directory existed, registration accepted a self-declared
     * role, so anyone could tick "teacher" and get teacher-level access. Nothing
     * stops a client skipping the verify step and posting straight here with a
     * forged role, so the guarantee has to be that the school's answer wins.
     */
    public function test_the_role_comes_from_the_school_not_from_the_registrant(): void
    {
        // 10234 is a STUDENT in the fixture. Claim to be a teacher anyway.
        $this->postJson('/api/auth/register', $this->payload(['role' => 'teacher']))
            ->assertStatus(201);

        $user = User::where('id_number', '10234')->firstOrFail();

        $this->assertSame('student', $user->role, 'A registrant claiming to be a teacher was believed.');
        $this->assertTrue($user->hasRole('student'));
        $this->assertFalse($user->hasRole('teacher'));
    }

    public function test_a_faculty_id_is_registered_as_a_teacher(): void
    {
        // The school says "faculty"; TIPIGANAN's word for that role is "teacher".
        $this->postJson('/api/auth/register', $this->payload([
            'id_number' => '20101',
            'email'     => 'rvillanueva@example.com',
        ]))->assertStatus(201);

        $user = User::where('id_number', '20101')->firstOrFail();

        $this->assertSame('teacher', $user->role);
        $this->assertTrue($user->hasRole('teacher'));
    }

    public function test_an_unknown_id_number_cannot_register(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['id_number' => '77777']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_number');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_graduate_cannot_register(): void
    {
        // 10001 exists in the school's records but has graduated - the exact
        // case this whole feature is meant to catch.
        $this->postJson('/api/auth/register', $this->payload(['id_number' => '10001']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_number');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_resigned_faculty_cannot_register(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['id_number' => '20099']))
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * The decision recorded in PROJECT-STATUS #26: when the school's system
     * cannot be reached, refuse. Letting unverified accounts through whenever
     * their server hiccups would defeat the point of verifying at all.
     */
    public function test_registration_is_refused_when_the_school_system_is_unreachable(): void
    {
        config(['school.directory.simulate_outage' => true]);

        $this->postJson('/api/auth/register', $this->payload())
            ->assertStatus(503)
            ->assertJsonValidationErrors('id_number');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_outage_is_not_reported_as_an_unknown_id(): void
    {
        config(['school.directory.simulate_outage' => true]);

        $message = $this->postJson('/api/auth/register/verify', ['id_number' => '10234'])
            ->assertStatus(503)
            ->json('errors.id_number.0');

        // "We don't know" must never be phrased as "no such person" - otherwise
        // an outage sends legitimate students to the library to fix an ID number
        // that was correct all along.
        $this->assertStringNotContainsString('could not find', $message);
        $this->assertStringContainsString("couldn't reach", $message);
    }

    public function test_the_verify_step_returns_a_first_name_but_never_the_full_name(): void
    {
        $response = $this->postJson('/api/auth/register/verify', ['id_number' => '10234'])
            ->assertStatus(200)
            ->assertJson([
                'verification_required' => true,
                'first_name'            => 'Juan',
                'role'                  => 'student',
            ]);

        // Deliberate: the endpoint reveals whether an ID is real, so it must not
        // also hand out full names to anyone sweeping ID numbers.
        $this->assertStringNotContainsString('Dela Cruz', $response->getContent());
    }

    public function test_a_surname_first_record_still_greets_by_the_given_name(): void
    {
        // Records systems commonly store "Otadoy, Rowena Mae".
        $this->postJson('/api/auth/register/verify', ['id_number' => '10791'])
            ->assertStatus(200)
            ->assertJson(['first_name' => 'Rowena']);
    }

    public function test_an_id_number_with_a_leading_zero_verifies_and_registers(): void
    {
        // Any system storing an ID as an integer turns 00042 into 42 and locks
        // this person out permanently.
        $this->postJson('/api/auth/register/verify', ['id_number' => '00042'])
            ->assertStatus(200)
            ->assertJson(['first_name' => 'Grace']);

        $this->postJson('/api/auth/register', $this->payload([
            'id_number' => '00042',
            'email'     => 'gbongcales@example.com',
        ]))->assertStatus(201);

        $this->assertDatabaseHas('users', ['id_number' => '00042']);
    }

    public function test_verify_refuses_an_id_number_that_already_has_an_account(): void
    {
        User::create([
            'id_number' => '10234',
            'email'     => 'taken@example.com',
            'password'  => Hash::make('Password123'),
            'role'      => 'student',
            'status'    => 'active',
        ]);

        $this->postJson('/api/auth/register/verify', ['id_number' => '10234'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_number');
    }

    public function test_registration_records_what_the_school_said(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertStatus(201);

        $user = User::where('id_number', '10234')->firstOrFail();

        $this->assertSame('Juan Dela Cruz', $user->name, 'The name should come from the school, not be left blank.');
        $this->assertSame('enrolled', $user->directory_status);
        $this->assertSame('MDC-0000481922', $user->directory_person_key);
        $this->assertNotNull($user->directory_checked_at);
    }

    /**
     * The verify endpoint answers "is this ID real?", which is exactly what an
     * enumeration sweep needs. Unlike login, EVERY attempt has to count towards
     * the limit - counting only failures would leave a successful sweep
     * completely unthrottled.
     */
    public function test_the_verify_step_throttles_repeated_attempts(): void
    {
        config(['school.directory.verify_attempts_per_minute' => 3]);

        // Three lookups that all SUCCEED - the case a failures-only counter
        // would happily let run forever.
        foreach (['10234', '10235', '20101'] as $idNumber) {
            $this->postJson('/api/auth/register/verify', ['id_number' => $idNumber])->assertStatus(200);
        }

        $this->postJson('/api/auth/register/verify', ['id_number' => '10456'])
            ->assertStatus(429);
    }

    public function test_verification_switched_off_leaves_registration_exactly_as_it_was(): void
    {
        config(['school.directory.driver' => 'off']);

        // An ID number that is in no directory at all, and a self-declared role.
        $this->postJson('/api/auth/register', $this->payload([
            'id_number' => '77777',
            'role'      => 'teacher',
        ]))->assertStatus(201);

        $this->assertDatabaseHas('users', ['id_number' => '77777', 'role' => 'teacher']);
    }

    /**
     * The registration page asks this before showing anything, and shows the
     * original single-step form unless verification is on. Getting it wrong in
     * the "off" direction is the costly one: the page would make every
     * registrant pass a check that checks nothing.
     */
    public function test_registration_options_report_verification_off_by_default(): void
    {
        config(['school.directory.driver' => 'off']);

        $this->getJson('/api/auth/register/options')
            ->assertStatus(200)
            ->assertExactJson(['verification_required' => false]);
    }

    public function test_registration_options_report_verification_on_when_enabled(): void
    {
        // setUp() already switched the driver to `fake`.
        $this->getJson('/api/auth/register/options')
            ->assertStatus(200)
            ->assertExactJson(['verification_required' => true]);
    }

    public function test_verify_tells_the_frontend_when_verification_is_switched_off(): void
    {
        config(['school.directory.driver' => 'off']);

        $this->postJson('/api/auth/register/verify', ['id_number' => '77777'])
            ->assertStatus(200)
            ->assertJson(['verification_required' => false]);
    }
}
