<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A fingerprint of the PDF exactly as it was uploaded, so the same file cannot
// be filed twice.
//
// `checksum` cannot do this job. It is taken AFTER the upload is rewritten for
// the viewer (PdfNormalizer / qpdf), and that rewrite is not repeatable:
// measured on two real theses, the same file rewritten twice gave two
// different hashes. Only the bytes as received are stable.
//
// Existing items keep NULL here - their originals were rewritten in place and
// no longer exist. They are still matched through `checksum` when their stored
// file itself comes back (e.g. downloaded and re-uploaded), and through the
// title-and-authors warning otherwise.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theses', function (Blueprint $table) {
            $table->char('original_checksum', 64)->nullable()->after('checksum');
            $table->index('original_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('theses', function (Blueprint $table) {
            $table->dropIndex(['original_checksum']);
            $table->dropColumn('original_checksum');
        });
    }
};
