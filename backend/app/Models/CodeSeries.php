<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CodeSeries extends Model
{
    protected $fillable = [
        'prefix',
        'name',
        'access_role',
        'next_number',
        'pad_length',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'pad_length' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function peekNextCode(): string
    {
        return $this->formatCode($this->next_number);
    }

    public function allocateNextCode(): string
    {
        return DB::transaction(function () {
            $series = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            $code = $series->formatCode($series->next_number);
            while (User::query()->where('employee_id', $code)->exists()) {
                $series->next_number++;
                $code = $series->formatCode($series->next_number);
            }
            $series->next_number++;
            $series->save();

            return $code;
        });
    }

    public function formatCode(int $number): string
    {
        return strtoupper($this->prefix).str_pad((string) $number, max(1, $this->pad_length), '0', STR_PAD_LEFT);
    }
}
