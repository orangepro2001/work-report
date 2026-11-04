<?php

namespace App\Models;

use Database\Factories\DailyLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyLog extends Model
{
    /** @use HasFactory<DailyLogFactory> */
    use HasFactory;

    protected $fillable = [
        'log_date', 'member_id', 'department', 'author_name', 'state', 'submitted_at', 'mood',
    ];

    protected function casts(): array
    {
        return ['log_date' => 'date', 'submitted_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    public function isSubmitted(): bool
    {
        return $this->state === 'submitted';
    }
}
