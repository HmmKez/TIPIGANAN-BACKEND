<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Thesis;
use App\Models\User;
use App\Support\ScanInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The folder the scanner drops finished PDFs into.
 *
 * TIPIGANAN never talks to the library's scanner - a ScanSnap SV600, which is
 * USB-only and reports a vendor-specific USB class, so no generic scanning
 * protocol can reach it. ScanSnap Home writes a PDF into a folder and the
 * system picks it up from there.
 *
 * The filename in an import request comes from the browser, so the path-safety
 * tests below are the important ones.
 */
class ScanInboxTest extends TestCase
{
    use RefreshDatabase;

    private string $inboxPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inboxPath = storage_path('framework/testing/scan-inbox-'.uniqid());
        mkdir($this->inboxPath, 0777, true);

        config([
            'scanner.inbox.enabled'        => true,
            'scanner.inbox.path'           => $this->inboxPath,
            // Nothing to wait for in a test: files are written instantly, and a
            // settle window would make every one of them invisible.
            'scanner.inbox.settle_seconds' => 0,
        ]);

        Role::findOrCreate('staff');
        Role::findOrCreate('student');
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->inboxPath.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->inboxPath);

        parent::tearDown();
    }

    /** A minimal but structurally real PDF, so page counting has something to read. */
    private function putScan(string $name, int $pages = 3): string
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
             ."2 0 obj\n<< /Type /Pages /Count {$pages} /Kids [] >>\nendobj\n"
             ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";

        file_put_contents($path = $this->inboxPath.DIRECTORY_SEPARATOR.$name, $pdf);

        return $path;
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

    /** Categories record who created them, so one has to be built from a real user. */
    private function category(string $name, string $code, User $creator): Category
    {
        return Category::create([
            'name'       => $name,
            'code'       => $code,
            'created_by' => $creator->id,
        ]);
    }

    private function student(): User
    {
        $user = User::create([
            'id_number' => '90003',
            'email'     => 'student@example.com',
            'password'  => Hash::make('Password123'),
            'role'      => 'student',
            'status'    => 'active',
        ]);
        $user->assignRole('student');

        return $user;
    }

    // ---------------------------------------------------------------- listing

    public function test_staff_see_what_the_scanner_has_left(): void
    {
        $this->putScan('thesis-scan-001.pdf', 42);

        $this->actingAs($this->staff())
            ->getJson('/api/scan-inbox')
            ->assertStatus(200)
            ->assertJson(['enabled' => true, 'available' => true])
            ->assertJsonPath('files.0.name', 'thesis-scan-001.pdf')
            ->assertJsonPath('files.0.pages', 42);
    }

    public function test_a_student_cannot_see_the_scan_inbox(): void
    {
        $this->putScan('private-scan.pdf');

        $this->actingAs($this->student())
            ->getJson('/api/scan-inbox')
            ->assertStatus(403);
    }

    public function test_only_pdfs_are_listed(): void
    {
        $this->putScan('real-scan.pdf');
        file_put_contents($this->inboxPath.'/notes.txt', 'not a scan');
        file_put_contents($this->inboxPath.'/photo.jpg', 'not a scan');

        $this->actingAs($this->staff())
            ->getJson('/api/scan-inbox')
            ->assertStatus(200)
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.name', 'real-scan.pdf');
    }

    /**
     * A scanner writing a large PDF leaves a file that exists but is not
     * finished. Attaching one mid-write produces a corrupt thesis, so anything
     * touched too recently must stay hidden.
     */
    public function test_a_file_still_being_written_is_not_offered(): void
    {
        config(['scanner.inbox.settle_seconds' => 30]);

        $this->putScan('still-writing.pdf');   // modified just now

        $this->actingAs($this->staff())
            ->getJson('/api/scan-inbox')
            ->assertStatus(200)
            ->assertJsonCount(0, 'files');
    }

    /**
     * An unreachable folder and an empty one look identical to a user but are
     * completely different problems - the first usually means the NAS share is
     * not mounted, and reporting it as "no scans" sends staff to investigate
     * the scanner instead of the storage.
     */
    public function test_an_unreachable_folder_is_reported_as_such_not_as_empty(): void
    {
        config(['scanner.inbox.path' => $this->inboxPath.'-does-not-exist']);

        $this->actingAs($this->staff())
            ->getJson('/api/scan-inbox')
            ->assertStatus(200)
            ->assertJson(['enabled' => true, 'available' => false])
            ->assertJsonPath('files', []);
    }

    // ------------------------------------------------------------ path safety

    /**
     * THE security test: the filename comes from the browser, so it must be
     * impossible to name a file outside the inbox and have the system copy it
     * into the repository.
     *
     * The target file is REAL and really does sit outside the inbox, which is
     * the whole point. An earlier version of this test used paths like
     * ../../etc/passwd that do not exist here, so realpath() returned false and
     * the "no longer in the inbox" branch threw first - the test passed against
     * a deliberately unsafe implementation, proving nothing. A traversal test
     * only tests anything if the file it reaches for actually exists.
     */
    public function test_a_filename_cannot_escape_the_inbox(): void
    {
        $inbox = app(ScanInbox::class);

        // A real, readable PDF in a sibling folder - exactly what an attacker
        // would be reaching for, and reachable by a plain ../ from the inbox.
        $outsideDir = dirname($this->inboxPath).DIRECTORY_SEPARATOR.'outside-'.uniqid();
        mkdir($outsideDir, 0777, true);
        $outsideFile = $outsideDir.DIRECTORY_SEPARATOR.'confidential.pdf';
        file_put_contents($outsideFile, "%PDF-1.4\n% not yours\n%%EOF\n");

        $escapes = [
            '../'.basename($outsideDir).'/confidential.pdf',
            '..\\'.basename($outsideDir).'\\confidential.pdf',
            $outsideFile,                                        // absolute path
            './../'.basename($outsideDir).'/confidential.pdf',
        ];

        try {
            // Collected and asserted afterwards rather than calling fail()
            // inside the loop: PHPUnit's fail() throws a class that extends
            // RuntimeException, so a catch here would swallow the very failure
            // it is meant to report and the test would pass regardless.
            $accepted = [];

            foreach ($escapes as $attempt) {
                try {
                    $accepted[$attempt] = $inbox->resolve($attempt);
                } catch (\RuntimeException) {
                    // Refused, which is the required behaviour.
                }
            }

            $this->assertSame([], $accepted, 'resolve() accepted a path outside the inbox: '.json_encode($accepted));

            // The file outside must still be sitting there untouched: discard()
            // goes through resolve(), so it must not be able to delete it either.
            $inbox->discard('../'.basename($outsideDir).'/confidential.pdf');
            $this->assertFileExists($outsideFile, 'A file outside the inbox was deleted.');
        } finally {
            @unlink($outsideFile);
            @rmdir($outsideDir);
        }
    }

    public function test_a_non_pdf_cannot_be_imported_by_name(): void
    {
        file_put_contents($this->inboxPath.'/config.env', 'SECRET=value');

        $this->expectException(\RuntimeException::class);

        app(ScanInbox::class)->resolve('config.env');
    }

    // ------------------------------------------------------------- discarding

    public function test_staff_can_discard_a_scan(): void
    {
        $this->putScan('misfeed.pdf');

        $this->actingAs($this->staff())
            ->deleteJson('/api/scan-inbox/misfeed.pdf')
            ->assertStatus(200);

        $this->assertFileDoesNotExist($this->inboxPath.'/misfeed.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'discard_scan']);
    }

    public function test_discarding_something_that_is_not_there_is_a_404_not_a_crash(): void
    {
        $this->actingAs($this->staff())
            ->deleteJson('/api/scan-inbox/never-existed.pdf')
            ->assertStatus(404);
    }

    // --------------------------------------------------------------- importing

    public function test_a_scan_can_be_turned_into_a_thesis_and_leaves_the_inbox(): void
    {
        Storage::fake('local');
        $this->putScan('ready-to-file.pdf');

        $staff    = $this->staff();
        $category = $this->category('Computer Studies', 'CS', $staff);

        $this->actingAs($staff)
            ->postJson('/api/theses', [
                'title'          => 'A Thesis That Was Scanned',
                'authors'        => 'Juan Dela Cruz',
                'adviser'        => 'Prof. Ramon Villanueva',
                'year_published' => 2026,
                'category_id'    => $category->id,
                'scan_file'      => 'ready-to-file.pdf',
            ])
            ->assertStatus(201);

        $thesis = Thesis::where('title', 'A Thesis That Was Scanned')->firstOrFail();

        $this->assertNotEmpty($thesis->file_path, 'The thesis should have a stored PDF.');
        Storage::disk('local')->assertExists($thesis->file_path);

        // Consumed, so the same scan cannot be filed twice by accident.
        $this->assertFileDoesNotExist(
            $this->inboxPath.'/ready-to-file.pdf',
            'The scan should be removed from the inbox once it has been filed.'
        );
    }

    public function test_naming_a_scan_that_is_not_there_is_refused_cleanly(): void
    {
        $staff    = $this->staff();
        $category = $this->category('Nursing', 'NUR', $staff);

        $this->actingAs($staff)
            ->postJson('/api/theses', [
                'title'          => 'Ghost Scan',
                'authors'        => 'Nobody',
                'adviser'        => 'Nobody',
                'year_published' => 2026,
                'category_id'    => $category->id,
                'scan_file'      => 'was-never-scanned.pdf',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scan_file');

        $this->assertDatabaseCount('theses', 0);
    }

    /**
     * The two ways of providing a file are alternatives, and neither being
     * present has to fail as a validation error rather than a null-reference
     * crash deeper in the controller.
     */
    public function test_an_upload_with_neither_a_file_nor_a_scan_is_refused(): void
    {
        $staff    = $this->staff();
        $category = $this->category('Education', 'EDUC', $staff);

        $this->actingAs($staff)
            ->postJson('/api/theses', [
                'title'          => 'No File At All',
                'authors'        => 'Nobody',
                'adviser'        => 'Nobody',
                'year_published' => 2026,
                'category_id'    => $category->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pdf_file');
    }

    // ------------------------------------------------------------ the count

    /**
     * The screen used to count the rows it was sent, so with 150 scans waiting
     * and a cap of 100 it announced "100 scanned files waiting". The real total
     * is now reported separately from the listed files.
     */
    public function test_the_total_counts_everything_waiting_not_just_what_is_listed(): void
    {
        config(['scanner.inbox.max_listed' => 2]);

        foreach (range(1, 5) as $n) {
            touch($this->putScan("scan-{$n}.pdf"), time() - (100 - $n));
        }

        $this->actingAs($this->staff())
            ->getJson('/api/scan-inbox')
            ->assertStatus(200)
            ->assertJsonPath('total', 5)
            ->assertJsonCount(2, 'files')
            // Newest first, so the scan just made is at the top of the list.
            ->assertJsonPath('files.0.name', 'scan-5.pdf')
            ->assertJsonPath('files.1.name', 'scan-4.pdf');
    }

    /**
     * Upper-case extensions are common from scanners. The listing used a
     * '*.pdf' glob, and PHP's glob is case-sensitive on Windows as well as
     * Linux, so SCAN001.PDF was invisible everywhere, silently. Confirmed to
     * fail against that glob.
     */
    public function test_an_upper_case_pdf_extension_is_still_listed(): void
    {
        $this->putScan('SCAN001.PDF');

        $this->actingAs($this->staff())
            ->getJson('/api/scan-inbox')
            ->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('files.0.name', 'SCAN001.PDF');
    }

    // ------------------------------------------------------------ previewing

    public function test_staff_can_open_a_waiting_scan_to_see_which_one_it_is(): void
    {
        $path = $this->putScan('which-one-is-this.pdf');

        $response = $this->actingAs($this->staff())
            ->get('/api/scan-inbox/which-one-is-this.pdf/preview')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');

        // Opened in the browser, not downloaded: staff are looking, not copying.
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame(file_get_contents($path), $response->streamedContent());
    }

    public function test_a_student_cannot_open_a_waiting_scan(): void
    {
        $this->putScan('private-scan.pdf');

        $this->actingAs($this->student())
            ->getJson('/api/scan-inbox/private-scan.pdf/preview')
            ->assertStatus(403);
    }

    public function test_previewing_a_scan_that_is_not_there_is_a_clean_404(): void
    {
        $this->actingAs($this->staff())
            ->getJson('/api/scan-inbox/never-existed.pdf/preview')
            ->assertStatus(404);
    }

    /**
     * The name comes from the browser. Previewing serves file CONTENTS, which
     * makes an escape from the folder worse here than anywhere else in the
     * inbox - so this uses a real PDF really sitting outside it. A traversal
     * test aimed at a file that does not exist passes against unsafe code and
     * proves nothing; that has already happened once in this project.
     */
    public function test_preview_cannot_be_used_to_read_a_file_outside_the_inbox(): void
    {
        $outsideDir = dirname($this->inboxPath).DIRECTORY_SEPARATOR.'outside-'.uniqid();
        mkdir($outsideDir, 0777, true);
        file_put_contents($outsideDir.DIRECTORY_SEPARATOR.'confidential.pdf', "%PDF-1.4\n% not yours\n%%EOF\n");

        try {
            $staff = $this->staff();

            foreach (['..%2F'.basename($outsideDir).'%2Fconfidential.pdf',
                      '..%5C'.basename($outsideDir).'%5Cconfidential.pdf'] as $attempt) {
                $response = $this->actingAs($staff)->get("/api/scan-inbox/{$attempt}/preview");

                $this->assertNotSame(200, $response->status(), "Preview served a file outside the inbox via '{$attempt}'.");
                $this->assertStringNotContainsString('not yours', (string) $response->getContent());
            }

            // The route's own pattern already rejects a slash, so the requests
            // above may never reach preview() at all - and would keep passing
            // even if preview() stopped checking names. Calling it directly is
            // what proves preview() itself refuses, independent of routing.
            $controller = app(\App\Http\Controllers\Api\ScanInboxController::class);

            foreach (['../'.basename($outsideDir).'/confidential.pdf',
                      '..\\'.basename($outsideDir).'\\confidential.pdf'] as $attempt) {
                $direct = $controller->preview($attempt);

                $this->assertSame(404, $direct->getStatusCode(), "preview() itself served '{$attempt}'.");
            }
        } finally {
            @unlink($outsideDir.DIRECTORY_SEPARATOR.'confidential.pdf');
            @rmdir($outsideDir);
        }
    }
}
