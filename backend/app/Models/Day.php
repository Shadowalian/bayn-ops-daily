<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Day extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'work_status',
        'check_in_at',
        'check_out_at',
        'late',
        'late_excused',
        'plan_submitted_at',
        'edit_unlocked',
        'morning_prompted_at',
        'evening_prompted_at',
        'closed_at',
        'day_note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'plan_submitted_at' => 'datetime',
            'morning_prompted_at' => 'datetime',
            'evening_prompted_at' => 'datetime',
            'closed_at' => 'datetime',
            'late' => 'boolean',
            'late_excused' => 'boolean',
            'edit_unlocked' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    public function isPlanLocked(): bool
    {
        return (bool) $this->plan_submitted_at && ! $this->edit_unlocked;
    }

    public function state(): string
    {
        if ($this->closed_at) {
            return 'closed';
        }

        if ($this->plan_submitted_at) {
            return $this->edit_unlocked ? 'edit_unlocked' : 'planned';
        }

        if ($this->morning_prompted_at) {
            return 'awaiting_plan';
        }

        return 'not_started';
    }
}
