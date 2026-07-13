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

    public function test_category_counts_separate_visible_theses_from_archived_ones(): void
    {
        // Regression: /categories exposed only a bare `theses_count` that
        // included archived theses, so the Browse filter advertised a bigger
        // number than opening it returned, and the dashboard's tiles summed to
        // more than its own "Total Items". Staff still need the true total, so
        // the counts are reported separately rather than one being overwritten.
        $this->makeThesis('active');
        $this->makeThesis('active');
        $this->makeThesis('restricted');
        $this->makeThesis('archived');

        $category = $this->getJson('/api/categories')->assertOk()->json('0');

        $this->assertSame(4, $category['theses_count'], 'staff total: every status');
        $this->assertSame(2, $category['active_theses_count'], 'what a guest may open');
        $this->assertSame(1, $category['restricted_theses_count'], 'members only');
    }

    public function test_the_category_badge_matches_what_opening_that_category_returns(): void
    {
        // The number on the filter and the number of results behind it are
        // computed by different queries; this pins them together.
        $this->makeThesis('active');
        $this->makeThesis('active');
        $this->makeThesis('archived');

        $category = $this->getJson('/api/categories')->assertOk()->json('0');
        $badge = $category['active_theses_count'];

        $listed = $this->getJson("/api/theses?category_id={$this->category->id}")
            ->assertOk()->json('total');

        $this->assertSame($badge, $listed);
    }

    public function test_recently_added_filter_respects_visibility_and_the_window(): void
    {
        $this->makeThesis('active');
        $this->makeThesis('archived');                       // hidden regardless
        $old = $this->makeThesis('active');
        // forceFill, not update() — `created_at` is not mass-assignable, so a
        // plain update silently drops it and the thesis stays "new".
        $old->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        // One active thesis inside the window; the archived one must not leak in
        // just because it is recent, and the 90-day-old one is outside it.
        $this->assertSame(1, $this->getJson('/api/theses?added_within_days=30')->assertOk()->json('total'));

        // Widen the window and the older active thesis joins it — still no archived.
        $this->assertSame(2, $this->getJson('/api/theses?added_within_days=365')->assertOk()->json('total'));
    }
}
