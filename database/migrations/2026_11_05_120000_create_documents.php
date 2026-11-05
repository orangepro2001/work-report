<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The document library (an internal assets shelf, docs/documents.md).
 *
 * `revision` is the optimistic-concurrency token: every save increments it, and a
 * save carrying a stale number is refused instead of silently overwriting whoever
 * got there first. `document_revisions` keeps the text of every save so a bad edit
 * is recoverable without a database restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('body')->default('');
            // team = every signed-in member may read and edit; private = owner and shares.
            $table->string('visibility', 16)->default('team');
            $table->foreignId('owner_id')->nullable()->constrained('members')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['visibility', 'updated_at']);
        });

        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('editor');   // editor | viewer
            $table->timestamps();

            $table->unique(['document_id', 'member_id']);
        });

        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('title');
            $table->text('body');
            $table->foreignId('editor_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['document_id', 'revision']);
        });

        // Who has the document open right now. A row is "alive" for 30 seconds; the
        // editor refreshes its own row on the same poll that asks for changes.
        Schema::create('document_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->timestamp('seen_at')->useCurrent();

            $table->unique(['document_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_presences');
        Schema::dropIfExists('document_revisions');
        Schema::dropIfExists('document_shares');
        Schema::dropIfExists('documents');
    }
};
