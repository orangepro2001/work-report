<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IssueComment extends Model
{
    /** @use HasFactory<\Database\Factories\IssueCommentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['issue_id', 'author_id', 'body'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'author_id');
    }
}
