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
        Schema::create('theses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('authors');
            $table->string('adviser');
            $table->text('abstract');
            $table->text('keywords')->nullable();
            $table->year('year_published');
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->integer('pages')->nullable();
            $table->string('file_path');
            $table->string('cover_image_path')->nullable();
            $table->enum('status', ['active', 'archived', 'restricted'])->default('active');
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theses');
    }
};
