<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'email', 'department', 'title',
        'reports_to_id', 'color', 'position', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'bool', 'position' => 'int'];
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

    public function scopeActive($query)
    {
        return $query->where('active', true)->orderBy('position')->orderBy('id');
    }
}
