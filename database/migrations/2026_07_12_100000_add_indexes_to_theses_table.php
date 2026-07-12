<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theses', function (Blueprint $table) {
            // Default Browse / Collection Management query filters by status
            // and shows newest first (ORDER BY created_at desc). A composite
            // index lets one index serve both the filter and the sort, so it
            // stays fast as the collection grows into the thousands+ rather
            // than full-scanning + filesorting the whole table each page.
            $table->index(['status', 'created_at']);

            // Year filter/facet on Browse. (category_id and uploaded_by are
            // already indexed via their foreign keys; title/authors are only
            // ever queried with a leading-wildcard LIKE, which no B-tree index
            // can serve — that's what Meilisearch is for.)
            $table->index('year_published');
        });
    }

    public function down(): void
    {
        Schema::table('theses', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['year_published']);
        });
    }
};
