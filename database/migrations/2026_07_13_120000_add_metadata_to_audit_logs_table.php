<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured payload for an audit entry, so consumers stop parsing the
     * human-readable `description` sentence to get at the data inside it.
     *
     * The concrete problem: "Most Searched Keywords" recovered the search term
     * with preg_match('/searched for: (.+)/') against the description, and the
     * per-user "recent searches" list did the same. Both would silently return
     * garbage the moment anyone reworded that sentence — the report would keep
     * rendering, just with wrong keywords. The description is a display string;
     * it should never have been the data source.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('description');
        });

        $this->backfillSearchQueries();
    }

    /**
     * Existing search rows only have the sentence, so recover the term from it
     * once, here, rather than leaving the regex living in the read path forever.
     * Anything that doesn't match is left null — the readers fall back to the
     * old parse for those, so no history is lost either way.
     */
    private function backfillSearchQueries(): void
    {
        DB::table('audit_logs')
            ->where('action', 'search')
            ->whereNull('metadata')
            ->orderBy('id')
            ->chunkById(500, function ($logs) {
                foreach ($logs as $log) {
                    if (! preg_match('/searched for: (.+)$/s', (string) $log->description, $m)) {
                        continue;
                    }

                    DB::table('audit_logs')
                        ->where('id', $log->id)
                        ->update(['metadata' => json_encode(['query' => trim($m[1])])]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
