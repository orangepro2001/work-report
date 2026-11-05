<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SSO picture, kept on the member.
 *
 * The claim is a signed URL on the authorization center (its `User::avatarUrl()`), so
 * this application can render it without fetching anything and without holding a token:
 * the row remembers what the last sign-in said, and falls back to initials when the
 * person never uploaded one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('picture_url', 500)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('picture_url');
        });
    }
};
