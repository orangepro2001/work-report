<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A private document handed to one more person (docs/documents.md). */
class DocumentShare extends Model
{
    public const ROLE_EDITOR = 'editor';

    public const ROLE_VIEWER = 'viewer';

    protected $fillable = ['document_id', 'member_id', 'role'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
