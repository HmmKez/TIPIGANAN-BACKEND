<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\Thesis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A bookmark outlives its item's visibility: archiving keeps the bookmark but
 * hides the item from readers, and restoring brings it back. Before this, the
 * list sent the archived record in full to readers, and opening it deleted the
 * bookmark for good.
 */
class BookmarkVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private User $student;
    private Thesis $thesis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['role' => 'staff']);
        Role::findOrCreate('student');
        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');

        $category = Category::create(['name' => 'CS', 'created_by' => $this->staff->id]);
        $this->thesis = Thesis::create([
            'title'          => 'A Very Recognisable Title',
            'authors'        => 'Some Author',
            'adviser'        => 'Adviser',
            'abstract'       => 'An abstract.',
            'year'           => 2024,
            'category_id'    => $category->id,
            'pages'          => 10,
            'file_path'      => 'theses/a.pdf',
            'status'         => 'active',
            'uploaded_by'    => $this->staff->id,
        ]);
    }

    private function bookmarksOf(User $user)
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/favorites')->assertOk();
    }

    public function test_an_archived_bookmark_stays_but_shows_nothing_of_the_item(): void
    {
        Favorite::create(['user_id' => $this->student->id, 'thesis_id' => $this->thesis->id, 'created_at' => now()]);
        $this->thesis->update(['status' => 'archived']);

        $response = $this->bookmarksOf($this->student);

        $response->assertJsonCount(1)
            ->assertJsonPath('0.available', false)
            ->assertJsonPath('0.unavailable_reason', 'unavailable')
            ->assertJsonPath('0.thesis', null);
        $this->assertStringNotContainsString('Recognisable', $response->getContent());
        $this->assertSame(1, Favorite::count(), 'the bookmark row must be kept');
    }

    public function test_a_restored_item_comes_back_on_the_same_bookmark(): void
    {
        Favorite::create(['user_id' => $this->student->id, 'thesis_id' => $this->thesis->id, 'created_at' => now()]);
        $this->thesis->update(['status' => 'archived']);
        $this->bookmarksOf($this->student)->assertJsonPath('0.available', false);

        $this->thesis->update(['status' => 'active']);

        $this->bookmarksOf($this->student)
            ->assertJsonPath('0.available', true)
            ->assertJsonPath('0.thesis.title', 'A Very Recognisable Title');
    }

    public function test_a_deleted_item_is_reported_as_removed(): void
    {
        Favorite::create(['user_id' => $this->student->id, 'thesis_id' => $this->thesis->id, 'created_at' => now()]);
        $this->thesis->delete();

        $this->bookmarksOf($this->student)
            ->assertJsonPath('0.available', false)
            ->assertJsonPath('0.unavailable_reason', 'removed')
            ->assertJsonPath('0.thesis', null);
    }

    public function test_staff_still_see_their_archived_bookmark_in_full(): void
    {
        Favorite::create(['user_id' => $this->staff->id, 'thesis_id' => $this->thesis->id, 'created_at' => now()]);
        $this->thesis->update(['status' => 'archived']);

        $this->bookmarksOf($this->staff)
            ->assertJsonPath('0.available', true)
            ->assertJsonPath('0.thesis.title', 'A Very Recognisable Title');
    }

    public function test_a_reader_cannot_bookmark_an_item_they_cannot_see(): void
    {
        $this->thesis->update(['status' => 'archived']);

        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/favorites/{$this->thesis->id}")
            ->assertNotFound();
        $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/favorites/999999')
            ->assertNotFound();

        $this->assertSame(0, Favorite::count());
    }
}
