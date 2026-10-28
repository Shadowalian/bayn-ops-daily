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
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __construct(private DayService $days) {}

    public function show(Request $request)
    {
        $tz = AppTime::timezone();
        $today = AppTime::today();
        $from = $request->query('from', Carbon::parse($today, $tz)->subDays(6)->toDateString());
        $to = $request->query('to', $today);

        $allActive = User::query()->where('active', true)->orderBy('employee_id')->get();
        $members = $allActive->where('role', 'member')->values();
        $inactiveCount = User::query()->where('active', false)->count();

        $teamToday = $this->days->teamToday($today, false);
        $teamById = collect($teamToday)->keyBy(fn ($r) => $r['user']['id']);
        $memberRows = collect($teamToday)->filter(fn ($r) => ($r['user']['role'] ?? 'member') === 'member')->values();

        $plansSubmitted = $memberRows->filter(fn ($r) => ! empty($r['day']['plan_submitted_at']))->count();
        $daysClosedToday = $memberRows->filter(fn ($r) => ($r['day']['state'] ?? '') === 'closed')->count();
        $blockers = $memberRows->sum(fn ($r) => count($r['blockers'] ?? []));
        $linked = $members->filter(fn (User $u) => (bool) $u->telegram_chat_id)->count();

        $todayDone = $memberRows->sum('overall_done');
        $todayPlanned = $memberRows->sum('overall_planned');
        $todayRate = $todayPlanned > 0 ? round(($todayDone / $todayPlanned) * 100) : null;

        $memberIds = $members->pluck('id');

        $rangeTasks = Task::query()
            ->select(['tasks.id', 'tasks.user_id', 'tasks.day_id', 'tasks.result', 'tasks.reason_code', 'tasks.carry_count', 'tasks.blocked', 'tasks.title', 'tasks.blocker_note', 'days.date as day_date'])
            ->join('days', 'days.id', '=', 'tasks.day_id')
            ->whereIn('tasks.user_id', $memberIds)
            ->where('tasks.result', '!=', 'dropped')
            ->whereBetween('days.date', [$from, $to])
            ->get();

        $rangeDone = $rangeTasks->where('result', 'done')->count();
        $rangePlanned = $rangeTasks->count();
        $rangeRate = $rangePlanned > 0 ? round(($rangeDone / $rangePlanned) * 100) : null;

        $reasonBreakdown = $rangeTasks
            ->whereIn('result', ['not_done', 'partial'])
            ->groupBy(fn ($t) => $t->reason_code ?: 'unspecified')
            ->map(fn ($group, $code) => [
                'reason_code' => $code,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values();

        $tasksByUser = $rangeTasks->groupBy('user_id');

        $dayStats = Day::query()
            ->select([
                'user_id',
                DB::raw('SUM(CASE WHEN plan_submitted_at IS NOT NULL THEN 1 ELSE 0 END) as plans_submitted'),
                DB::raw('SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) as days_closed'),
            ])
            ->whereIn('user_id', $memberIds)
            ->whereBetween('date', [$from, $to])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $workingDaysInRange = $this->countWorkingDays($from, $to);

        $employeePerformance = $members->map(function (User $user) use ($from, $to, $teamById, $tasksByUser, $dayStats, $workingDaysInRange) {
            $todayRow = $teamById->get($user->id);
            $tasks = $tasksByUser->get($user->id, collect());
            $done = $tasks->where('result', 'done')->count();
            $planned = $tasks->count();
            $notDone = $tasks->whereIn('result', ['not_done', 'partial'])->count();
            $carryHeavy = $tasks->where('carry_count', '>=', 2)->count();
            $stats = $dayStats->get($user->id);
            $daysWithPlan = (int) ($stats->plans_submitted ?? 0);
            $daysClosed = (int) ($stats->days_closed ?? 0);

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
                    'completion' => $todayRow['overall_completion'] ?? null,
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

        $tasksByDate = $rangeTasks->groupBy(fn ($t) => Carbon::parse($t->day_date)->toDateString());
        $dailyTrend = [];
        $cursor = Carbon::parse($from, $tz)->startOfDay();
        $end = Carbon::parse($to, $tz)->startOfDay();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $dayTasks = $tasksByDate->get($date, collect());
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
                'days_closed_today' => $daysClosedToday,
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
                'inactive' => $inactiveCount,
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
