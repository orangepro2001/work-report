<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who has a document open. A row is alive while `seen_at` is fresh; the editor
 * refreshes its own row on every poll, so a closed tab ages out on its own.
 */
class DocumentPresence extends Model
{
    public $timestamps = false;

    /** Seconds after which a reader is considered gone. */
    public const TTL = 45;

    protected $fillable = ['document_id', 'member_id', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
