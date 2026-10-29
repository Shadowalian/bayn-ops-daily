<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'employee_id',
        'code_series_id',
        'name',
        'email',
        'password',
        'telegram_chat_id',
        'role',
        'job_title',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function codeSeries(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CodeSeries::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(Day::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function leaveDays(): HasMany
    {
        return $this->hasMany(LeaveDay::class);
    }
}
