<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Super Admin
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@tipiganan.com'],
            [
                'name'     => 'Super Admin',
                'password' => Hash::make('password'),
                'role'     => 'super_admin',
                'status'   => 'active',
            ]
        );
        $superAdmin->assignRole('super_admin');

        // Staff
        $staff = User::firstOrCreate(
            ['email' => 'staff@tipiganan.com'],
            [
                'name'     => 'Library Staff',
                'password' => Hash::make('password'),
                'role'     => 'staff',
                'status'   => 'active',
            ]
        );
        $staff->assignRole('staff');

        // Student
        $student = User::firstOrCreate(
            ['email' => 'student@tipiganan.com'],
            [
                'name'     => 'Juan dela Cruz',
                'password' => Hash::make('password'),
                'role'     => 'student',
                'status'   => 'active',
            ]
        );
        $student->assignRole('student');

        // Teacher
        $teacher = User::firstOrCreate(
            ['email' => 'teacher@tipiganan.com'],
            [
                'name'     => 'Prof. Santos',
                'password' => Hash::make('password'),
                'role'     => 'teacher',
                'status'   => 'active',
            ]
        );
        $teacher->assignRole('teacher');
    }
}