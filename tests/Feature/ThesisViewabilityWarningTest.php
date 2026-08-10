<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPdfs;
use Tests\TestCase;

// A thesis is watermarked on every view, so a PDF the stamper can't parse is
// one nobody can read. The upload is deliberately still allowed — rejecting a
// file the staff member cannot fix helps no one — which means the only thing
// standing between that and a reader hitting "Could not load the PDF" weeks
// later is this warning. These tests exist so it can't quietly stop being
// returned.
class ThesisViewabilityWarningTest extends TestCase
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
        $this->category = Category::create(['name' => 'CS', 'created_by' => $this->staff->id]);
    }

    private function upload(string $pdfBytes, string $title = 'T')
    {
        return $this->actingAs($this->staff)->postJson('/api/theses', [
            'title'          => $title,
            'authors'        => 'A',
            'adviser'        => 'Adv',
            'year_published' => 2026,
            'category_id'    => $this->category->id,
            'pdf_file'       => UploadedFile::fake()->createWithContent('t.pdf', $pdfBytes),
        ]);
    }

    public function test_a_readable_pdf_uploads_with_no_warning(): void
    {
        $response = $this->upload($this->minimalPdf());

        $response->assertCreated();
        $this->assertNull($response->json('view_warning'), 'A perfectly good PDF must not alarm anyone.');
    }

    public function test_a_pdf_the_viewer_cannot_open_still_uploads_but_warns(): void
    {
        // Needs a real password, so qpdf cannot repair it however it's configured.
        $bytes = $this->pdfBuiltWith([
            '--encrypt', '--user-password=secret', '--owner-password=owner', '--bits=256', '--',
        ]);

        if ($bytes === null) {
            $this->markTestSkipped('qpdf is not installed on this machine.');
        }

        $response = $this->upload($bytes);

        // Still created: the file is kept, the staff member is told.
        $response->assertCreated();
        $this->assertDatabaseHas('theses', ['title' => 'T']);

        $warning = $response->json('view_warning');
        $this->assertNotNull($warning, 'An unviewable PDF must not upload silently.');
        $this->assertStringContainsString('password-protected', $warning);
    }

    public function test_the_warning_names_qpdf_when_qpdf_is_the_thing_thats_missing(): void
    {
        // The likeliest cause in practice: a teammate who never installed qpdf.
        // They can't act on "this file may be damaged" — the file is fine, the
        // machine isn't — so the two causes must read differently.
        $bytes = $this->pdfBuiltWith(['--object-streams=generate']);

        if ($bytes === null) {
            $this->markTestSkipped('qpdf is not installed on this machine.');
        }

        config(['thesis.qpdf_binary' => 'qpdf-not-installed-here']);

        $warning = $this->upload($bytes)->assertCreated()->json('view_warning');

        $this->assertNotNull($warning);
        $this->assertStringContainsString('qpdf', $warning);
        $this->assertStringNotContainsString('password-protected', $warning);
    }

    public function test_replacing_a_file_reports_viewability_too(): void
    {
        // A replace can introduce an unopenable file just as easily as an
        // upload, and it's the same one-line mistake to leave it unchecked.
        $created = $this->upload($this->minimalPdf())->assertCreated();
        $id      = $created->json('id');

        $bad = $this->pdfBuiltWith([
            '--encrypt', '--user-password=secret', '--owner-password=owner', '--bits=256', '--',
        ]);

        if ($bad === null) {
            $this->markTestSkipped('qpdf is not installed on this machine.');
        }

        $response = $this->actingAs($this->staff)->postJson("/api/theses/{$id}/file", [
            'pdf_file' => UploadedFile::fake()->createWithContent('new.pdf', $bad),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('view_warning'));
    }
}
