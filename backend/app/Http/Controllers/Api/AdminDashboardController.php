<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Day;
use App\Models\Task;
use App\Models\User;
use App\Services\AppTime;
use App\Services\DayService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    public function __construct(private DayService $days) {}

    public function show(Request $request)
    {
        $tz = AppTime::timezone();
        $today = AppTime::today();
        $from = $request->query('from', Carbon::parse($today, $tz)->subDays(6)->toDateString());
        $to = $request->query('to', $today);

        $members = User::query()->where('active', true)->where('role', 'member')->orderBy('employee_id')->get();
        $allActive = User::query()->where('active', true)->orderBy('employee_id')->get();

        $teamToday = $this->days->teamToday($today);
        $memberRows = collect($teamToday)->filter(fn ($r) => ($r['user']['role'] ?? 'member') === 'member')->values();

        $plansSubmitted = $memberRows->filter(fn ($r) => ! empty($r['day']['plan_submitted_at']))->count();
        $daysClosed = $memberRows->filter(fn ($r) => ($r['day']['state'] ?? '') === 'closed')->count();
        $blockers = $memberRows->sum(fn ($r) => count($r['blockers'] ?? []));
        $linked = $members->filter(fn (User $u) => (bool) $u->telegram_chat_id)->count();

        $todayDone = $memberRows->sum('overall_done');
        $todayPlanned = $memberRows->sum('overall_planned');
        $todayRate = $todayPlanned > 0 ? round(($todayDone / $todayPlanned) * 100) : null;

        $rangeTasks = Task::query()
            ->with(['user', 'day'])
            ->where('result', '!=', 'dropped')
            ->whereHas('day', fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->whereHas('user', fn ($q) => $q->where('role', 'member')->where('active', true))
            ->get();

        $rangeDone = $rangeTasks->where('result', 'done')->count();
        $rangePlanned = $rangeTasks->count();
        $rangeRate = $rangePlanned > 0 ? round(($rangeDone / $rangePlanned) * 100) : null;

        $reasonBreakdown = $rangeTasks
            ->whereIn('result', ['not_done', 'partial'])
            ->groupBy(fn (Task $t) => $t->reason_code ?: 'unspecified')
            ->map(fn ($group, $code) => [
                'reason_code' => $code,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values();

        $employeePerformance = $members->map(function (User $user) use ($from, $to, $today, $teamToday) {
            $todayRow = collect($teamToday)->first(fn ($r) => $r['user']['id'] === $user->id);

            $tasks = Task::query()
                ->where('user_id', $user->id)
                ->where('result', '!=', 'dropped')
                ->whereHas('day', fn ($q) => $q->whereBetween('date', [$from, $to]))
                ->get();

            $done = $tasks->where('result', 'done')->count();
            $planned = $tasks->count();
            $notDone = $tasks->whereIn('result', ['not_done', 'partial'])->count();
            $carryHeavy = $tasks->where('carry_count', '>=', 2)->count();

            $daysWithPlan = Day::query()
                ->where('user_id', $user->id)
                ->whereBetween('date', [$from, $to])
                ->whereNotNull('plan_submitted_at')
                ->count();

            $daysClosed = Day::query()
                ->where('user_id', $user->id)
                ->whereBetween('date', [$from, $to])
                ->whereNotNull('closed_at')
                ->count();

            $workingDaysInRange = $this->countWorkingDays($from, $to);

            return [
                'user' => [
                    'id' => $user->id,
                    'employee_id' => $user->employee_id,
                    'name' => $user->name,
                    'role' => $user->role,
                    'telegram_linked' => (bool) $user->telegram_chat_id,
                ],
                'today' => [
                    'state' => $todayRow['day']['state'] ?? 'not_started',
                    'done' => $todayRow['overall_done'] ?? 0,
                    'planned' => $todayRow['overall_planned'] ?? 0,
                    'completion' => $todayRow['overall_completion'],
                    'blockers' => count($todayRow['blockers'] ?? []),
                    'day_id' => $todayRow['day']['id'] ?? null,
                    'edit_unlocked' => $todayRow['day']['edit_unlocked'] ?? false,
                ],
                'period' => [
                    'from' => $from,
                    'to' => $to,
                    'done' => $done,
                    'planned' => $planned,
                    'not_done' => $notDone,
                    'completion' => $planned > 0 ? round(($done / $planned) * 100) : null,
                    'plans_submitted' => $daysWithPlan,
                    'days_closed' => $daysClosed,
                    'working_days' => $workingDaysInRange,
                    'plan_submission_rate' => $workingDaysInRange > 0 ? round(($daysWithPlan / $workingDaysInRange) * 100) : null,
                    'close_rate' => $workingDaysInRange > 0 ? round(($daysClosed / $workingDaysInRange) * 100) : null,
                    'carry_over_hot' => $carryHeavy,
                ],
            ];
        })->values();

        $dailyTrend = [];
        $cursor = Carbon::parse($from, $tz)->startOfDay();
        $end = Carbon::parse($to, $tz)->startOfDay();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $dayTasks = $rangeTasks->filter(fn (Task $t) => $t->day && $t->day->date->toDateString() === $date);
            $planned = $dayTasks->count();
            $done = $dayTasks->where('result', 'done')->count();
            $dailyTrend[] = [
                'date' => $date,
                'planned' => $planned,
                'done' => $done,
                'completion' => $planned > 0 ? round(($done / $planned) * 100) : null,
            ];
            $cursor->addDay();
        }

        $openRoadblocks = $memberRows
            ->flatMap(fn ($r) => collect($r['blockers'] ?? [])->map(fn ($b) => [
                'employee_id' => $r['user']['employee_id'] ?? null,
                'name' => $r['user']['name'],
                'task_id' => $b['id'],
                'title' => $b['title'],
                'blocker_note' => $b['blocker_note'],
            ]))
            ->values();

        return response()->json([
            'timezone' => $tz,
            'today' => $today,
            'range' => ['from' => $from, 'to' => $to],
            'kpis' => [
                'active_members' => $members->count(),
                'telegram_linked' => $linked,
                'telegram_link_rate' => $members->count() > 0 ? round(($linked / $members->count()) * 100) : 0,
                'plans_submitted_today' => $plansSubmitted,
                'days_closed_today' => $daysClosed,
                'open_blockers_today' => $blockers,
                'team_completion_today' => $todayRate,
                'team_completion_period' => $rangeRate,
                'tasks_done_today' => $todayDone,
                'tasks_planned_today' => $todayPlanned,
                'tasks_done_period' => $rangeDone,
                'tasks_planned_period' => $rangePlanned,
            ],
            'employees' => $employeePerformance,
            'reason_breakdown' => $reasonBreakdown,
            'daily_trend' => $dailyTrend,
            'needs_attention' => [
                'missing_plans' => $memberRows
                    ->filter(fn ($r) => in_array($r['day']['state'] ?? 'not_started', ['not_started', 'awaiting_plan'], true))
                    ->map(fn ($r) => $r['user'])
                    ->values(),
                'not_closed' => $memberRows
                    ->filter(fn ($r) => ! in_array($r['day']['state'] ?? '', ['closed'], true) && ! empty($r['day']['plan_submitted_at']))
                    ->map(fn ($r) => $r['user'])
                    ->values(),
                'not_linked' => $members->filter(fn (User $u) => ! $u->telegram_chat_id)->map(fn (User $u) => [
                    'id' => $u->id,
                    'employee_id' => $u->employee_id,
                    'name' => $u->name,
                    'role' => $u->role,
                ])->values(),
                'roadblocks' => $openRoadblocks,
            ],
            'directory_count' => [
                'total' => $allActive->count(),
                'managers' => $allActive->where('role', 'manager')->count(),
                'members' => $members->count(),
                'inactive' => User::query()->where('active', false)->count(),
            ],
        ]);
    }

    private function countWorkingDays(string $from, string $to): int
    {
        $tz = AppTime::timezone();
        $cursor = Carbon::parse($from, $tz)->startOfDay();
        $end = Carbon::parse($to, $tz)->startOfDay();
        $count = 0;
        while ($cursor->lte($end)) {
            if (AppTime::isWorkingDay($cursor)) {
                $count++;
            }
            $cursor->addDay();
        }

        return max($count, 1);
    }
}
