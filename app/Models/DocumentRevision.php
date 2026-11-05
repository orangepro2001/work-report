<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One saved state of a document; the body of an earlier revision is recoverable. */
class DocumentRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_id', 'revision', 'title', 'body', 'editor_id', 'created_at'];

    protected function casts(): array
    {
        return ['revision' => 'int', 'created_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'editor_id');
    }
}
