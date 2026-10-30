<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyGoal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REMOVE_REQUESTED = 'remove_requested';

    public const STATUS_REMOVED = 'removed';

    public const LIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REMOVE_REQUESTED,
    ];

    public const TABLE_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_REMOVE_REQUESTED,
    ];

    protected $fillable = [
        'weekly_plan_id',
        'title',
        'owner_id',
        'week_start',
        'success_measure',
        'status',
        'source',
        'assigned_by',
        'position',
        'review_note',
        'remove_request_note',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'position' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function isOnTable(): bool
    {
        return in_array($this->status, self::TABLE_STATUSES, true);
    }

    public function srcLabel(): string
    {
        if ($this->status === self::STATUS_REMOVE_REQUESTED) {
            return 'cut';
        }

        return $this->source === 'admin' ? 'adm' : 'you';
    }
}
