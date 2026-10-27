<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        $defaults = Setting::defaults();
        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = Setting::getValue($key, $default);
        }
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
            'reminder_times.morning' => ['sometimes', 'date_format:H:i'],
            'reminder_times.evening' => ['sometimes', 'date_format:H:i'],
        ]);

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
}
