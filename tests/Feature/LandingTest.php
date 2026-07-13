<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Setting;
use App\Models\Thesis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'staff', 'super_admin'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->assignRole($role);

        return $user;
    }

    private function thesis(Category $category, string $status): Thesis
    {
        return Thesis::withoutSyncingToSearch(fn () => Thesis::create([
            'title'          => "Thesis {$status} " . uniqid(),
            'authors'        => 'Author',
            'adviser'        => 'Adviser',
            'abstract'       => '',
            'keywords'       => null,
            'year_published' => 2024,
            'category_id'    => $category->id,
            'pages'          => 10,
            'file_path'      => "theses/{$status}.pdf",
            'status'         => $status,
            'uploaded_by'    => $category->created_by,
        ]));
    }

    public function test_the_landing_payload_is_public(): void
    {
        $this->getJson('/api/landing')
            ->assertOk()
            ->assertJsonStructure([
                'hero_image_path',
                'stats' => ['total_theses', 'total_categories', 'total_users', 'total_views'],
                'collections',
            ]);
    }

    public function test_stats_count_only_active_theses(): void
    {
        $category = Category::create([
            'name'       => 'CS',
            'created_by' => $this->userWithRole('super_admin')->id,
        ]);

        $this->thesis($category, 'active');
        $this->thesis($category, 'active');
        $this->thesis($category, 'archived');   // gone from the public collection
        $this->thesis($category, 'restricted'); // a guest cannot open it

        // The landing page is written for guests, so advertising archived or
        // restricted items would promise visitors things they cannot read.
        $this->getJson('/api/landing')
            ->assertOk()
            ->assertJsonPath('stats.total_theses', 2)
            ->assertJsonPath('collections.0.total', 2);
    }

    /**
     * Regression. The payload is cached; a cache backend that SERIALIZES (redis,
     * file, database — i.e. every real one) turned an Eloquent Collection left
     * inside it into a __PHP_Incomplete_Class, which JSON-encoded to
     * {"__PHP_Incomplete_Class_Name": "Illuminate\Support\Collection"} and broke
     * the page with "departments.map is not a function".
     *
     * The catch: it only failed on a cache HIT, so the first request always
     * looked fine. And the test suite's default `array` store does NOT serialize,
     * so it could never reproduce this — hence the explicit file store here.
     */
    public function test_departments_survive_a_serializing_cache(): void
    {
        Config::set('cache.default', 'file');
        Cache::store('file')->clear();

        $category = Category::create([
            'name'       => 'CS',
            'created_by' => $this->userWithRole('super_admin')->id,
        ]);
        $this->thesis($category, 'active');

        // First request: cache MISS — computed fresh, always looked correct.
        $this->getJson('/api/landing')->assertOk()->assertJsonPath('collections.0.total', 1);

        // Second request: cache HIT — this is the one that used to return garbage.
        $response = $this->getJson('/api/landing')->assertOk();

        $departments = $response->json('collections');

        $this->assertIsArray($departments, 'departments must be a JSON array, not a serialized object');
        $this->assertArrayNotHasKey('__PHP_Incomplete_Class_Name', $departments);
        $this->assertSame(1, $departments[0]['total']);

        Cache::store('file')->clear();
    }

    public function test_a_guest_cannot_change_the_hero_image(): void
    {
        Storage::fake('public');

        $this->postJson('/api/landing/hero', [
            'image' => UploadedFile::fake()->image('hero.jpg'),
        ])->assertStatus(401);
    }

    public function test_non_super_admin_roles_cannot_change_the_hero_image(): void
    {
        Storage::fake('public');

        foreach (['student', 'staff'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->postJson('/api/landing/hero', ['image' => UploadedFile::fake()->image('hero.jpg')])
                ->assertStatus(403, "role [{$role}] must not be able to change the hero image");
        }
    }

    public function test_a_super_admin_can_upload_and_reset_the_hero_image(): void
    {
        Storage::fake('public');
        $admin = $this->userWithRole('super_admin');

        $path = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/landing/hero', ['image' => UploadedFile::fake()->image('hero.jpg', 1600, 600)])
            ->assertOk()
            ->json('hero_image_path');

        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, Setting::get(Setting::LANDING_HERO_IMAGE));

        // Visible to the public immediately — i.e. the cache was invalidated.
        $this->getJson('/api/landing')->assertOk()->assertJsonPath('hero_image_path', $path);

        // Reset clears the setting AND removes the orphaned file.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/landing/hero')
            ->assertOk()
            ->assertJsonPath('hero_image_path', null);

        Storage::disk('public')->assertMissing($path);
        $this->getJson('/api/landing')->assertOk()->assertJsonPath('hero_image_path', null);
    }

    public function test_the_hero_image_must_actually_be_an_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->postJson('/api/landing/hero', ['image' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    // --- Featured collections ------------------------------------------------

    /**
     * Names must be unique across repeated calls — one test creates a batch,
     * then creates more afterwards to prove that "reset" picks up collections
     * added later.
     */
    private function categories(int $count): array
    {
        $owner = $this->userWithRole('super_admin');
        $seq   = static::$categorySeq;

        static::$categorySeq += $count;

        return collect(range($seq, $seq + $count - 1))
            ->map(fn ($i) => Category::create([
                'name'       => sprintf('Cat %02d', $i),
                'created_by' => $owner->id,
            ]))
            ->all();
    }

    private static int $categorySeq = 1;

    public function test_every_collection_is_shown_when_none_has_been_chosen(): void
    {
        $this->categories(3);

        // A fresh install must display everything without a Super Admin having
        // to opt in first.
        $this->getJson('/api/landing')->assertOk()->assertJsonCount(3, 'collections');
    }

    public function test_a_super_admin_can_choose_which_collections_are_shown(): void
    {
        [$a, $b, $c] = $this->categories(3);

        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->putJson('/api/landing/collections', ['category_ids' => [$a->id, $c->id]])
            ->assertOk();

        $names = collect($this->getJson('/api/landing')->assertOk()->json('collections'))
            ->pluck('name')
            ->all();

        $this->assertSame([$a->name, $c->name], $names);

        // The headline stat still counts the WHOLE repository — the grid is a
        // curated selection, not a claim about how many collections exist.
        $this->getJson('/api/landing')->assertJsonPath('stats.total_categories', 3);
    }

    public function test_choosing_none_is_distinct_from_choosing_nothing_yet(): void
    {
        $this->categories(3);
        $admin = $this->userWithRole('super_admin');

        // An empty array means "show none" — it must NOT be read as "unset", or
        // hiding every collection would silently show them all instead.
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/landing/collections', ['category_ids' => []])
            ->assertOk();

        $this->getJson('/api/landing')->assertOk()->assertJsonCount(0, 'collections');

        // Reset is the way back to "all", including collections added later.
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/landing/collections')->assertOk();

        $this->getJson('/api/landing')->assertOk()->assertJsonCount(3, 'collections');
    }

    public function test_resetting_shows_collections_created_afterwards(): void
    {
        [$a] = $this->categories(1);
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/landing/collections', ['category_ids' => [$a->id]])
            ->assertOk();

        $this->categories(2); // two new collections added later

        // Still pinned to the explicit selection...
        $this->getJson('/api/landing')->assertJsonCount(1, 'collections');

        // ...until reset, which is why "reset" exists rather than just saving
        // every ID: a saved list would leave future collections invisible.
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/landing/collections')->assertOk();
        $this->getJson('/api/landing')->assertJsonCount(3, 'collections');
    }

    public function test_non_super_admin_roles_cannot_choose_the_collections(): void
    {
        [$a] = $this->categories(1);

        $this->putJson('/api/landing/collections', ['category_ids' => [$a->id]])
            ->assertStatus(401);

        foreach (['student', 'staff'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->putJson('/api/landing/collections', ['category_ids' => [$a->id]])
                ->assertStatus(403, "role [{$role}] must not be able to choose the featured collections");
        }
    }

    public function test_a_collection_that_does_not_exist_is_rejected(): void
    {
        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->putJson('/api/landing/collections', ['category_ids' => [9999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_ids.0');
    }
}
