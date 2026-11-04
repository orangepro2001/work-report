<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SSO link (docs/auth-seam.md).
 *
 * `members.key` stays the human handle the workbook and the issue keys were built
 * on. The issuer+subject pair is what the authorization center calls this person,
 * so nothing is derived from a display name and a provider can be swapped later
 * without renaming rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('oidc_issuer')->nullable()->after('email');
            $table->string('oidc_subject')->nullable()->after('oidc_issuer');

            // Nullable pair: seeded members that never signed in keep both columns
            // empty, and PostgreSQL allows any number of NULLs in a unique index.
            $table->unique(['oidc_issuer', 'oidc_subject']);
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['oidc_issuer', 'oidc_subject']);
            $table->dropColumn(['oidc_issuer', 'oidc_subject']);
        });
    }
};
