<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SchoolRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** deploy:check must catch the mistakes that are silent in normal use. */
class DeployCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (SchoolRoster::ROLES as $r) {
            Role::findOrCreate($r);
        }
    }

    /** @var array<string, mixed> */
    private array $tools = [];

    private function runCheck(): array
    {
        Process::fake($this->tools + ['*' => Process::result('v1.0')]);

        $code = Artisan::call('deploy:check');

        return [$code, Artisan::output()];
    }

    private function good(): void
    {
        config([
            'app.debug' => false, 'app.url' => 'https://repo.example.com', 'app.key' => 'base64:abc', 'app.timezone' => 'Asia/Manila',
            'school.directory.driver' => 'csv', 'queue.default' => 'database', 'queue.connections.database.retry_after' => 1000,
            'scout.driver' => 'collection', 'cache.default' => 'array',
        ]);
    }

    public function test_it_flags_the_settings_that_are_silent_mistakes(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://example.com', 'queue.default' => 'sync',
            'queue.connections.database.retry_after' => 90, 'school.directory.driver' => 'off', 'cache.default' => 'array', 'scout.driver' => 'collection']);

        [$code, $out] = $this->runCheck();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  APP_DEBUG is on', $out);
        $this->assertStringContainsString('FAIL  APP_URL is not https', $out);
        $this->assertStringContainsString('FAIL  QUEUE_CONNECTION is sync', $out);
        $this->assertStringContainsString('FAIL  DB_QUEUE_RETRY_AFTER is 90s but OCR may run 900s', $out);
        $this->assertStringContainsString('FAIL  SCHOOL_DIRECTORY_DRIVER is off', $out);
    }

    public function test_good_settings_pass(): void
    {
        $this->good();

        [, $out] = $this->runCheck();

        foreach (['APP_DEBUG is off', 'APP_URL uses https', 'read in the background', 'is longer than the OCR limit (900s)', 'list is enforced', 'All migrations have run', 'Roles and permissions'] as $expected) {
            $this->assertMatchesRegularExpression('/ok    .*'.preg_quote($expected, '/').'/', $out);
        }
    }

    public function test_a_document_waiting_too_long_means_no_worker(): void
    {
        $this->good();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subHours(2)->getTimestamp(), 'created_at' => now()->subHours(2)->getTimestamp()]);

        [$code, $out] = $this->runCheck();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  1 document(s) have waited', $out);
    }

    public function test_demo_accounts_fail_only_on_a_live_system(): void
    {
        $this->good();
        $demo = User::create(['id_number' => '90001', 'email' => 'demo@example.com', 'password' => Hash::make('password'), 'role' => 'super_admin', 'status' => 'active']);

        [, $out] = $this->runCheck();
        $this->assertStringContainsString('WARN  Demo accounts exist: 90001', $out);

        $this->app->detectEnvironment(fn () => 'production');
        [$code, $out] = $this->runCheck();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Demo accounts with the password "password" can sign in: 90001', $out);

        // Same ID with a real password is not a demo account.
        $demo->update(['password' => Hash::make('SomethingReal9')]);
        [, $out] = $this->runCheck();
        $this->assertStringContainsString('No demo accounts', $out);
    }

    public function test_it_reports_a_missing_super_admin_and_list(): void
    {
        $this->good();

        [$code, $out] = $this->runCheck();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  No active Super Admin', $out);
        $this->assertStringContainsString('WARN  No school list is imported yet', $out);
    }

    public function test_a_missing_tool_is_reported(): void
    {
        $this->good();
        $this->tools = ['*node*' => Process::result('', 'not found', 1)];

        [, $out] = $this->runCheck();

        $this->assertStringContainsString('FAIL  Node.js was not found', $out);
    }
}
