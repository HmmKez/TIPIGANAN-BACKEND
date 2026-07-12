<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Thesis;
use App\Models\ThesisFileVersion;
use App\Models\User;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ThesisFileVersionTest extends TestCase
{
    use RefreshDatabase;

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

    private function seedThesis(): array
    {
        // %PDF header → detected as application/pdf (passes mimes:pdf); the
        // random tail makes each file's content (and hash) distinct.
        $file = UploadedFile::fake()->createWithContent('old.pdf', "%PDF-1.4\nOLD " . Str::random(24));
        $path = $file->store('theses', 'local');
        $checksum = hash_file('sha256', Storage::disk('local')->path($path));

        $thesis = Thesis::create([
            'title'          => 'X',
            'authors'        => 'A',
            'adviser'        => 'Adv',
            'abstract'       => 'kept abstract',
            'keywords'       => 'kept, keywords',
            'year_published' => 2024,
            'category_id'    => $this->category->id,
            'pages'          => 5,
            'file_path'      => $path,
            'checksum'       => $checksum,
            'status'         => 'active',
            'uploaded_by'    => $this->staff->id,
        ]);

        return [$thesis, $path, $checksum];
    }

    public function test_replacing_a_file_archives_a_version_and_updates_the_checksum(): void
    {
        [$thesis, $oldPath, $oldChecksum] = $this->seedThesis();

        $newFile = UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4
NEW " . Str::random(24));

        $this->actingAs($this->staff, 'sanctum')
            ->post("/api/theses/{$thesis->id}/file", ['pdf_file' => $newFile])
            ->assertOk();

        $thesis->refresh();

        // The old file was archived as a restorable pending version.
        $this->assertDatabaseHas('thesis_file_versions', [
            'thesis_id' => $thesis->id,
            'file_path' => $oldPath,
            'status'    => 'pending',
        ]);

        // Active file changed, and its checksum matches the NEW file's hash.
        $this->assertNotEquals($oldPath, $thesis->file_path);
        $this->assertNotEquals($oldChecksum, $thesis->checksum);
        $this->assertEquals(
            hash_file('sha256', Storage::disk('local')->path($thesis->file_path)),
            $thesis->checksum
        );

        // Non-blank metadata is never overwritten by a replace.
        $this->assertEquals('kept abstract', $thesis->abstract);
    }

    public function test_restoring_a_version_swaps_the_file_and_checksum_back(): void
    {
        [$thesis, $oldPath, $oldChecksum] = $this->seedThesis();

        $newFile = UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4
NEW " . Str::random(24));
        $this->actingAs($this->staff, 'sanctum')
            ->post("/api/theses/{$thesis->id}/file", ['pdf_file' => $newFile])
            ->assertOk();

        $version = ThesisFileVersion::where('thesis_id', $thesis->id)
            ->where('status', 'pending')->firstOrFail();

        $this->actingAs($this->staff, 'sanctum')
            ->post("/api/theses/{$thesis->id}/file-versions/{$version->id}/restore")
            ->assertOk();

        $thesis->refresh();
        $this->assertEquals($oldPath, $thesis->file_path);
        $this->assertEquals($oldChecksum, $thesis->checksum);
        $this->assertEquals('restored', $version->fresh()->status);
    }
}
