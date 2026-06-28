<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Thesis;
use App\Models\User;
use Illuminate\Database\Seeder;

class ThesisSeeder extends Seeder
{
    public function run(): void
    {
        $staff    = User::where('role', 'staff')->first();
        $cast     = Category::where('name', 'CAST')->first();
        $coe      = Category::where('name', 'COE')->first();

        $theses = [
            [
                'title'          => 'Smart Attendance Monitoring System Using RFID',
                'authors'        => 'dela Cruz, Juan A.; Reyes, Maria B.',
                'adviser'        => 'Dr. Jose Santos',
                'abstract'       => 'This study presents a smart attendance monitoring system using RFID technology to automate student attendance tracking in educational institutions.',
                'keywords'       => 'RFID, attendance, monitoring, automation',
                'year_published' => 2024,
                'category_id'   => $cast->id,
                'pages'          => 98,
                'file_path'      => 'theses/sample1.pdf',
                'status'         => 'active',
                'uploaded_by'    => $staff->id,
            ],
            [
                'title'          => 'Web-Based Inventory Management System for Small Businesses',
                'authors'        => 'Garcia, Pedro C.; Lim, Ana D.',
                'adviser'        => 'Prof. Maria Fernandez',
                'abstract'       => 'This research proposes a web-based inventory management system designed to help small businesses efficiently manage their stock and reduce losses.',
                'keywords'       => 'inventory, web-based, small business, management',
                'year_published' => 2023,
                'category_id'   => $coe->id,
                'pages'          => 112,
                'file_path'      => 'theses/sample2.pdf',
                'status'         => 'active',
                'uploaded_by'    => $staff->id,
            ],
        ];

        foreach ($theses as $thesis) {
            Thesis::firstOrCreate(
                ['title' => $thesis['title']],
                $thesis
            );
        }
    }
}