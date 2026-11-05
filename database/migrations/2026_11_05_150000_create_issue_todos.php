<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-tasks of an issue, as rows rather than Markdown.
 *
 * The `tasks` fenced block was the wrong shape for this: a checklist inside a text
 * field needs the author to remember a syntax, cannot be toggled, and can only show
 * the board's state by reference. A todo is data — one row, its own position, its own
 * done flag — so ticking one is a single request and the progress is a count.
 *
 * The Markdown block stays for documents (a summary *of* issues); an issue's own
 * checklist is this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('title', 300);
            $table->boolean('done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('assignee_id')->nullable()->constrained('members')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();

            $table->index(['issue_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_todos');
    }
};
