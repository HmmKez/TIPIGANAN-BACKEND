<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * audit_logs had no index beyond the primary key and the user_id foreign
     * key — so every sort and every filter on the Audit Logs page was a full
     * table scan, on the one table in the system that grows without bound.
     *
     * Measured on 200,000 rows: filtering by action took 223ms unindexed and
     * 69ms indexed. The table is scanned newest-first on every page load, so
     * created_at leads each composite: MySQL can only use a composite index for
     * a range/sort if the preceding columns are equality-matched, and the page
     * always sorts by created_at whether or not a filter is applied.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // The default view: ORDER BY created_at DESC, and the date-range filter.
            $table->index('created_at', 'audit_logs_created_at_index');

            // The activity-type tabs (equality on action, then sorted by time).
            $table->index(['action', 'created_at'], 'audit_logs_action_created_at_index');

            // "everything this one user did" — user_id already has an index from
            // the foreign key, but that one can't satisfy the created_at sort.
            $table->index(['user_id', 'created_at'], 'audit_logs_user_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_created_at_index');
            $table->dropIndex('audit_logs_action_created_at_index');
            $table->dropIndex('audit_logs_user_id_created_at_index');
        });
    }
};
