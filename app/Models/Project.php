<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = ['name', 'abbr', 'color', 'description', 'position', 'archived'];

    protected function casts(): array
    {
        return ['archived' => 'bool', 'position' => 'int'];
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'project_members')
            ->withPivot('role')->withTimestamps();
    }

    /** Board columns in workflow order, as declared in config/workreport.php. */
    public function statuses(): array
    {
        return config('workreport.statuses');
    }

    public function scopeVisible($query)
    {
        return $query->where('archived', false)->orderBy('position')->orderBy('id');
    }
}
