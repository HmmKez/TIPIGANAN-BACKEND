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

    // ---------------------------------------------------------------------
    // Asking for a status directly.
    //
    // `?status=` used to be applied verbatim, so the rules above could be
    // stepped around by anyone who typed a status into the address bar.
    // ---------------------------------------------------------------------

    public function test_a_guest_cannot_reach_archived_theses_by_asking_for_them(): void
    {
        $this->makeThesis('active');
        $this->makeThesis('archived');

        $this->assertSame(0, $this->getJson('/api/theses?status=archived')->assertOk()->json('total'));
    }

    /** The same hole exposed restricted theses, which are for members only. */
    public function test_a_guest_cannot_reach_restricted_theses_by_asking_for_them(): void
    {
        $this->makeThesis('restricted');

        $this->assertSame(0, $this->getJson('/api/theses?status=restricted')->assertOk()->json('total'));
    }

    public function test_a_reader_cannot_reach_archived_theses_by_asking_for_them(): void
    {
        $this->makeThesis('archived');

        $this->assertSame(0, $this->actingAs($this->student(), 'sanctum')
            ->getJson('/api/theses?status=archived')->assertOk()->json('total'));
    }

    public function test_staff_can_list_archived_theses_because_managing_them_is_their_job(): void
    {
        $this->makeThesis('active');
        $this->makeThesis('archived');

        $this->assertSame(1, $this->actingAs($this->uploader, 'sanctum')
            ->getJson('/api/theses?status=archived')->assertOk()->json('total'));
    }

    // ---------------------------------------------------------------------
    // Opening an archived thesis.
    //
    // Archived theses were hidden from staff as well as readers, so Collection
    // Management offered an Edit action that opened a broken screen.
    // ---------------------------------------------------------------------

    public function test_staff_can_open_an_archived_thesis(): void
    {
        $archived = $this->makeThesis('archived');

        $this->actingAs($this->uploader, 'sanctum')
            ->getJson("/api/theses/{$archived->id}")
            ->assertOk()
            ->assertJsonPath('thesis.status', 'archived');
    }

    public function test_a_super_admin_can_open_an_archived_thesis(): void
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create(['role' => 'super_admin']);
        $admin->assignRole('super_admin');

        $archived = $this->makeThesis('archived');

        $this->actingAs($admin, 'sanctum')->getJson("/api/theses/{$archived->id}")->assertOk();
    }

    public function test_a_reader_cannot_open_an_archived_thesis(): void
    {
        $archived = $this->makeThesis('archived');

        $this->actingAs($this->student(), 'sanctum')
            ->getJson("/api/theses/{$archived->id}")
            ->assertStatus(404);
    }

    public function test_a_guest_cannot_open_an_archived_thesis(): void
    {
        $archived = $this->makeThesis('archived');

        $this->getJson("/api/theses/{$archived->id}")->assertStatus(404);
    }

    /**
     * The refusal must not leak the framework's own wording. Staff opening an
     * archived item were shown "No query results for model [App\Models\Thesis]
     * 56" on screen, above an empty form.
     */
    public function test_an_unavailable_thesis_does_not_leak_the_internal_error(): void
    {
        $archived = $this->makeThesis('archived');

        $message = (string) $this->getJson("/api/theses/{$archived->id}")->json('message');

        $this->assertStringNotContainsString('No query results', $message);
        $this->assertStringNotContainsString('App\Models', $message);
        $this->assertSame('That item is not available.', $message);
    }

    // ---------------------------------------------------------------------
    // Search follows the same rule.
    //
    // SearchService hard-coded active/restricted for everyone, so staff saw an
    // archived item while browsing and lost it the moment they typed a search.
    // ---------------------------------------------------------------------

    private function searchStatuses($as, string $query = 'Thesis'): array
    {
        $request = $as ? $this->actingAs($as, 'sanctum') : $this;

        return collect($request->getJson('/api/search?' . $query)->assertOk()->json('data'))
            ->pluck('status')->sort()->values()->all();
    }

    public function test_staff_search_includes_archived_theses(): void
    {
        $this->makeThesis('active');
        $this->makeThesis('restricted');
        $this->makeThesis('archived');

        $this->assertSame(['active', 'archived', 'restricted'], $this->searchStatuses($this->uploader, 'q=Thesis'));
    }

    public function test_reader_and_guest_search_never_include_archived_theses(): void
    {
        $this->makeThesis('active');
        $this->makeThesis('restricted');
        $this->makeThesis('archived');

        // Guest first: once actingAs() has run, every later request in this
        // test is signed in, so a "guest" check after it would test nothing.
        $this->assertSame(['active'], $this->searchStatuses(null, 'q=Thesis'));
        $this->assertSame(['active', 'restricted'], $this->searchStatuses($this->student(), 'q=Thesis'));
    }

    public function test_staff_can_narrow_a_search_to_one_status(): void
    {
        $this->makeThesis('active');
        $this->makeThesis('archived');

        $this->assertSame(['archived'], $this->searchStatuses($this->uploader, 'q=Thesis&status=archived'));
        $this->assertSame(['active'], $this->searchStatuses($this->uploader, 'q=Thesis&status=active'));
    }

    public function test_a_guest_cannot_reach_archived_theses_by_asking_search_for_them(): void
    {
        $this->makeThesis('archived');

        // Guest first, for the same reason as above.
        $this->assertSame([], $this->searchStatuses(null, 'q=Thesis&status=archived'));
        $this->assertSame([], $this->searchStatuses($this->student(), 'q=Thesis&status=archived'));
    }

    // ---------------------------------------------------------------------
    // Reading an archived thesis.
    //
    // The PDF token hard-coded active/restricted, so staff could open an
    // archived item's page but got a 404 the moment they pressed Read.
    // ---------------------------------------------------------------------

    public function test_staff_can_read_an_archived_thesis(): void
    {
        $archived = $this->makeThesis('archived');

        $this->actingAs($this->uploader, 'sanctum')
            ->postJson("/api/theses/{$archived->id}/view-token")
            ->assertOk();
    }

    public function test_a_reader_cannot_read_an_archived_thesis(): void
    {
        $archived = $this->makeThesis('archived');

        $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/theses/{$archived->id}/view-token")
            ->assertNotFound();
    }
}
