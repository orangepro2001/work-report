<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-project issue counter.
 *
 * Allocating `max(issue_no) + 1` needs either an aggregate with FOR UPDATE (which
 * PostgreSQL rejects) or a lock over every issue row. A counter column on the
 * project row is both cheaper and precise: the row lock is one row per project,
 * and `issue_high_water` keeps keys stable across deletions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Next number to hand out.
            $table->unsignedInteger('next_issue_no')->default(1)->after('archived');
            // Highest number ever handed out; never decreases.
            $table->unsignedInteger('issue_high_water')->default(0)->after('next_issue_no');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['next_issue_no', 'issue_high_water']);
        });
    }
};
