<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Citation;
use App\Models\Thesis;
use App\Models\User;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Year published" became just "year" (much of the collection was never
 * published) and is optional: left blank at upload, it is read from the
 * document like the abstract and keywords. An item whose document states no
 * year has none, and its citations say "n.d.".
 */
class ItemYearTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['queue.default' => 'sync']);   // read during the upload, so the result is checkable

        Role::findOrCreate('staff');
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->staff->assignRole('staff');
        $this->category = Category::create(['name' => 'Special Collections', 'created_by' => $this->staff->id]);
    }

    /** A fake reader that "finds" the given year, so no test shells out to Node. */
    private function readerFinds(?int $year): void
    {
        $this->instance(OcrService::class, new class($year) extends OcrService {
            public function __construct(private ?int $year) {}

            public function extract(string $filePath): array
            {
                return ['title' => '', 'year' => $this->year, 'abstract' => '', 'keywords' => '',
                        'introduction' => '', 'conclusion' => '', 'method' => 'digital'];
            }
        });
    }

    private function upload(array $overrides = [])
    {
        return $this->actingAs($this->staff)->postJson('/api/theses', array_merge([
            'title'       => 'A Collection Item',
            'authors'     => 'Someone',
            'category_id' => $this->category->id,
            'pdf_file'    => UploadedFile::fake()->create('item.pdf', 50, 'application/pdf'),
        ], $overrides));
    }

    private function item(array $overrides = []): Thesis
    {
        return Thesis::withoutSyncingToSearch(fn () => Thesis::create(array_merge([
            'title' => 'Item', 'authors' => 'Someone', 'abstract' => '', 'category_id' => $this->category->id,
            'file_path' => 'theses/x.pdf', 'status' => 'active', 'uploaded_by' => $this->staff->id,
        ], $overrides)));
    }

    // --- Upload and reading -------------------------------------------------

    public function test_a_blank_year_is_filled_in_from_the_document(): void
    {
        $this->readerFinds(2023);

        $this->upload()->assertCreated();

        $this->assertSame(2023, (int) Thesis::firstOrFail()->year);
    }

    public function test_a_year_typed_by_staff_is_never_overwritten_by_the_reader(): void
    {
        $this->readerFinds(2023);

        $this->upload(['year' => 2019])->assertCreated();

        $this->assertSame(2019, (int) Thesis::firstOrFail()->year);
    }

    public function test_an_item_whose_document_states_no_year_has_none(): void
    {
        $this->readerFinds(null);

        $this->upload(['year' => ''])->assertCreated();

        $this->assertNull(Thesis::firstOrFail()->year);
    }

    public function test_a_year_must_still_be_four_digits(): void
    {
        $this->readerFinds(null);

        $this->upload(['year' => 23])->assertStatus(422)->assertJsonValidationErrors('year');
    }

    public function test_a_year_can_be_cleared_when_editing(): void
    {
        $item = $this->item(['year' => 2020]);

        $this->actingAs($this->staff)->putJson("/api/theses/{$item->id}", ['year' => ''])->assertOk();

        $this->assertNull($item->fresh()->year);
    }

    public function test_the_reader_rejects_anything_that_is_not_a_plausible_year(): void
    {
        $ocr = new OcrService();

        $this->assertSame(2024, $ocr->cleanYear('2024'));
        $this->assertNull($ocr->cleanYear(''));
        $this->assertNull($ocr->cleanYear('1492'));
        $this->assertNull($ocr->cleanYear((string) ((int) date('Y') + 5)));
        $this->assertNull($ocr->cleanYear('20x4'));
    }

    // --- Citations ----------------------------------------------------------

    public function test_a_citation_without_a_year_says_n_d(): void
    {
        $item = $this->item(['year' => null]);

        $response = $this->getJson("/api/theses/{$item->id}/citations/generate")->assertOk();

        $this->assertStringContainsString('(n.d.)', $response->json('APA'));
        $this->assertStringContainsString('n.d.', $response->json('MLA'));
        $this->assertStringEndsWith('Mater Dei College, n.d.', $response->json('MLA'), 'no doubled full stop after n.d.');
    }

    public function test_a_default_citation_follows_the_year_once_it_is_known(): void
    {
        $item = $this->item(['year' => null]);
        $this->getJson("/api/theses/{$item->id}/citations/generate")->assertOk();   // stored with "n.d."

        Thesis::withoutSyncingToSearch(fn () => $item->update(['year' => 2023]));

        $this->assertStringContainsString('(2023)',
            $this->getJson("/api/theses/{$item->id}/citations/generate")->json('APA'));
    }

    public function test_a_citation_staff_customized_is_left_alone(): void
    {
        $item = $this->item(['year' => null]);
        $this->getJson("/api/theses/{$item->id}/citations/generate")->assertOk();
        $apa = Citation::where('thesis_id', $item->id)->where('format_type', 'APA')->firstOrFail();
        $this->actingAs($this->staff)
            ->putJson("/api/theses/{$item->id}/citations/{$apa->id}", ['citation_text' => 'Custom text by staff.'])
            ->assertOk();

        Thesis::withoutSyncingToSearch(fn () => $item->update(['year' => 2023]));

        $this->assertSame('Custom text by staff.',
            $this->getJson("/api/theses/{$item->id}/citations/generate")->json('APA'));
    }

    // --- Browsing, search and reports ---------------------------------------

    public function test_browse_and_search_filter_by_year(): void
    {
        $this->item(['title' => 'Old Item', 'year' => 2010]);
        $this->item(['title' => 'New Item', 'year' => 2024]);

        $this->assertSame(['New Item'],
            collect($this->getJson('/api/theses?year=2024')->assertOk()->json('data'))->pluck('title')->all());
        $this->assertSame(['New Item'],
            collect($this->getJson('/api/search?q=Item&year=2024')->assertOk()->json('data'))->pluck('title')->all());
    }

    public function test_the_by_year_report_counts_items_with_no_year_too(): void
    {
        $this->item(['year' => 2024]);
        $this->item(['year' => null]);

        Role::findOrCreate('super_admin');
        $admin = User::factory()->create(['role' => 'super_admin']);
        $admin->assignRole('super_admin');

        $rows = collect($this->actingAs($admin)->getJson('/api/reports/by-year')->assertOk()->json());

        $this->assertSame(1, (int) $rows->firstWhere('year', 2024)['total']);
        $this->assertSame(1, (int) $rows->first(fn ($r) => $r['year'] === null)['total']);
    }
}
