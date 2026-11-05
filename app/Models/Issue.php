<?php

namespace App\Models;

use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The central entity: a card on the board and a row in the exported workbook.
 */
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id', 'sprint_id', 'issue_no', 'summary', 'description',
        'priority_key', 'status_key', 'percent', 'reporter_id', 'assignee_id',
        'starts_on', 'due_on', 'due_undecided', 'note', 'board_rank',
        'flagged', 'story_points', 'completed_at',
    ];

    protected $appends = ['issue_key'];

    protected function casts(): array
    {
        return [
            'percent' => 'int',
            'board_rank' => 'float',
            'story_points' => 'int',
            'flagged' => 'bool',
            'due_undecided' => 'bool',
            'starts_on' => 'date',
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relations */
    /* ------------------------------------------------------------------ */

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'assignee_id');
    }

    /** 공동 담당 — additional names joined with ", " in the workbook. */
    public function coAssignees(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'issue_assignees')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'issue_watchers')->withTimestamps();
    }

    /**
     * Does this issue belong to this person?
     *
     * Ownership is narrow on purpose: the assignee or the reporter. Everyone else can
     * read it, comment on it, and nothing more — the board is a shared view of work that
     * individual people are responsible for, not a wiki where anyone edits anything.
     */
    public function isOwnedBy(?Member $member): bool
    {
        if ($member === null) {
            return false;
        }

        return $this->assignee_id === $member->id || $this->reporter_id === $member->id;
    }

    /** The issue's own checklist (app/Models/IssueTodo.php). */
    public function todos(): HasMany
    {
        return $this->hasMany(IssueTodo::class)->orderBy('position')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(IssueComment::class)->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(IssueActivity::class)->latest();
    }

    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    /* ------------------------------------------------------------------ */
    /* Derived attributes */
    /* ------------------------------------------------------------------ */

    /**
     * The workbook's 업무 키 (e.g. "3D-01"): project abbreviation plus the
     * zero-padded sequence number. Requires `project` to be loaded; callers that
     * list many issues must eager load it (see Issue::scopeForBoard()).
     */
    public function getIssueKeyAttribute(): string
    {
        $abbr = $this->relationLoaded('project')
            ? ($this->project->abbr ?? 'WR')
            : Project::whereKey($this->project_id)->value('abbr') ?? 'WR';

        return $abbr.'-'.str_pad((string) $this->issue_no, 2, '0', STR_PAD_LEFT);
    }

    /** All people on the task, in workbook order: assignee first, then co-assignees. */
    public function allAssigneeNames(): string
    {
        $names = collect();
        if ($this->assignee) {
            $names->push($this->assignee->name);
        }
        foreach ($this->coAssignees as $member) {
            if (! $names->contains($member->name)) {
                $names->push($member->name);
            }
        }

        return $names->implode(', ');
    }

    public function statusLabel(): string
    {
        return collect(config('workreport.statuses'))
            ->firstWhere('key', $this->status_key)['label'] ?? $this->status_key;
    }

    public function priorityLabel(): string
    {
        return collect(config('workreport.priorities'))
            ->firstWhere('key', $this->priority_key)['label'] ?? $this->priority_key;
    }

    /** A task is late when it is unfinished and its due date is already behind the report date. */
    public function isLateOn(\DateTimeInterface $reportDate): bool
    {
        if ($this->status_key === 'done' || ! $this->due_on || $this->due_undecided) {
            return false;
        }

        return $this->due_on->lt($reportDate);
    }

    /** Progress as the workbook's hidden 집계용 진행률 column computes it. */
    public function effectivePercent(): int
    {
        return $this->status_key === 'done' ? 100 : (int) $this->percent;
    }

    /* ------------------------------------------------------------------ */
    /* Scopes */
    /* ------------------------------------------------------------------ */

    /** Everything the board needs, in two queries plus the co-assignee pivot. */
    public function scopeForBoard(Builder $query): Builder
    {
        return $query->with([
            'project:id,abbr,name,color',
            'assignee:id,name,color',
            'coAssignees:id,name,color',
        ]);
    }

    public function scopeDone(Builder $query): Builder
    {
        return $query->where('status_key', 'done');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status_key', '!=', 'done');
    }

    public function scopeForMember(Builder $query, int $memberId): Builder
    {
        return $query->where(function (Builder $q) use ($memberId) {
            $q->where('assignee_id', $memberId)
                ->orWhereHas('coAssignees', fn (Builder $c) => $c->where('members.id', $memberId));
        });
    }
}
