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
        'key', 'name', 'email', 'oidc_issuer', 'oidc_subject', 'department', 'title',
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

    public function scopeActive($query)
    {
        return $query->where('active', true)->orderBy('position')->orderBy('id');
    }
}
