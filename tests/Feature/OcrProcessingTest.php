<?php

namespace Tests\Feature;

use App\Jobs\ProcessThesisOcr;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Thesis;
use App\Models\User;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reading an uploaded document, and the state the record carries while it
 * happens.
 *
 * Reading a scanned thesis is slow - a real 36-page upload measured 4m41s - so
 * it can finish long after the upload request has returned. These tests pin
 * down what the record says in the meantime, and that an upload is recorded
 * even when the reading never succeeds.
 */
class OcrProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('staff');
        Storage::fake('local');
    }

    private function staff(): User
    {
        $user = User::create([
            'id_number' => '90002',
            'email'     => 'staff@example.com',
            'password'  => Hash::make('Password123'),
            'role'      => 'staff',
            'status'    => 'active',
        ]);
        $user->assignRole('staff');

        return $user;
    }

    private function upload(User $staff): \Illuminate\Testing\TestResponse
    {
        $category = Category::create([
            'name' => 'Computer Studies', 'code' => 'CS', 'created_by' => $staff->id,
        ]);

        return $this->actingAs($staff)->postJson('/api/theses', [
            'title'          => 'A Scanned Thesis',
            'authors'        => 'Juan Dela Cruz',
            'adviser'        => 'Prof. Villanueva',
            'year'           => 2026,
            'category_id'    => $category->id,
            'pdf_file'       => UploadedFile::fake()->create('thesis.pdf', 120, 'application/pdf'),
        ]);
    }

    /** A thesis row built directly, for the cases that never go through upload. */
    private function thesis(array $overrides = []): Thesis
    {
        $staff = User::first() ?: $this->staff();

        return Thesis::withoutSyncingToSearch(fn () => Thesis::create(array_merge([
            'title'          => 'An Existing Thesis',
            'authors'        => 'Maria Santos',
            'adviser'        => 'Prof. Villanueva',
            'abstract'       => '',
            'year'           => 2025,
            'category_id'    => Category::create([
                'name' => 'Nursing '.uniqid(), 'code' => 'N'.random_int(100, 999), 'created_by' => $staff->id,
            ])->id,
            'file_path'      => 'theses/existing.pdf',
            'status'         => 'active',
            'uploaded_by'    => $staff->id,
        ], $overrides)));
    }

    /** A fake reader, so no test ever shells out to Node and spends minutes. */
    private function fakeOcr(array $returns = [], ?\Throwable $throws = null): void
    {
        $this->instance(OcrService::class, new class($returns, $throws) extends OcrService {
            public function __construct(private array $returns, private ?\Throwable $throws) {}

            public function extract(string $filePath): array
            {
                if ($this->throws) {
                    throw $this->throws;
                }

                return array_merge(
                    ['abstract' => '', 'keywords' => '', 'method' => 'text'],
                    $this->returns
                );
            }

            public function cleanKeywords(string $raw): string
            {
                return $raw;
            }
        });
    }

    public function test_uploading_queues_the_reading_instead_of_doing_it_in_the_request(): void
    {
        Queue::fake();

        $this->upload($this->staff())->assertStatus(201);

        Queue::assertPushed(ProcessThesisOcr::class);
    }

    /**
     * While the work is queued the record has to say so. Without this the
     * upload screen cannot tell "still being read" from "read, found nothing".
     */
    public function test_a_thesis_waiting_to_be_read_is_marked_pending(): void
    {
        Queue::fake();

        $this->upload($this->staff())->assertStatus(201)->assertJsonPath('ocr_status', 'pending');

        $this->assertSame('pending', Thesis::firstOrFail()->ocr_status);
    }

    /** On the sync queue the work is finished before the response is sent. */
    public function test_a_thesis_read_during_the_request_comes_back_done(): void
    {
        config(['queue.default' => 'sync']);
        $this->fakeOcr(['abstract' => 'Recovered abstract.', 'keywords' => 'water, sensor']);

        $this->upload($this->staff())->assertStatus(201)->assertJsonPath('ocr_status', 'done');

        $thesis = Thesis::firstOrFail();
        $this->assertSame('Recovered abstract.', $thesis->abstract);
        $this->assertNotNull($thesis->ocr_completed_at);
    }

    /**
     * A scan too poor to read is a finished job, not a failed one. Calling it
     * a failure would invite staff to re-run something that can never produce
     * more than it already did.
     */
    public function test_finding_nothing_readable_still_counts_as_done(): void
    {
        config(['queue.default' => 'sync']);
        $this->fakeOcr(['abstract' => '', 'keywords' => '', 'method' => 'ocr']);

        $this->upload($this->staff())->assertStatus(201);

        $thesis = Thesis::firstOrFail();
        $this->assertSame('done', $thesis->ocr_status);
        $this->assertSame('', $thesis->abstract);
    }

    public function test_a_failed_reading_is_marked_failed_so_the_screen_stops_waiting(): void
    {
        $thesis = $this->thesis(['ocr_status' => 'processing']);

        (new ProcessThesisOcr($thesis))->failed(new RuntimeException('OCR blew up'));

        $this->assertSame('failed', $thesis->fresh()->ocr_status);
        $this->assertNotNull($thesis->fresh()->ocr_completed_at);
    }

    /**
     * THE regression test for the ordering change.
     *
     * The audit entry used to be written AFTER the OCR ran inside the upload
     * request, so anything that killed the OCR - a crash, or a gateway timeout
     * on a long scan - left a thesis in the repository with no trace in the
     * audit trail. Observed for real on thesis #48 while its OCR was running.
     * An upload is a fact the moment the record exists.
     */
    public function test_an_upload_is_audited_even_when_the_reading_fails(): void
    {
        config(['queue.default' => 'sync']);
        $this->fakeOcr(throws: new RuntimeException('Node crashed'));

        $staff = $this->staff();

        try {
            $this->upload($staff);
        } catch (\Throwable) {
            // The sync queue rethrows into the request; the point of this test
            // is what survives in the database, not the response code.
        }

        $thesis = Thesis::firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'upload_thesis',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
        ]);
    }

    /**
     * Every thesis uploaded before this feature existed was read during its own
     * upload, so it is already finished. If the column did not default to
     * 'done', all of them would show "Reading the document..." forever.
     */
    public function test_a_thesis_created_without_a_status_counts_as_already_read(): void
    {
        $this->assertSame('done', $this->thesis()->fresh()->ocr_status);
    }
}
