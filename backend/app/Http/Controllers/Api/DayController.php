<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Day;
use App\Services\AppTime;
use App\Services\DayService;
use Illuminate\Http\Request;

class DayController extends Controller
{
    public function __construct(private DayService $days) {}

    public function today(Request $request)
    {
        $day = $this->days->getOrCreateToday($request->user());

        return response()->json($this->dayPayload($day));
    }

    public function submitPlan(Request $request)
    {
        $day = $this->days->submitPlan($request->user());

        return response()->json($this->dayPayload($day));
    }

    public function closeDay(Request $request)
    {
        $day = $this->days->closeDay($request->user());

        return response()->json($this->dayPayload($day));
    }

    public function history(Request $request, int $userId)
    {
        $actor = $request->user();
        if ($actor->id !== $userId && ! $actor->isManager()) {
            abort(403);
        }

        $from = $request->query('from', AppTime::now()->subDays(30)->toDateString());
        $to = $request->query('to', AppTime::today());

        $days = Day::query()
            ->with('tasks')
            ->where('user_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->paginate(31);

        return response()->json($days);
    }

    private function dayPayload(Day $day): array
    {
        $day->loadMissing('tasks');

        return [
            'id' => $day->id,
            'date' => $day->date->toDateString(),
            'state' => $day->state(),
            'edit_unlocked' => (bool) $day->edit_unlocked,
            'plan_locked' => $day->isPlanLocked(),
            'plan_submitted_at' => AppTime::toLocalIso($day->plan_submitted_at),
            'closed_at' => AppTime::toLocalIso($day->closed_at),
            'morning_prompted_at' => AppTime::toLocalIso($day->morning_prompted_at),
            'evening_prompted_at' => AppTime::toLocalIso($day->evening_prompted_at),
            'tasks' => $day->tasks->where('result', '!=', 'dropped')->values(),
            'settings' => [
                'timezone' => AppTime::timezone(),
                'morning_time' => \App\Models\Setting::reminderWindows()['morning'],
                'evening_time' => \App\Models\Setting::reminderWindows()['evening'],
            ],
        ];
    }
}
