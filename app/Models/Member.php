<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'email', 'picture_url', 'oidc_issuer', 'oidc_subject', 'department', 'title',
        'reports_to_id', 'color', 'position', 'active', 'is_external',
    ];

    protected function casts(): array
    {
        return ['active' => 'bool', 'is_external' => 'bool', 'position' => 'int'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot('role')->withTimestamps();
    }

    public function assignedIssues(): HasMany
    {
        return $this->hasMany(Issue::class, 'assignee_id');
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
    }

    /** Two-letter avatar text: first syllable of the given name. */
    public function initials(): string
    {
        return mb_substr($this->name, 0, 1);
    }

    /**
     * The people who can be assigned work: the ones with an account. A name-only
     * contact (is_external) can report an issue, never own one.
     */
    public function scopeInternal($query)
    {
        return $query->where('is_external', false);
    }

    /** Finds a person by name, or creates a name-only contact for a first-time reporter. */
    public static function forName(string $name): self
    {
        $name = trim($name);

        $existing = static::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::create([
            'key' => static::uniqueKey($name),
            'name' => $name,
            'department' => config('workreport.department'),
            'color' => '#7f7f7f',
            'position' => (int) static::max('position') + 1,
            'active' => true,
            'is_external' => true,
        ]);
    }

    private static function uniqueKey(string $name): string
    {
        $base = Str::slug($name) ?: 'person';
        $base = mb_substr($base, 0, 40) ?: 'person';
        $key = $base;

        for ($i = 2; static::where('key', $key)->exists(); $i++) {
            $key = $base.'-'.$i;
        }

        return $key;
    }

    /**
     * Everything that would lose its meaning if this row disappeared.
     *
     * Most foreign keys pointing at members are `on delete set null`, so deleting a
     * person silently erases who wrote a comment, who moved an issue, who reported it.
     * The member page therefore refuses to delete anything with references and offers
     * "deactivate" instead — inactive members vanish from the pickers and from the
     * workbook's 담당자 요약 sheet while the history keeps its attribution.
     */
    public function referenceCount(): int
    {
        if ($this->getKey() === null) {
            return 0;
        }

        return (int) $this->newQuery()->whereKey($this->getKey())->where(function ($outer) {
            $outer
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('issues')->where('assignee_id', $this->id)->orWhere('reporter_id', $this->id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('issue_activities')->where('actor_id', $this->id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('issue_comments')->where('author_id', $this->id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('issue_todos')->where('assignee_id', $this->id)->orWhere('created_by', $this->id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('documents')->where('owner_id', $this->id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('document_revisions')->where('editor_id', $this->id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('attachments')->where('uploader_id', $this->id));
        })->exists() ? 1 : 0;
    }

    /** How many issues carry this member as assignee or reporter — for the member page. */
    public function issueCount(): int
    {
        return (int) Issue::query()
            ->where(fn ($q) => $q->where('assignee_id', $this->id)->orWhere('reporter_id', $this->id))
            ->count();
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->orderBy('position')->orderBy('id');
    }
}
