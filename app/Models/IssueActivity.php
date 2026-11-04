<?php

namespace App\Models;

use Database\Factories\IssueActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueActivity extends Model
{
    /** @use HasFactory<IssueActivityFactory> */
    use HasFactory;

    protected $fillable = ['issue_id', 'actor_id', 'verb', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'actor_id');
    }

    /** Human sentence for the timeline, resolved through lang/<locale>/activity.php. */
    public function describe(): string
    {
        return __("activity.{$this->verb}", $this->changes ?? []);
    }
}
