<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Year published" described theses, but much of the collection - special
// collections, institutional records, local creations - was never published.
// The field is now just the item's year.
//
// It is also optional now: like the abstract and keywords, a year left blank
// at upload is filled in by reading the document (ProcessThesisOcr), and an
// item whose document states no year simply has none (citations say "n.d.").
return new class extends Migration
{
    public function up(): void
    {
        // The index is dropped and re-made rather than renamed, so it ends up
        // named after the new column on every database driver.
        Schema::table('theses', function (Blueprint $table) {
            $table->dropIndex(['year_published']);
        });

        Schema::table('theses', function (Blueprint $table) {
            $table->renameColumn('year_published', 'year');
        });

        Schema::table('theses', function (Blueprint $table) {
            $table->year('year')->nullable()->change();
            $table->index('year');
        });
    }

    public function down(): void
    {
        // NOT NULL again needs a value in every row. The upload year is the
        // closest thing to a real year for an item whose document had none.
        DB::table('theses')->whereNull('year')->orderBy('id')->get(['id', 'created_at'])
            ->each(fn ($row) => DB::table('theses')->where('id', $row->id)
                ->update(['year' => (int) substr((string) $row->created_at, 0, 4)]));

        Schema::table('theses', function (Blueprint $table) {
            $table->dropIndex(['year']);
        });

        Schema::table('theses', function (Blueprint $table) {
            $table->year('year')->nullable(false)->change();
        });

        Schema::table('theses', function (Blueprint $table) {
            $table->renameColumn('year', 'year_published');
        });

        Schema::table('theses', function (Blueprint $table) {
            $table->index('year_published');
        });
    }
};
