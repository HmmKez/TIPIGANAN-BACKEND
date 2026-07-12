<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theses', function (Blueprint $table) {
            // SHA-256 fixity hash of the stored PDF (64 hex chars). Nullable:
            // existing rows are backfilled by `php artisan theses:verify-checksums`.
            // Re-verifying this later detects silent corruption/tampering — the
            // point of fixity in a single-copy archive.
            $table->string('checksum', 64)->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('theses', function (Blueprint $table) {
            $table->dropColumn('checksum');
        });
    }
};
