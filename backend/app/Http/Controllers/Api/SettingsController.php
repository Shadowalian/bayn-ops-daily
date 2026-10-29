<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function show()
    {
        $defaults = Setting::defaults();
        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = Setting::getValue($key, $default);
        }
        $out['reminder_times'] = Setting::reminderWindows();
        $out['holidays'] = Holiday::query()->orderBy('date')->get();
        $out['bot_token_set'] = (bool) config('services.telegram.bot_token');

        return response()->json($out);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);

        $data = $request->validate([
            'working_days' => ['sometimes', 'array'],
            'working_days.*' => ['integer', 'between:1,7'],
            'timezone' => ['sometimes', 'string'],
            'reminder_times' => ['sometimes', 'array'],
            'reminder_times.morning' => ['sometimes'],
            'reminder_times.morning.from' => ['sometimes', 'date_format:H:i'],
            'reminder_times.morning.to' => ['sometimes', 'date_format:H:i'],
            'reminder_times.evening' => ['sometimes'],
            'reminder_times.evening.from' => ['sometimes', 'date_format:H:i'],
            'reminder_times.evening.to' => ['sometimes', 'date_format:H:i'],
        ]);

        if (isset($data['reminder_times'])) {
            $data['reminder_times'] = $this->normalizeReminderPayload($data['reminder_times']);
        }

        foreach ($data as $key => $value) {
            Setting::putValue($key, $value);
        }

        return $this->show();
    }

    public function storeHoliday(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);

        $data = $request->validate([
            'date' => ['required', 'date', 'unique:holidays,date'],
            'name' => ['required', 'string', 'max:200'],
        ]);

        return response()->json(Holiday::query()->create($data), 201);
    }

    private function normalizeReminderPayload(array $raw): array
    {
        $out = Setting::reminderWindows();
        foreach (['morning', 'evening'] as $slot) {
            if (! isset($raw[$slot])) {
                continue;
            }
            if (is_string($raw[$slot])) {
                $out[$slot] = Setting::normalizeWindow($raw[$slot], $out[$slot]['from'], $out[$slot]['to']);
            } else {
                $from = $raw[$slot]['from'] ?? $out[$slot]['from'];
                $to = $raw[$slot]['to'] ?? $from;
                if ($to < $from) {
                    throw ValidationException::withMessages([
                        "reminder_times.{$slot}.to" => 'End time must be the same as or after start time.',
                    ]);
                }
                $out[$slot] = ['from' => $from, 'to' => $to];
            }
        }

        return $out;
    }
}
