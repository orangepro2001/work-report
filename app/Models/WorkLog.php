<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkLog extends Model
{
    /** @use HasFactory<\Database\Factories\WorkLogFactory> */
    use HasFactory;

    protected $fillable = [
        'daily_log_id', 'issue_id', 'member_id', 'logged_on',
        'percent_from', 'percent_to', 'minutes', 'note',
    ];

    protected function casts(): array
    {
        return [
            'logged_on' => 'date',
            'percent_from' => 'int',
            'percent_to' => 'int',
            'minutes' => 'int',
        ];
    }

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
