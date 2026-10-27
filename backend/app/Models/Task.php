<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    public const REASON_CODES = [
        'took_longer',
        'waiting_external',
        'waiting_internal',
        'priority_changed',
        'interrupted',
        'blocked',
        'other',
    ];

    protected $fillable = [
        'user_id',
        'day_id',
        'title',
        'tier',
        'position',
        'result',
        'reason_code',
        'reason_note',
        'blocked',
        'blocker_note',
        'effort',
        'added_after_cutoff',
        'carried_from_task_id',
        'carry_count',
        'weekly_goal_id',
    ];

    protected function casts(): array
    {
        return [
            'blocked' => 'boolean',
            'added_after_cutoff' => 'boolean',
            'carry_count' => 'integer',
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(Day::class);
    }

    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'carried_from_task_id');
    }

    public function weeklyGoal(): BelongsTo
    {
        return $this->belongsTo(WeeklyGoal::class);
    }
}
