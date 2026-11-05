<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One sub-task of an issue (database/migrations/…create_issue_todos). */
class IssueTodo extends Model
{
    protected $fillable = [
        'issue_id', 'title', 'done', 'position', 'assignee_id', 'due_on', 'created_by',
    ];

    protected function casts(): array
    {
        return ['done' => 'bool', 'position' => 'int', 'due_on' => 'date'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'assignee_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'created_by');
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'done' => $this->done,
            'position' => $this->position,
            'assignee_id' => $this->assignee_id,
            'assignee_name' => $this->assignee?->name,
            'due_on' => $this->due_on?->toDateString(),
        ];
    }
}
