<?php

namespace App\Console\Commands;

use App\Jobs\ProcessThesisOcr;
use App\Models\User;
use App\Support\PdfNormalizer;
use App\Support\SchoolRoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Is this server ready, and is it still healthy? Run it after installing, after
 * each update, or whenever something seems off:
 *
 *   php artisan deploy:check
 *
 * Several failures here are silent in normal use: without a worker documents are
 * simply never read, and without the scheduler backups never happen. FAIL means
 * fix it before people rely on the system (exit code 1); WARN means look at it.
 * It reads only and changes nothing, apart from one short-lived cache value.
 */
class DeployCheck extends Command
{
    protected $signature = 'deploy:check';

    protected $description = 'Check that this server is correctly set up to run TIPIGANAN';

    private const DEMO_IDS = ['90001', '90002', '90003', '90004', '90005', '90006'];

    /** The backup runs daily at 02:00, so anything older means the scheduler stopped. */
    private const BACKUP_MAX_AGE_HOURS = 48;

    /** A worker takes a waiting job within seconds, unless it is busy with another. */
    private const JOB_MAX_WAIT_MINUTES = 30;

    private int $failed = 0;

    private int $warned = 0;

    public function handle(): int
    {
        $this->settings();
        $this->database();
        $this->files();
        $this->tools();
        $this->services();
        $this->backgroundWork();
        $this->accounts();

        $this->newLine();
        $this->line("{$this->failed} failed, {$this->warned} to look at.");

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function settings(): void
    {
        $this->section('Settings');

        $this->result(app()->isProduction(), 'APP_ENV is production', 'APP_ENV is '.app()->environment().', so demo accounts can be created and errors are less protected');
        $this->result(! config('app.debug'), 'APP_DEBUG is off', 'APP_DEBUG is on, which shows internal details on every error');
        $this->result(filled(config('app.key')), 'APP_KEY is set', 'APP_KEY is empty. Run: php artisan key:generate');
        $this->result(Str::startsWith((string) config('app.url'), 'https://'), 'APP_URL uses https', 'APP_URL is not https: '.config('app.url'));
        $this->result(! Str::contains((string) config('app.url').implode(',', (array) config('cors.allowed_origins')), 'CHANGE_ME'), 'No CHANGE_ME values left', 'A CHANGE_ME value is still in .env');
        $this->result(config('app.timezone') === 'Asia/Manila', 'Timezone is Asia/Manila', 'Timezone is '.config('app.timezone').', so reports will be off by hours');
        $this->result(config('school.directory.driver') === 'csv', 'The school list is enforced (csv)', 'SCHOOL_DIRECTORY_DRIVER is '.config('school.directory.driver').', so sign-in does not check the list');
        $this->result(config('queue.default') === 'database', 'Documents are read in the background (database queue)', 'QUEUE_CONNECTION is '.config('queue.default').', so reading a scan ties up the upload for minutes and can be cut off');

        // A database job still running after retry_after looks abandoned, so a
        // second worker would start it again and the job would then be failed.
        $timeout = (int) ((new \ReflectionClass(ProcessThesisOcr::class))->getDefaultProperties()['timeout'] ?? 0);
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $this->result($retryAfter > $timeout, "DB_QUEUE_RETRY_AFTER ({$retryAfter}s) is longer than the OCR limit ({$timeout}s)", "DB_QUEUE_RETRY_AFTER is {$retryAfter}s but OCR may run {$timeout}s. Set it to 1000");
    }

    private function database(): void
    {
        $this->section('Database');

        try {
            DB::connection()->getPdo();
            $this->result(true, 'Connected to '.DB::connection()->getDatabaseName());
        } catch (\Throwable $e) {
            $this->result(false, '', 'Cannot connect to the database: '.$e->getMessage());

            return;
        }

        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());
        $this->result(! $pending, 'All migrations have run', count($pending).' migration(s) are waiting. Run: php artisan migrate --force');

        $roles = Role::whereIn('name', SchoolRoster::ROLES)->count();
        $this->result($roles === count(SchoolRoster::ROLES), 'Roles and permissions are set up', 'Roles are missing. Run: php artisan db:seed --force');
    }

    private function files(): void
    {
        $this->section('Files');

        foreach (['storage/app/private', 'storage/app/public', 'storage/logs', 'storage/framework/cache', 'bootstrap/cache'] as $dir) {
            $path = base_path($dir);
            $this->result(is_dir($path) && is_writable($path), "{$dir} is writable", "{$dir} cannot be written to by ".(function_exists('get_current_user') ? get_current_user() : 'this user'));
        }

        $this->result(file_exists(public_path('storage')), 'Public files are linked', 'public/storage is missing. Run: php artisan storage:link');
    }

    private function tools(): void
    {
        $this->section('Tools');

        $node = $this->firstLineOf(['node', '--version']);
        $this->result($node !== null, 'Node.js '.$node.' (reads documents)', 'Node.js was not found, so documents cannot be read');

        $missing = array_filter(['pdf-parse', 'pdf-to-img', 'tesseract.js'], fn ($p) => ! is_dir(base_path("node_modules/{$p}")));
        $this->result(! $missing, 'OCR packages are installed', 'Missing '.implode(', ', $missing).'. Run: npm ci --omit=dev');

        $this->result(PdfNormalizer::isAvailable(), 'qpdf is available (PDFs open in the viewer)', 'qpdf was not found, so many PDFs will not open in the viewer');

        $dump = $this->firstLineOf([config('database.dump.binary', 'mysqldump'), '--version']);
        $this->result($dump !== null, 'mysqldump is available (backups)', 'mysqldump was not found, so backups fail. Set DB_DUMP_BINARY');
    }

    private function services(): void
    {
        $this->section('Services');

        try {
            $key = 'deploy-check-'.Str::random(8);
            Cache::put($key, 'ok', 30);
            $works = Cache::get($key) === 'ok';
            Cache::forget($key);
            $this->result($works, 'Cache works ('.config('cache.default').')', 'The cache did not keep a value ('.config('cache.default').')');
        } catch (\Throwable $e) {
            $this->required('The cache ('.config('cache.default').') is not reachable: '.$e->getMessage());
        }

        // Required on a live system. The app can fall back without them, which
        // is what keeps it up during an outage, so nothing else would report it.
        if (config('cache.default') !== 'redis') {
            $this->required('CACHE_STORE is '.config('cache.default').', but Redis is required on a live system');
        }

        if (config('scout.driver') !== 'meilisearch') {
            $this->required('SCOUT_DRIVER is '.config('scout.driver').', but Meilisearch is required on a live system');

            return;
        }

        try {
            $healthy = Http::timeout(3)->get(rtrim((string) config('scout.meilisearch.host'), '/').'/health')->successful();
        } catch (\Throwable) {
            $healthy = false;
        }
        $healthy
            ? $this->result(true, 'Meilisearch is running')
            : $this->required('Meilisearch is not reachable. Search is falling back to MySQL, without typo tolerance');
    }

    private function backgroundWork(): void
    {
        $this->section('Background work');

        $stuck = DB::table('jobs')->whereNull('reserved_at')
            ->where('available_at', '<', now()->subMinutes(self::JOB_MAX_WAIT_MINUTES)->getTimestamp())->count();
        $this->result($stuck === 0, 'No documents are waiting too long', "{$stuck} document(s) have waited over ".self::JOB_MAX_WAIT_MINUTES.' minutes, so the worker is probably not running');

        $failed = DB::table('failed_jobs')->count();
        if ($failed > 0) {
            $this->warning("{$failed} background job(s) failed. See: php artisan queue:failed");
        }

        $backups = glob(storage_path('app/backups/tipiganan-*.sql')) ?: [];
        if (! $backups) {
            $this->warning('No database backup exists yet. If this is not a new server, the scheduler is not running');
        } else {
            $hours = (time() - max(array_map('filemtime', $backups))) / 3600;
            $this->result($hours <= self::BACKUP_MAX_AGE_HOURS, 'The newest backup is '.round($hours).' hour(s) old', 'The newest backup is '.round($hours).' hours old, so the scheduler is probably not running');
        }
    }

    private function accounts(): void
    {
        $this->section('Accounts');

        $admins = User::where('role', 'super_admin')->where('status', 'active')->where('needs_activation', false)->count();
        $this->result($admins > 0, "{$admins} active Super Admin(s)", 'No active Super Admin. Run: php artisan accounts:create-super-admin');

        if (! SchoolRoster::isLoaded()) {
            $this->warning('No school list is imported yet. Nothing can be activated until one is');
        }

        // The seeded password is public: it is in the team guide and the repo.
        $demo = User::whereIn('id_number', self::DEMO_IDS)->get()->filter(fn ($u) => Hash::check('password', $u->password));
        if ($demo->isEmpty()) {
            $this->result(true, 'No demo accounts with the password "password"');
        } elseif (app()->isProduction()) {
            $this->result(false, '', 'Demo accounts with the password "password" can sign in: '.$demo->pluck('id_number')->implode(', '));
        } else {
            $this->warning('Demo accounts exist: '.$demo->pluck('id_number')->implode(', ').'. Fine for testing, not on a live system');
        }
    }

    /** The command's first line of output, or null if it cannot run. */
    private function firstLineOf(array $command): ?string
    {
        try {
            $result = Process::timeout(10)->run($command);

            return $result->successful() ? trim(strtok($result->output(), "\n") ?: '') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function section(string $name): void
    {
        $this->newLine();
        $this->line($name);
    }

    private function result(bool $ok, string $pass, string $fail = ''): void
    {
        if ($ok) {
            $this->line("  ok    {$pass}");

            return;
        }

        $this->failed++;
        $this->line("  FAIL  {$fail}");
    }

    /** A failure on a live system; on a development machine, only worth a look. */
    private function required(string $message): void
    {
        app()->isProduction() ? $this->result(false, '', $message) : $this->warning($message);
    }

    private function warning(string $message): void
    {
        $this->warned++;
        $this->line("  WARN  {$message}");
    }
}
