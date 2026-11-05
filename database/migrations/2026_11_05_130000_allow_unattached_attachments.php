<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An image is uploaded before the document (or issue) it belongs to exists, so the
 * polymorphic pair has to be nullable until it is attached. Images that never get
 * attached are the uploader's alone to see (docs/documents.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('attachable_type')->nullable()->change();
            $table->unsignedBigInteger('attachable_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('attachable_type')->nullable(false)->change();
            $table->unsignedBigInteger('attachable_id')->nullable(false)->change();
        });
    }
};
