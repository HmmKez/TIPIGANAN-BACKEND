<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Thesis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ThesisVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $uploader;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploader = User::factory()->create(['role' => 'staff']);
        $this->category = Category::create(['name' => 'CS', 'created_by' => $this->uploader->id]);
    }

    private function makeThesis(string $status): Thesis
    {
        return Thesis::create([
            'title'          => "Thesis {$status}",
            'authors'        => 'Author',
            'adviser'        => 'Adviser',
            'abstract'       => '',
            'keywords'       => null,
            'year_published' => 2024,
            'category_id'    => $this->category->id,
            'pages'          => 10,
            'file_path'      => "theses/{$status}.pdf",
            'status'         => $status,
            'uploaded_by'    => $this->uploader->id,
        ]);
    }

    private function student(): User
    {
        Role::findOrCreate('student');
        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        return $student;
    }

    public function test_guest_sees_only_active_theses_in_the_list(): void
    {
        $this->makeThesis('active');
        $this->makeThesis('restricted');
        $this->makeThesis('archived');

        $response = $this->getJson('/api/theses')->assertOk();

        $statuses = collect($response->json('data'))->pluck('status')->unique()->values()->all();
        $this->assertEquals(['active'], $statuses);
    }

    public function test_logged_in_user_sees_active_and_restricted_but_not_archived(): void
    {
        $this->makeThesis('active');
        $this->makeThesis('restricted');
        $this->makeThesis('archived');

        $response = $this->actingAs($this->student(), 'sanctum')
            ->getJson('/api/theses')->assertOk();

        $statuses = collect($response->json('data'))->pluck('status')->unique()->sort()->values()->all();
        $this->assertEquals(['active', 'restricted'], $statuses);
    }

    public function test_guest_cannot_open_a_restricted_thesis_detail(): void
    {
        $restricted = $this->makeThesis('restricted');

        $this->getJson("/api/theses/{$restricted->id}")->assertStatus(404);
    }

    public function test_logged_in_user_can_open_a_restricted_thesis_detail(): void
    {
        $restricted = $this->makeThesis('restricted');

        $this->actingAs($this->student(), 'sanctum')
            ->getJson("/api/theses/{$restricted->id}")
            ->assertOk();
    }
}
