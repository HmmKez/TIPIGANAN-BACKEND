<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lets the upload screen say what is happening to a document after it is saved.
//
// Reading a scanned thesis takes minutes - a real 36-page upload measured
// 4m41s - and that work is moving out of the upload request into a background
// worker. Once it is no longer finished by the time the page returns, the
// record has to carry its own state, or staff see an empty abstract with no way
// to tell whether it is still being read, already finished, or failed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theses', function (Blueprint $table) {
            // pending    - queued, not picked up yet
            // processing - a worker is reading it now
            // done       - finished (whether or not anything was found: a scan
            //              too poor to read is a completed job, not a failure)
            // failed     - the job errored or ran past its time limit
            $table->string('ocr_status', 16)->default('done')->after('checksum');
            $table->timestamp('ocr_completed_at')->nullable()->after('ocr_status');
        });

        // Every thesis uploaded before this migration was read synchronously
        // during its own upload, so its OCR is already finished. The column
        // defaults to 'done' for exactly this reason - without it, every
        // existing record would show "Reading the document..." forever.
        DB::table('theses')->whereNull('ocr_completed_at')->update([
            'ocr_status'       => 'done',
            'ocr_completed_at' => DB::raw('updated_at'),
        ]);

        Schema::table('theses', function (Blueprint $table) {
            // The worker and the "still waiting?" checks both look up rows by
            // state, never by id.
            $table->index('ocr_status');
        });
    }

    public function down(): void
    {
        Schema::table('theses', function (Blueprint $table) {
            $table->dropIndex(['ocr_status']);
            $table->dropColumn(['ocr_status', 'ocr_completed_at']);
        });
    }
};
