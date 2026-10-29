<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Thesis;
use App\Models\User;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPdfs;
use Tests\TestCase;

/**
 * The same item must not be filed twice.
 *
 * The same FILE is refused outright - identical bytes, certain. The same WORK
 * as a different file (a rescan) is a matching title plus a shared author, and
 * only warns: staff may confirm and upload anyway, because unrelated items can
 * share a title.
 */
class DuplicateUploadTest extends TestCase
{
    use RefreshDatabase;
    use MakesPdfs;

    private User $staff;
    private Category $category;
    private string $inboxPath;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Never shell out to Node in a test.
        $this->mock(OcrService::class, function ($mock) {
            $mock->shouldReceive('extract')->andReturn(['abstract' => '', 'keywords' => '', 'year' => null, 'method' => 'digital']);
            $mock->shouldReceive('cleanKeywords')->andReturn('');
        });

        Role::findOrCreate('staff');
        Role::findOrCreate('student');   // here, not mid-test: the permission cache would not see it
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->staff->assignRole('staff');
        $this->category = Category::create(['name' => 'Special Collections', 'created_by' => $this->staff->id]);

        $this->inboxPath = storage_path('framework/testing/dup-inbox-'.uniqid());
        mkdir($this->inboxPath, 0777, true);
        config(['scanner.inbox.enabled' => true, 'scanner.inbox.path' => $this->inboxPath, 'scanner.inbox.settle_seconds' => 0]);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->inboxPath.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->inboxPath);
        parent::tearDown();
    }

    /** A PDF; a different $variant gives different bytes. */
    private function pdf(int $variant = 1): UploadedFile
    {
        return UploadedFile::fake()->createWithContent("item-{$variant}.pdf", $this->minimalPdf($variant));
    }

    private function upload(array $fields = [], ?UploadedFile $file = null)
    {
        return $this->actingAs($this->staff)->postJson('/api/theses', array_merge([
            'title'       => 'Tabuluxe: An On-Demand Event Tabulation System',
            'authors'     => 'Golosinda, Aldjohn B.; Lenteria, Angela Cecilia G.',
            'category_id' => $this->category->id,
            'pdf_file'    => $file ?? $this->pdf(),
        ], $fields));
    }

    // --- 1. The same file ---------------------------------------------------

    public function test_the_same_file_cannot_be_uploaded_twice(): void
    {
        $this->upload()->assertCreated();
        $filesBefore = count(Storage::disk('local')->allFiles('theses'));

        $this->upload(['title' => 'A Completely Different Title', 'authors' => 'Nobody Else'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'same_file')
            ->assertJsonPath('duplicate_of.title', 'Tabuluxe: An On-Demand Event Tabulation System');

        $this->assertSame(1, Thesis::count());
        $this->assertSame($filesBefore, count(Storage::disk('local')->allFiles('theses')), 'a refused upload must leave no file behind');
    }

    public function test_confirming_does_not_override_an_identical_file(): void
    {
        $this->upload()->assertCreated();

        $this->upload(['confirm_duplicate' => 1])->assertStatus(409)->assertJsonPath('reason', 'same_file');
    }

    public function test_a_different_file_with_a_different_title_uploads_normally(): void
    {
        $this->upload()->assertCreated();

        $this->upload(['title' => 'Another Study', 'authors' => 'Someone Else'], $this->pdf(2))->assertCreated();

        $this->assertSame(2, Thesis::count());
    }

    public function test_a_deleted_items_file_may_be_uploaded_again(): void
    {
        $this->upload()->assertCreated();
        Thesis::withoutSyncingToSearch(fn () => Thesis::first()->delete());

        $this->upload()->assertCreated();
    }

    public function test_an_archived_match_says_to_restore_it_instead(): void
    {
        $this->upload()->assertCreated();
        Thesis::withoutSyncingToSearch(fn () => Thesis::first()->update(['status' => 'archived']));

        $message = $this->upload(['title' => 'Different', 'authors' => 'Different'])->assertStatus(409)->json('message');

        $this->assertStringContainsString('restore it', $message);
    }

    public function test_an_existing_items_stored_file_uploaded_again_is_recognised(): void
    {
        // Items from before this check have no original fingerprint, only the
        // stored file's. That file, downloaded and re-uploaded, still matches.
        $this->upload()->assertCreated();
        $stored = Storage::disk('local')->get(Thesis::first()->file_path);
        Thesis::withoutSyncingToSearch(fn () => Thesis::first()->update(['original_checksum' => null]));

        $this->upload(['title' => 'X', 'authors' => 'Y'], UploadedFile::fake()->createWithContent('copy.pdf', $stored))
            ->assertStatus(409)->assertJsonPath('reason', 'same_file');
    }

    public function test_a_scan_matching_an_existing_item_is_refused_and_stays_in_the_inbox(): void
    {
        $this->upload()->assertCreated();
        file_put_contents($this->inboxPath.'/Scan_001.pdf', $this->minimalPdf(1));

        $this->actingAs($this->staff)->postJson('/api/theses', [
            'title' => 'Other', 'authors' => 'Other', 'category_id' => $this->category->id, 'scan_file' => 'Scan_001.pdf',
        ])->assertStatus(409)->assertJsonPath('reason', 'same_file');

        $this->assertFileExists($this->inboxPath.'/Scan_001.pdf', 'a refused scan must not be removed from the inbox');
        $this->assertSame(1, Thesis::count());
    }

    public function test_replacing_with_the_items_own_current_file_is_refused(): void
    {
        $this->upload()->assertCreated();
        $id = Thesis::first()->id;

        $this->actingAs($this->staff)->postJson("/api/theses/{$id}/file", ['pdf_file' => $this->pdf()])
            ->assertStatus(409)->assertJsonPath('reason', 'same_file');
    }

    public function test_replacing_with_another_items_file_is_refused(): void
    {
        $this->upload()->assertCreated();
        $this->upload(['title' => 'Second', 'authors' => 'Other'], $this->pdf(2))->assertCreated();
        $second = Thesis::where('title', 'Second')->first();

        $this->actingAs($this->staff)->postJson("/api/theses/{$second->id}/file", ['pdf_file' => $this->pdf(1)])
            ->assertStatus(409)
            ->assertJsonPath('duplicate_of.title', 'Tabuluxe: An On-Demand Event Tabulation System');
    }

    public function test_replacing_with_a_new_file_records_its_fingerprint(): void
    {
        $this->upload()->assertCreated();
        $item = Thesis::first();

        $this->actingAs($this->staff)->postJson("/api/theses/{$item->id}/file", ['pdf_file' => $this->pdf(3)])->assertOk();

        $this->assertSame(hash('sha256', $this->minimalPdf(3)), $item->fresh()->original_checksum);
    }

    // --- 2. The same work as another file -----------------------------------

    public function test_same_title_and_a_shared_author_is_flagged_as_a_possible_duplicate(): void
    {
        $this->upload()->assertCreated();

        // A rescan: different file, title differently cased and punctuated.
        $this->upload(['title' => 'TABULUXE - an on-demand event tabulation system', 'authors' => 'Lenteria, A.'], $this->pdf(2))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'possible_duplicate')
            ->assertJsonPath('matches.0.title', 'Tabuluxe: An On-Demand Event Tabulation System');

        $this->assertSame(1, Thesis::count());
    }

    public function test_the_main_title_alone_also_matches(): void
    {
        $this->upload()->assertCreated();

        $this->upload(['title' => 'Tabuluxe', 'authors' => 'Golosinda et al.'], $this->pdf(2))
            ->assertStatus(409)->assertJsonPath('reason', 'possible_duplicate');
    }

    public function test_staff_can_confirm_and_upload_a_possible_duplicate_anyway(): void
    {
        $this->upload()->assertCreated();

        $this->upload(['authors' => 'Golosinda, A.', 'confirm_duplicate' => 1], $this->pdf(2))->assertCreated();

        $this->assertSame(2, Thesis::count());
    }

    public function test_the_same_title_by_different_authors_is_not_flagged(): void
    {
        $this->upload()->assertCreated();

        $this->upload(['authors' => 'Reyes, Maria; Santos, Juan'], $this->pdf(2))->assertCreated();
    }

    public function test_the_live_check_lists_matches_for_staff_only(): void
    {
        $this->upload()->assertCreated();

        $this->actingAs($this->staff)
            ->getJson('/api/duplicate-check?'.http_build_query(['title' => 'tabuluxe: an on-demand event tabulation system', 'authors' => 'Lenteria']))
            ->assertOk()
            ->assertJsonCount(1, 'matches');
        $this->actingAs($this->staff)
            ->getJson('/api/duplicate-check?'.http_build_query(['title' => 'Something Unrelated', 'authors' => 'Lenteria']))
            ->assertOk()
            ->assertJsonCount(0, 'matches');

        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $this->actingAs($student)->getJson('/api/duplicate-check?title=Tabuluxe')->assertForbidden();
    }
}
