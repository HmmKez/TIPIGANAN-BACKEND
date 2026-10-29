<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * One-off, for the switch from self-registration to activation at the library:
 * every existing account must be activated at the library again, except the
 * ones kept. Nothing is deleted - bookmarks and history stay, and activation
 * gives the account a new email and password.
 *
 *   php artisan accounts:require-activation --keep=90001 --keep=90002 --keep=90003 --keep=90004
 */
class RequireAccountActivation extends Command
{
    protected $signature = 'accounts:require-activation
        {--keep=* : ID numbers that stay usable (repeat for each)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Make existing accounts be activated at the library again (except the ones kept)';

    public function handle(): int
    {
        $keep = array_values(array_filter((array) $this->option('keep')));
        if (! $keep) {
            $this->error('Name at least one account to keep with --keep=ID (for example the Super Admin), or nobody could sign in to activate the others.');
            return self::FAILURE;
        }

        $users = User::where('needs_activation', false)->whereNotIn('id_number', $keep)->orderBy('id_number')->get();
        if ($users->isEmpty()) {
            $this->info('Nothing to do: every other account already needs activation.');
            return self::SUCCESS;
        }

        $this->table(['ID number', 'Name', 'Role'], $users->map(fn ($u) => [$u->id_number, $u->display_name, $u->role])->all());
        if (! $this->option('force') && ! $this->confirm("These {$users->count()} accounts will need to be activated at the library again. Continue?")) {
            return self::FAILURE;
        }

        foreach ($users as $u) {
            $u->update(['needs_activation' => true]);
            $u->tokens()->delete();
        }

        $this->info("{$users->count()} accounts now need activation. Kept: ".implode(', ', $keep).'.');

        return self::SUCCESS;
    }
}
