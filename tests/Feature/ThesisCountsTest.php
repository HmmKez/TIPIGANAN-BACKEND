<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\ReadingHistory;
use App\Models\Thesis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The per-item counts Browse renders (views, bookmarks) are computed by three
// different code paths - the plain listing, the Meilisearch search, and the
// MySQL fallback search - so a count can be correct in one and silently absent
// in another. That is exactly what happened twice: views_count was missing from
// the list endpoints, and bookmark_count was never produced at all while the
// frontend happily read it.
//
// Both failures were INVISIBLE rather than loud: the UI guards these with
// `|| 0` and `!= null`, so a missing field renders as zero or hides the badge
// instead of raising anything. Only a test that asserts the field is actually
// present can catch that.
class ThesisCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'staff']);
        $this->category = Category::create(['name' => 'CS', 'created_by' => $this->user->id]);
    }

    private function makeThesis(string $title = 'Findable Thesis'): Thesis
    {
        return Thesis::create([
            'title'          => $title,
            'authors'        => 'Author',
            'adviser'        => 'Adviser',
            'abstract'       => '',
            'keywords'       => null,
            'year_published' => 2024,
            'category_id'    => $this->category->id,
            'pages'          => 10,
            'file_path'      => 'theses/x.pdf',
            'status'         => 'active',
            'uploaded_by'    => $this->user->id,
        ]);
    }

    public function test_the_listing_returns_both_counts_with_real_values(): void
    {
        $thesis = $this->makeThesis();

        $readers = User::factory()->count(2)->create(['role' => 'student']);
        foreach ($readers as $r) {
            Favorite::create(['user_id' => $r->id, 'thesis_id' => $thesis->id]);
        }
        ReadingHistory::create([
            'user_id' => $readers[0]->id, 'thesis_id' => $thesis->id, 'viewed_at' => now(),
        ]);

        $row = collect($this->getJson('/api/theses')->assertOk()->json('data'))
            ->firstWhere('id', $thesis->id);

        // assertArrayHasKey, not just a value check: the bug was an ABSENT key,
        // and `null == 0` would let a value-only assertion pass against it.
        $this->assertArrayHasKey('bookmark_count', $row, 'Browse reads bookmark_count; the API must send it.');
        $this->assertArrayHasKey('views_count', $row);
        $this->assertSame(2, $row['bookmark_count']);
        $this->assertSame(1, $row['views_count']);
    }

    public function test_search_results_carry_the_same_counts_as_the_listing(): void
    {
        // Guards the path that broke before: a card loses its counts the moment
        // the user types a query, because search hydrates results separately.
        $thesis = $this->makeThesis('Uniquely Findable Thesis');

        $reader = User::factory()->create(['role' => 'student']);
        Favorite::create(['user_id' => $reader->id, 'thesis_id' => $thesis->id]);

        $results = $this->getJson('/api/search?q=Uniquely')->assertOk()->json('data');
        $row = collect($results)->firstWhere('id', $thesis->id);

        $this->assertNotNull($row, 'The seeded thesis should be findable by search.');
        $this->assertArrayHasKey('bookmark_count', $row, 'Search results must carry bookmark_count too.');
        $this->assertArrayHasKey('views_count', $row);
        $this->assertSame(1, $row['bookmark_count']);
    }

    public function test_bookmarks_still_work_after_the_table_rename(): void
    {
        // The favorites table is now called bookmarks. The model, the relations
        // and the /api/favorites routes all still have to resolve against it.
        $thesis = $this->makeThesis();
        $reader = User::factory()->create(['role' => 'student']);

        $this->actingAs($reader)->postJson("/api/favorites/{$thesis->id}")->assertCreated();
        $this->assertDatabaseHas('bookmarks', ['user_id' => $reader->id, 'thesis_id' => $thesis->id]);

        // Adding the same one twice is a conflict, not a duplicate row.
        $this->actingAs($reader)->postJson("/api/favorites/{$thesis->id}")->assertStatus(409);

        $this->assertCount(1, $this->actingAs($reader)->getJson('/api/favorites')->assertOk()->json());

        $this->actingAs($reader)->deleteJson("/api/favorites/{$thesis->id}")->assertOk();
        $this->assertDatabaseMissing('bookmarks', ['user_id' => $reader->id, 'thesis_id' => $thesis->id]);
    }
}
