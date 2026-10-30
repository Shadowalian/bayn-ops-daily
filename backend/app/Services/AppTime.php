<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Setting;
use Carbon\Carbon;

class AppTime
{
    public static function timezone(): string
    {
        return Setting::getValue('timezone', config('app.timezone', 'Africa/Addis_Ababa'));
    }

    public static function now(): Carbon
    {
        return now(self::timezone());
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** Monday of the week containing $date (app timezone). */
    public static function weekStart(?Carbon $date = null): string
    {
        $date = ($date ?? self::now())->copy()->timezone(self::timezone());

        return $date->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public static function weeklyPlansEnabled(): bool
    {
        return (bool) Setting::getValue('weekly_plans_enabled', true);
    }

    public static function isWorkingDay(?Carbon $date = null): bool
    {
        $date ??= self::now();
        $workingDays = Setting::getValue('working_days', [1, 2, 3, 4, 5]);
        $dow = (int) $date->dayOfWeekIso; // 1=Mon … 7=Sun

        if (! in_array($dow, $workingDays, true)) {
            return false;
        }

        return ! Holiday::query()->whereDate('date', $date->toDateString())->exists();
    }

    public static function nextWorkingDay(?Carbon $from = null): Carbon
    {
        $date = ($from ?? self::now())->copy()->startOfDay()->addDay();

        while (! self::isWorkingDay($date)) {
            $date->addDay();
        }

        return $date;
    }

    public static function settingTime(string $key, string $default): Carbon
    {
        $time = Setting::getValue($key, $default);
        [$h, $m] = array_map('intval', explode(':', $time));

        return self::now()->copy()->setTime($h, $m, 0);
    }

    public static function toLocalIso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->timezone(self::timezone())->toIso8601String();
    }
}
