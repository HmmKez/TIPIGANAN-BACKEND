<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'super_admin')->first();

        $departments = [
            'CAST',
            'COE',
            'CABM-B',
            'CABM-H',
            'CCJ',
            'CON',
            'Graduate Studies',
            'Special Collections',
            'Faculty Research',
            'Institutional Publications',
        ];

        foreach ($departments as $dept) {
            Category::firstOrCreate(
                ['name' => $dept],
                ['created_by' => $admin->id]
            );
        }
    }
}