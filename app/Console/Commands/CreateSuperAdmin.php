<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SchoolRoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Creates the first real Super Admin when the system is deployed. Only a Super
 * Admin can make another one, and activation never gives staff powers, so the
 * first one has to come from outside the app. Refused once an active Super Admin
 * exists; after that, everything is done on the website.
 *
 *   php artisan accounts:create-super-admin
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'accounts:create-super-admin';

    protected $description = 'Create the first Super Admin account when the system is deployed (works only once)';

    public function handle(): int
    {
        $active = User::where('role', 'super_admin')->where('status', 'active')->where('needs_activation', false);
        if ($active->exists()) {
            $this->error('An active Super Admin already exists, so this command will not create another. They can promote accounts in User Management.');
            return self::FAILURE;
        }

        if (! Role::where('name', 'super_admin')->exists()) {
            $this->error('The roles have not been set up yet. Run: php artisan migrate, then php artisan db:seed');
            return self::FAILURE;
        }

        $idNumber = (string) $this->ask('ID number');

        // Once a list is imported the name comes from it, as with every account,
        // so the next import does not see a different name.
        $listed = SchoolRoster::isLoaded() ? SchoolRoster::find($idNumber) : null;
        if (SchoolRoster::isLoaded() && ! $listed) {
            $this->error("ID number {$idNumber} is not on the school's list, so it could not sign in. Nothing was created.");
            return self::FAILURE;
        }
        $name = $listed ? SchoolRoster::fullName($listed) : (string) $this->ask('Name');

        $input = [
            'id_number'             => $idNumber,
            'name'                  => $name,
            'email'                 => (string) $this->ask('Email'),
            'password'              => (string) $this->secret('Password (nothing shows while typing)'),
            'password_confirmation' => (string) $this->secret('Confirm password'),
        ];

        $validator = Validator::make($input, [
            'id_number' => 'required|digits:5|unique:users,id_number',
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|max:255|unique:users,email',
            'password'  => ['required', 'string', 'confirmed', Password::default()],
        ], [
            'id_number.digits' => 'The ID number must be exactly 5 digits.',
            'id_number.unique' => 'That ID number already has an account.',
            'email.unique'     => 'That email is already used by another account.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }
            $this->line('Nothing was created. Run the command again.');
            return self::FAILURE;
        }

        $user = User::create([
            'id_number' => $idNumber,
            'name'      => $name,
            'email'     => $input['email'],
            'password'  => Hash::make($input['password']),
            'role'      => 'super_admin',
            'status'    => 'active',
        ]);
        $user->assignRole('super_admin');

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'create_first_super_admin',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "First Super Admin {$user->display_name} ({$idNumber}) created from the server",
        ]);

        $this->info("Super Admin account created for {$name} ({$idNumber}).");
        $this->line('They can now sign in and import the school list. Their ID number must be on every list.');

        return self::SUCCESS;
    }
}
