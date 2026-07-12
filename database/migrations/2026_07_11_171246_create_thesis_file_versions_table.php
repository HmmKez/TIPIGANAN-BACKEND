<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('thesis_file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thesis_id')->constrained()->onDelete('cascade');
            $table->string('file_path');
            $table->foreignId('replaced_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('replaced_at');
            // When the auto-purge sweep is allowed to delete the underlying
            // file — replaced_at + the configured retention window.
            $table->timestamp('purge_after');
            $table->timestamp('purged_at')->nullable();
            // 'restored' means this exact version was promoted back to being
            // the thesis's active file (restoring doesn't delete the row —
            // it stays as history, just no longer restorable a second time
            // since the file itself moved back into active use).
            $table->enum('status', ['pending', 'restored', 'purged'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('thesis_file_versions');
    }
};
