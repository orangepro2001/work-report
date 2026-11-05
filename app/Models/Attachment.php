<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file stored on this host and served through the application, never from a
 * public directory: an image in a private document must not be readable by URL
 * guessing (docs/documents.md).
 */
class Attachment extends Model
{
    protected $fillable = ['attachable_type', 'attachable_id', 'uploader_id', 'disk', 'path', 'original_name', 'mime_type', 'size'];

    protected function casts(): array
    {
        return ['size' => 'int'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'uploader_id');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }
}
