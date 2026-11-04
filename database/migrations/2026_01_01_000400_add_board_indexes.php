<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes measured against the real query set (docs/perf.md).
 *
 * The board reads every issue ordered by status then rank; without this composite
 * index PostgreSQL sorts the whole table on every request. The trigram indexes make
 * the JQL-lite search (`summary ILIKE '%term%'`) use an index instead of a scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // Board lens, all projects: ORDER BY status_key, board_rank.
            $table->index(['status_key', 'board_rank'], 'issues_board_order_idx');
        });

        // Korean and mixed-script text: pg_trgm handles substring search without a
        // Korean morphological dictionary.
        DB::statement('CREATE INDEX IF NOT EXISTS issues_summary_trgm_idx ON issues USING gin (summary gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS issues_note_trgm_idx ON issues USING gin (note gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex('issues_board_order_idx');
        });

        DB::statement('DROP INDEX IF EXISTS issues_summary_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS issues_note_trgm_idx');
    }
};
