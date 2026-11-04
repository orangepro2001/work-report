<?php

namespace App\Models;

use Database\Factories\SprintFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sprint extends Model
{
    /** @use HasFactory<SprintFactory> */
    use HasFactory;

    protected $fillable = ['project_id', 'name', 'goal', 'state', 'starts_on', 'ends_on', 'position'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'position' => 'int'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function scopeActive($query)
    {
        return $query->where('state', 'active');
    }
}
