<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The repository holds more than theses - Special Collections, Institutional
// Publications, Special Boholano Creations - and most of those have no
// adviser. The column was NOT NULL, so staff had to type something meaningless
// into the field to upload them at all.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theses', function (Blueprint $table) {
            $table->string('adviser')->nullable()->change();
        });
    }

    public function down(): void
    {
        // NOT NULL again needs a value in every row, so an item saved without
        // an adviser goes back to an empty string rather than blocking rollback.
        DB::table('theses')->whereNull('adviser')->update(['adviser' => '']);

        Schema::table('theses', function (Blueprint $table) {
            $table->string('adviser')->nullable(false)->change();
        });
    }
};
