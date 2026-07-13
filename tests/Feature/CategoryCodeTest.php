<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('staff');
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'staff']);
        $user->assignRole('staff');

        return $user;
    }

    public function test_a_category_requires_a_code(): void
    {
        // The code used to be invented from the name by the frontend. It is now
        // authored, so it is required — there is nothing left to fall back to.
        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/categories', ['name' => 'College of Engineering'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    /**
     * The bug that motivated all of this. The frontend derived a code as
     * `name.slice(0, 4).toUpperCase()`, so CABM-B and CABM-H BOTH became "CABM",
     * and Special Collections and Special Boholano Creations both became "SPEC".
     * Two distinct collections shared one identifier — and one icon, and one
     * breadcrumb. A code that can collide is not an identifier.
     */
    public function test_two_categories_cannot_share_a_code(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/categories', ['name' => 'Business Management', 'code' => 'CABM-B'])
            ->assertCreated();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/categories', ['name' => 'Hospitality Management', 'code' => 'CABM-B'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // ...but the genuinely distinct code is accepted, which is the whole
        // point: these two collections can finally be told apart.
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/categories', ['name' => 'Hospitality Management', 'code' => 'CABM-H'])
            ->assertCreated();
    }

    public function test_the_code_is_stored_uppercase(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/categories', ['name' => 'Institutional Publications', 'code' => 'ip'])
            ->assertCreated()
            ->assertJsonPath('code', 'IP');
    }

    public function test_the_code_rejects_characters_that_are_not_code_like(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/categories', ['name' => 'Weird', 'code' => 'a b/c!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_editing_a_category_keeps_its_own_code_available(): void
    {
        $staff = $this->staff();
        $category = Category::create(['name' => 'Nursing', 'code' => 'CON', 'created_by' => $staff->id]);

        // Re-saving a category without changing its code must not trip the
        // uniqueness rule against itself.
        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/categories/{$category->id}", ['name' => 'College of Nursing', 'code' => 'CON'])
            ->assertOk()
            ->assertJsonPath('code', 'CON')
            ->assertJsonPath('name', 'College of Nursing');
    }

    /**
     * Search results carry their category. They did not: the moment a user typed
     * a query, every result card lost its department tag, its cover image and its
     * icon — while the same cards showed all three when browsing without one.
     */
    public function test_search_results_include_the_category(): void
    {
        $staff = $this->staff();
        $category = Category::create(['name' => 'Nursing', 'code' => 'CON', 'created_by' => $staff->id]);

        \App\Models\Thesis::withoutSyncingToSearch(fn () => \App\Models\Thesis::create([
            'title'          => 'A study of findable things',
            'authors'        => 'Author',
            'adviser'        => 'Adviser',
            'abstract'       => '',
            'keywords'       => null,
            'year_published' => 2024,
            'category_id'    => $category->id,
            'pages'          => 10,
            'file_path'      => 'theses/x.pdf',
            'status'         => 'active',
            'uploaded_by'    => $staff->id,
        ]));

        $body = $this->getJson('/api/search?q=findable')->assertOk()->json();
        $results = $body['data'] ?? $body;

        $this->assertNotEmpty($results);
        $this->assertSame('CON', $results[0]['category']['code'] ?? null);
    }
}
