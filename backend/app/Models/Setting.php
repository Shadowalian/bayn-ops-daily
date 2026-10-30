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
                'morning' => ['from' => '08:30', 'to' => '09:30'],
                'evening' => ['from' => '17:00', 'to' => '18:00'],
            ],
            'weekly_plans_enabled' => true,
        ];
    }

    /** @return array{morning: array{from: string, to: string}, evening: array{from: string, to: string}} */
    public static function reminderWindows(): array
    {
        $raw = self::getValue('reminder_times', self::defaults()['reminder_times']);

        return [
            'morning' => self::normalizeWindow($raw['morning'] ?? null, '08:30', '09:30'),
            'evening' => self::normalizeWindow($raw['evening'] ?? null, '17:00', '18:00'),
        ];
    }

    /** @return array{from: string, to: string} */
    public static function normalizeWindow(mixed $value, string $defaultFrom, string $defaultTo): array
    {
        if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
            return ['from' => $value, 'to' => $value];
        }

        if (is_array($value)) {
            $from = $value['from'] ?? $defaultFrom;
            $to = $value['to'] ?? $from;

            return ['from' => $from, 'to' => $to];
        }

        return ['from' => $defaultFrom, 'to' => $defaultTo];
    }

    public static function timeInWindow(string $hm, array $window): bool
    {
        $from = $window['from'] ?? null;
        $to = $window['to'] ?? $from;
        if (! $from || ! $to) {
            return false;
        }

        return $hm >= $from && $hm <= $to;
    }
}
