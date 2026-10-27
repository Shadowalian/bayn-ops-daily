<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("setting.{$key}", 60, fn () => static::query()->where('key', $key)->first());

        return $setting?->value ?? $default;
    }

    public static function putValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.{$key}");
    }

    public static function defaults(): array
    {
        return [
            'working_days' => [1, 2, 3, 4, 5],
            'timezone' => 'Africa/Addis_Ababa',
            'reminder_times' => [
                'morning' => '09:00',
                'evening' => '17:30',
            ],
        ];
    }
}
