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

// The repository holds more than theses, and most special collections and
// institutional publications have no adviser. The field used to be required
// (and the column NOT NULL), so staff had to invent one to upload at all.
class OptionalAdviserTest extends TestCase
{
    use RefreshDatabase;
    use MakesPdfs;

    private User $staff;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // OCR shells out to Node/Tesseract — mock it so tests never do that.
        $this->mock(OcrService::class, function ($mock) {
            $mock->shouldReceive('extract')->andReturn(['abstract' => '', 'keywords' => '', 'method' => 'digital']);
            $mock->shouldReceive('cleanKeywords')->andReturn('');
        });

        Role::findOrCreate('staff');
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->staff->assignRole('staff');
        $this->category = Category::create(['name' => 'Special Collections', 'created_by' => $this->staff->id]);
    }

    private function upload(array $overrides = [])
    {
        return $this->actingAs($this->staff)->postJson('/api/theses', array_merge([
            'title'          => 'A Collection Item',
            'authors'        => 'Someone',
            'year_published' => 2026,
            'category_id'    => $this->category->id,
            'pdf_file'       => UploadedFile::fake()->createWithContent('t.pdf', $this->minimalPdf()),
        ], $overrides));
    }

    public function test_an_item_can_be_uploaded_without_an_adviser(): void
    {
        $this->upload()->assertCreated();

        $this->assertNull(Thesis::first()->adviser);
    }

    public function test_a_blank_adviser_is_accepted_and_stored_as_none(): void
    {
        $this->upload(['adviser' => ''])->assertCreated();

        $this->assertNull(Thesis::first()->adviser);
    }

    public function test_an_adviser_is_still_saved_when_given(): void
    {
        $this->upload(['adviser' => 'Engr. Roberto Aquino'])->assertCreated();

        $this->assertSame('Engr. Roberto Aquino', Thesis::first()->adviser);
    }

    public function test_an_existing_adviser_can_be_cleared_when_editing(): void
    {
        $this->upload(['adviser' => 'Wrong Person'])->assertCreated();
        $id = Thesis::first()->id;

        $this->actingAs($this->staff)
            ->putJson("/api/theses/{$id}", ['adviser' => ''])
            ->assertOk();

        $this->assertNull(Thesis::find($id)->adviser);
    }

    public function test_an_item_without_an_adviser_opens_normally(): void
    {
        $this->upload()->assertCreated();
        $id = Thesis::first()->id;

        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/theses/{$id}")
            ->assertOk()
            ->assertJsonPath('thesis.adviser', null);
    }
}
