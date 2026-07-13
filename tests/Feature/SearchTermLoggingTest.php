<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SearchTermLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('staff');
        Permission::findOrCreate('export_reports');
    }

    private function reporter(): User
    {
        $user = User::factory()->create(['role' => 'staff']);
        $user->assignRole('staff');
        $user->givePermissionTo('export_reports');

        return $user;
    }

    public function test_a_search_stores_the_term_as_structured_data(): void
    {
        $this->getJson('/api/search?q=nursing')->assertOk();

        $log = AuditLog::where('action', 'search')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('nursing', $log->metadata['query'] ?? null);
        $this->assertSame('nursing', $log->searchQuery());
    }

    /**
     * The point of the whole change. "Most Searched Keywords" used to recover
     * the term with preg_match('/searched for: (.+)/') against the log's
     * human-readable description. Reword that sentence — for any reason — and
     * the report keeps rendering, silently, with wrong keywords. The term is now
     * read from `metadata`, so the display sentence is free to change.
     */
    public function test_rewording_the_log_sentence_does_not_corrupt_the_search_term(): void
    {
        $this->getJson('/api/search?q=robotics')->assertOk();

        $log = AuditLog::where('action', 'search')->latest('id')->first();

        // Simulate a future reword of the display text.
        $log->update(['description' => 'Guest ran a query: robotics']);

        $this->assertSame('robotics', $log->fresh()->searchQuery());
    }

    public function test_a_legacy_row_without_metadata_still_resolves_via_the_fallback(): void
    {
        // Rows written before the metadata column existed, and anything the
        // backfill migration could not parse, must still work.
        $log = AuditLog::create([
            'user_id'     => null,
            'action'      => 'search',
            'description' => 'Guest searched for: heritage',
            'metadata'    => null,
            'ip_address'  => '127.0.0.1',
            'created_at'  => now(),
        ]);

        $this->assertSame('heritage', $log->searchQuery());
    }

    public function test_most_searched_counts_the_structured_terms(): void
    {
        $this->getJson('/api/search?q=nursing')->assertOk();
        $this->getJson('/api/search?q=nursing')->assertOk();
        $this->getJson('/api/search?q=robotics')->assertOk();

        $counts = $this->mostSearched();

        // The keyword must be the term itself — not the whole log sentence,
        // which is what the old regex fell back to whenever it failed to match.
        $this->assertSame(2, $counts['nursing'] ?? null);
        $this->assertSame(1, $counts['robotics'] ?? null);
    }

    /**
     * The test that actually matters, and the one whose absence let a bug
     * through: asserting the model accessor is reword-proof says nothing about
     * the REPORT, which had its own private copy of the regex. Reword every
     * description, then hit the endpoint.
     */
    public function test_the_most_searched_report_survives_a_reworded_log_sentence(): void
    {
        $this->getJson('/api/search?q=nursing')->assertOk();
        $this->getJson('/api/search?q=nursing')->assertOk();
        $this->getJson('/api/search?q=robotics')->assertOk();

        // Whatever the display sentence becomes, the report reads the metadata.
        AuditLog::where('action', 'search')->get()->each(
            fn (AuditLog $log) => $log->update(['description' => 'somebody looked something up'])
        );

        $counts = $this->mostSearched();

        $this->assertSame(2, $counts['nursing'] ?? null);
        $this->assertSame(1, $counts['robotics'] ?? null);

        // And the sentence itself must never be counted as a keyword.
        $this->assertArrayNotHasKey('somebody looked something up', $counts);
    }

    private function mostSearched(): array
    {
        $rows = $this->actingAs($this->reporter(), 'sanctum')
            ->getJson('/api/reports/most-searched')
            ->assertOk()
            ->json();

        return collect($rows)->pluck('count', 'keyword')->all();
    }
}
