<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // On a live system only the roles and permissions are set up: the rest
        // is demo data, and the demo accounts all use the password "password".
        if (app()->isProduction()) {
            $this->call(PermissionSeeder::class);
            return;
        }

        $this->call([
            PermissionSeeder::class,
            UserSeeder::class,
            CategorySeeder::class,
            ThesisSeeder::class,
        ]);
    }
}