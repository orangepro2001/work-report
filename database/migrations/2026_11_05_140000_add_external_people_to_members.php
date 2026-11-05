<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People who are not accounts.
 *
 * Only three people sign in, but a report is often *addressed* by somebody outside
 * that circle (a customer, a manager from another department). Those names still have
 * to land in the workbook's 보고자 column, so they become `members` rows with
 * `is_external = true`: a name and nothing else — no OIDC link, no login, never an
 * assignee. Keeping them in `members` rather than a free-text column is what keeps the
 * existing foreign keys, filters and the exporter working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('is_external')->default(false)->after('active');
            $table->index(['is_external', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['is_external', 'active']);
            $table->dropColumn('is_external');
        });
    }
};
