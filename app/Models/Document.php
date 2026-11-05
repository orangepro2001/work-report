<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A shared document: an internal asset that lives next to the board instead of in
 * someone's drive (docs/documents.md).
 *
 * `revision` is the concurrency token — the editor sends the number it started
 * from, and a mismatch is answered with 409 rather than overwriting another
 * person's work.
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    public const VISIBILITY_TEAM = 'team';

    public const VISIBILITY_PRIVATE = 'private';

    protected $fillable = [
        'slug', 'title', 'body', 'visibility', 'owner_id', 'revision',
    ];

    protected function casts(): array
    {
        return ['revision' => 'int'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'owner_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class)->orderByDesc('revision');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(DocumentShare::class);
    }

    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'document_shares')
            ->withPivot('role')->withTimestamps();
    }

    public function presence(): HasMany
    {
        return $this->hasMany(DocumentPresence::class);
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    /** Everyone may read a team document; a private one only its people. */
    public function isVisibleTo(Member $member): bool
    {
        if (! $this->isPrivate()) {
            return true;
        }

        return $this->owner_id === $member->id
            || $this->shares()->where('member_id', $member->id)->exists();
    }

    public function isEditableBy(Member $member): bool
    {
        if (! $this->isVisibleTo($member)) {
            return false;
        }

        return ! $this->isPrivate() || $this->owner_id === $member->id
            || $this->shares()->where('member_id', $member->id)->where('role', 'editor')->exists();
    }

    /** Unique, readable, and stable once created — links are quoted in chat. */
    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'doc';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
