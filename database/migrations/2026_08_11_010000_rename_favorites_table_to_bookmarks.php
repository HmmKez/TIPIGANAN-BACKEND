<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// The feature is called a bookmark everywhere a user meets it - the button on
// a thesis, the page heading, the sidebar, the dashboard tile - but the table
// was still called `favorites`, which meant the data dictionary in the capstone
// documentation described a table by a name that appears nowhere in the system.
//
// A rename rather than a create/copy/drop: it is atomic, keeps every row, and
// needs no data migration. Nothing else in the schema points AT this table (it
// points out to users and theses, and those are untouched), so there are no
// dependent constraints to rebuild.
//
// The API routes (/api/favorites) are deliberately NOT renamed here. Those are
// a contract with a separately-deployed frontend, so changing them means
// coordinating two deploys; the table name is internal and changing it is
// invisible from outside.
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so this is safe to run against a database that has already
        // been renamed by hand, or a fresh one built from a later snapshot.
        if (Schema::hasTable('favorites') && ! Schema::hasTable('bookmarks')) {
            Schema::rename('favorites', 'bookmarks');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bookmarks') && ! Schema::hasTable('favorites')) {
            Schema::rename('bookmarks', 'favorites');
        }
    }
};
