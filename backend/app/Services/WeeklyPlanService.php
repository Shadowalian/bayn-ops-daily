<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyGoal;
use App\Models\WeeklyPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WeeklyPlanService
{
    public const MAX_ITEMS = 12;

    public function __construct(
        private DayService $days,
        private WeeklyPlanTable $table,
    ) {}

    public function enabled(): bool
    {
        return AppTime::weeklyPlansEnabled();
    }

    public function currentPlan(User $user, ?string $weekStart = null): ?WeeklyPlan
    {
        $weekStart ??= AppTime::weekStart();

        return WeeklyPlan::query()
            ->where('user_id', $user->id)
            ->whereDate('week_start', $weekStart)
            ->with(['goals' => fn ($q) => $q->orderBy('position')])
            ->first();
    }

    public function getOrCreatePlan(User $user, ?string $weekStart = null): WeeklyPlan
    {
        $weekStart ??= AppTime::weekStart();

        return WeeklyPlan::query()->firstOrCreate(
            ['user_id' => $user->id, 'week_start' => $weekStart],
            ['status' => WeeklyPlan::STATUS_PENDING]
        );
    }

    /**
     * @param  list<string>  $titles
     */
    public function submitPlan(User $user, array $titles, ?string $weekStart = null): WeeklyPlan
    {
        $weekStart ??= AppTime::weekStart();
        $titles = array_values(array_filter(array_map(fn ($t) => mb_substr(trim($t), 0, 200), $titles)));

        if ($titles === []) {
            throw ValidationException::withMessages(['titles' => 'Send at least one checkpoint.']);
        }

        return DB::transaction(function () use ($user, $titles, $weekStart) {
            $plan = WeeklyPlan::query()->lockForUpdate()->firstOrCreate(
                ['user_id' => $user->id, 'week_start' => $weekStart],
                ['status' => WeeklyPlan::STATUS_PENDING]
            );

            $liveAdmin = $plan->goals()
                ->where('source', 'admin')
                ->whereIn('status', WeeklyGoal::LIVE_STATUSES)
                ->count();

            if ($liveAdmin + count($titles) > self::MAX_ITEMS) {
                throw ValidationException::withMessages([
                    'titles' => 'Keep the week to '.self::MAX_ITEMS.' checkpoints or fewer (including admin items).',
                ]);
            }

            $plan->goals()
                ->where('source', 'self')
                ->whereIn('status', [WeeklyGoal::STATUS_PENDING, WeeklyGoal::STATUS_REJECTED])
                ->delete();

            $position = (int) ($plan->goals()->max('position') ?? 0);
            foreach ($titles as $title) {
                $position++;
                WeeklyGoal::query()->create([
                    'weekly_plan_id' => $plan->id,
                    'title' => $title,
                    'owner_id' => $user->id,
                    'week_start' => $weekStart,
                    'status' => WeeklyGoal::STATUS_PENDING,
                    'source' => 'self',
                    'position' => $position,
                ]);
            }

            $plan->update([
                'status' => WeeklyPlan::STATUS_PENDING,
                'submitted_at' => now('UTC'),
                'rejection_reason' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ]);

            AuditLog::record($user->id, 'weekly_plan', $plan->id, 'submitted', null, (string) count($titles), 'Weekly plan submitted');

            return $plan->fresh(['goals', 'user']);
        });
    }

    /**
     * @param  list<string>  $titles
     */
    public function requestAdd(User $user, array $titles, ?string $weekStart = null): WeeklyPlan
    {
        $plan = $this->currentPlan($user, $weekStart);
        if (! $plan || ! $plan->isApproved()) {
            throw ValidationException::withMessages(['plan' => 'Your week must be approved before adding more work.']);
        }

        $titles = array_values(array_filter(array_map(fn ($t) => mb_substr(trim($t), 0, 200), $titles)));
        if ($titles === []) {
            throw ValidationException::withMessages(['titles' => 'Send at least one checkpoint to add.']);
        }

        return DB::transaction(function () use ($plan, $user, $titles) {
            $plan = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $live = $plan->goals()->whereIn('status', WeeklyGoal::LIVE_STATUSES)->count();
            if ($live + count($titles) > self::MAX_ITEMS) {
                throw ValidationException::withMessages(['titles' => 'Week already has '.self::MAX_ITEMS.' live checkpoints.']);
            }

            $position = (int) ($plan->goals()->max('position') ?? 0);
            foreach ($titles as $title) {
                $position++;
                WeeklyGoal::query()->create([
                    'weekly_plan_id' => $plan->id,
                    'title' => $title,
                    'owner_id' => $user->id,
                    'week_start' => $plan->week_start->toDateString(),
                    'status' => WeeklyGoal::STATUS_PENDING,
                    'source' => 'self',
                    'position' => $position,
                ]);
            }

            AuditLog::record($user->id, 'weekly_plan', $plan->id, 'add_requested', null, (string) count($titles), 'Mid-week add requested');

            return $plan->fresh(['goals', 'user']);
        });
    }

    /**
     * @param  list<int>  $goalIds
     */
    public function requestRemoval(User $user, array $goalIds, ?string $note = null): WeeklyPlan
    {
        $plan = $this->currentPlan($user);
        if (! $plan || ! $plan->isApproved()) {
            throw ValidationException::withMessages(['plan' => 'No approved week to remove from.']);
        }

        return DB::transaction(function () use ($plan, $user, $goalIds, $note) {
            $plan = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $goals = $plan->goals()
                ->whereIn('id', $goalIds)
                ->whereIn('status', [WeeklyGoal::STATUS_APPROVED, WeeklyGoal::STATUS_REMOVE_REQUESTED])
                ->get();

            foreach ($goals as $goal) {
                $goal->update([
                    'status' => WeeklyGoal::STATUS_REMOVE_REQUESTED,
                    'remove_request_note' => $note ? mb_substr($note, 0, 500) : $goal->remove_request_note,
                ]);
                AuditLog::record($user->id, 'weekly_goal', $goal->id, 'remove_requested', WeeklyGoal::STATUS_APPROVED, WeeklyGoal::STATUS_REMOVE_REQUESTED, $note);
            }

            return $plan->fresh(['goals', 'user']);
        });
    }

    public function approvePlan(WeeklyPlan $plan, User $manager): WeeklyPlan
    {
        return DB::transaction(function () use ($plan, $manager) {
            $plan = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $old = $plan->status;

            // Pending adds + items from a revoked/rejected week become live again.
            $plan->goals()
                ->whereIn('status', [WeeklyGoal::STATUS_PENDING, WeeklyGoal::STATUS_REJECTED])
                ->update(['status' => WeeklyGoal::STATUS_APPROVED, 'review_note' => null]);

            $plan->update([
                'status' => WeeklyPlan::STATUS_APPROVED,
                'reviewed_at' => now('UTC'),
                'reviewed_by' => $manager->id,
                'rejection_reason' => null,
            ]);

            AuditLog::record($manager->id, 'weekly_plan', $plan->id, 'approved', $old, WeeklyPlan::STATUS_APPROVED, 'Week approved');

            $fresh = $plan->fresh(['goals', 'user']);
            $this->materializeAdminGoalsForToday($fresh->user, $fresh);

            return $fresh;
        });
    }

    public function rejectPlan(WeeklyPlan $plan, User $manager, string $reason): WeeklyPlan
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Give a clear rejection reason (at least 3 characters).']);
        }

        return DB::transaction(function () use ($plan, $manager, $reason) {
            $plan = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $old = $plan->status;

            $liveGoals = $plan->goals()
                ->whereIn('status', WeeklyGoal::LIVE_STATUSES)
                ->get();

            foreach ($liveGoals as $goal) {
                $goal->update([
                    'status' => WeeklyGoal::STATUS_REJECTED,
                    'review_note' => mb_substr($reason, 0, 500),
                    'remove_request_note' => null,
                ]);
                $this->dropPlannedTasksForGoal($goal);
            }

            $plan->update([
                'status' => WeeklyPlan::STATUS_REJECTED,
                'reviewed_at' => now('UTC'),
                'reviewed_by' => $manager->id,
                'rejection_reason' => mb_substr($reason, 0, 1000),
            ]);

            AuditLog::record($manager->id, 'weekly_plan', $plan->id, 'rejected', $old, WeeklyPlan::STATUS_REJECTED, $reason);

            return $plan->fresh(['goals', 'user']);
        });
    }

    public function updateGoalTitle(WeeklyGoal $goal, User $manager, string $title): WeeklyGoal
    {
        $title = mb_substr(trim($title), 0, 200);
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Title required.']);
        }

        return DB::transaction(function () use ($goal, $manager, $title) {
            $goal = WeeklyGoal::query()->lockForUpdate()->findOrFail($goal->id);
            if (in_array($goal->status, [WeeklyGoal::STATUS_REMOVED, WeeklyGoal::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['goal' => 'Cannot edit a removed or rejected item. Re-add it instead.']);
            }

            $old = $goal->title;
            $goal->update(['title' => $title]);

            // Keep today's open tasks in sync
            Task::query()
                ->where('weekly_goal_id', $goal->id)
                ->whereIn('result', ['planned', 'partial'])
                ->update(['title' => $title]);

            AuditLog::record($manager->id, 'weekly_goal', $goal->id, 'title', $old, $title, 'Admin edited checkpoint');

            return $goal->fresh();
        });
    }

    public function approveGoal(WeeklyGoal $goal, User $manager): WeeklyGoal
    {
        return DB::transaction(function () use ($goal, $manager) {
            $goal = WeeklyGoal::query()->lockForUpdate()->findOrFail($goal->id);
            if ($goal->status !== WeeklyGoal::STATUS_PENDING) {
                throw ValidationException::withMessages(['goal' => 'Only pending items can be approved.']);
            }

            $goal->update(['status' => WeeklyGoal::STATUS_APPROVED, 'review_note' => null]);
            AuditLog::record($manager->id, 'weekly_goal', $goal->id, 'approved', WeeklyGoal::STATUS_PENDING, WeeklyGoal::STATUS_APPROVED, null);

            $owner = $goal->owner;
            $this->materializeGoalOnOpenDay($owner, $goal->fresh());

            return $goal->fresh();
        });
    }

    public function rejectGoal(WeeklyGoal $goal, User $manager, string $reason): WeeklyGoal
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Reason required (min 3 characters).']);
        }

        return DB::transaction(function () use ($goal, $manager, $reason) {
            $goal = WeeklyGoal::query()->lockForUpdate()->findOrFail($goal->id);
            if (! in_array($goal->status, WeeklyGoal::LIVE_STATUSES, true)) {
                throw ValidationException::withMessages(['goal' => 'That item is already closed.']);
            }

            $old = $goal->status;
            $goal->update([
                'status' => WeeklyGoal::STATUS_REJECTED,
                'review_note' => mb_substr($reason, 0, 500),
                'remove_request_note' => null,
            ]);
            $this->dropPlannedTasksForGoal($goal);
            AuditLog::record($manager->id, 'weekly_goal', $goal->id, 'rejected', $old, WeeklyGoal::STATUS_REJECTED, $reason);

            return $goal->fresh();
        });
    }

    public function approveRemoval(WeeklyGoal $goal, User $manager): WeeklyGoal
    {
        return DB::transaction(function () use ($goal, $manager) {
            $goal = WeeklyGoal::query()->lockForUpdate()->findOrFail($goal->id);
            if ($goal->status !== WeeklyGoal::STATUS_REMOVE_REQUESTED) {
                throw ValidationException::withMessages(['goal' => 'No removal request on this item.']);
            }

            $goal->update(['status' => WeeklyGoal::STATUS_REMOVED]);
            AuditLog::record($manager->id, 'weekly_goal', $goal->id, 'removed', WeeklyGoal::STATUS_REMOVE_REQUESTED, WeeklyGoal::STATUS_REMOVED, 'Removal approved');

            $this->dropPlannedTasksForGoal($goal);

            return $goal->fresh();
        });
    }

    public function denyRemoval(WeeklyGoal $goal, User $manager, string $reason): WeeklyGoal
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Reason required (min 3 characters).']);
        }

        return DB::transaction(function () use ($goal, $manager, $reason) {
            $goal = WeeklyGoal::query()->lockForUpdate()->findOrFail($goal->id);
            if ($goal->status !== WeeklyGoal::STATUS_REMOVE_REQUESTED) {
                throw ValidationException::withMessages(['goal' => 'No removal request on this item.']);
            }

            $goal->update([
                'status' => WeeklyGoal::STATUS_APPROVED,
                'review_note' => mb_substr($reason, 0, 500),
                'remove_request_note' => null,
            ]);
            AuditLog::record($manager->id, 'weekly_goal', $goal->id, 'removal_denied', WeeklyGoal::STATUS_REMOVE_REQUESTED, WeeklyGoal::STATUS_APPROVED, $reason);

            return $goal->fresh();
        });
    }

    public function removeGoal(WeeklyGoal $goal, User $manager, ?string $note = null): WeeklyGoal
    {
        return DB::transaction(function () use ($goal, $manager, $note) {
            $goal = WeeklyGoal::query()->lockForUpdate()->findOrFail($goal->id);
            $old = $goal->status;
            $goal->update([
                'status' => WeeklyGoal::STATUS_REMOVED,
                'review_note' => $note ? mb_substr($note, 0, 500) : $goal->review_note,
            ]);
            AuditLog::record($manager->id, 'weekly_goal', $goal->id, 'removed', $old, WeeklyGoal::STATUS_REMOVED, $note);
            $this->dropPlannedTasksForGoal($goal);

            return $goal->fresh();
        });
    }

    public function assignToWeek(User $employee, string $title, User $manager): array
    {
        $title = mb_substr(trim($title), 0, 200);
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Title required.']);
        }

        return DB::transaction(function () use ($employee, $title, $manager) {
            $weekStart = AppTime::weekStart();
            $plan = WeeklyPlan::query()->lockForUpdate()->firstOrCreate(
                ['user_id' => $employee->id, 'week_start' => $weekStart],
                ['status' => WeeklyPlan::STATUS_APPROVED, 'submitted_at' => now('UTC'), 'reviewed_at' => now('UTC'), 'reviewed_by' => $manager->id]
            );

            // Admin assign does not flip pending/rejected plan status
            $live = $plan->goals()->whereIn('status', WeeklyGoal::LIVE_STATUSES)->count();
            if ($live >= self::MAX_ITEMS) {
                throw ValidationException::withMessages(['title' => 'Week already has '.self::MAX_ITEMS.' checkpoints.']);
            }

            $position = (int) ($plan->goals()->max('position') ?? 0) + 1;
            $goal = WeeklyGoal::query()->create([
                'weekly_plan_id' => $plan->id,
                'title' => $title,
                'owner_id' => $employee->id,
                'week_start' => $weekStart,
                'status' => WeeklyGoal::STATUS_APPROVED,
                'source' => 'admin',
                'assigned_by' => $manager->id,
                'position' => $position,
            ]);

            AuditLog::record($manager->id, 'weekly_goal', $goal->id, 'assigned', null, $title, 'Admin assigned to week');

            $task = $this->materializeGoalOnOpenDay($employee, $goal);

            return ['plan' => $plan->fresh(['goals']), 'goal' => $goal, 'task' => $task];
        });
    }

    /** Goals that appear on the daily table. */
    public function tableGoals(?WeeklyPlan $plan): Collection
    {
        if (! $plan) {
            return collect();
        }

        return $plan->goals
            ->filter(fn (WeeklyGoal $g) => $g->isOnTable())
            ->values();
    }

    /**
     * Lock today's focus: selected goal ids (+ always include admin table goals).
     *
     * @param  list<int>  $selectedGoalIds
     */
    public function lockTodayFocus(User $user, array $selectedGoalIds): array
    {
        $plan = $this->currentPlan($user);
        if (! $plan || ! $plan->isApproved()) {
            throw ValidationException::withMessages(['plan' => 'No approved weekly plan.']);
        }

        $tableGoals = $this->tableGoals($plan);
        $adminIds = $tableGoals->where('source', 'admin')->pluck('id')->all();
        $allowed = $tableGoals->pluck('id')->all();
        $selected = array_values(array_unique(array_merge(
            array_intersect($selectedGoalIds, $allowed),
            $adminIds
        )));

        if ($selected === []) {
            throw ValidationException::withMessages(['focus' => 'Pick at least one checkpoint for today.']);
        }

        if (count($selected) > 10) {
            throw ValidationException::withMessages(['focus' => 'Pick 10 checkpoints or fewer for today.']);
        }

        $day = $this->days->getOrCreateToday($user);

        return DB::transaction(function () use ($user, $day, $plan, $tableGoals, $selected) {
            $byId = $tableGoals->keyBy('id');

            // Drop self planned tasks for goals not selected
            $day->tasks()
                ->where('result', 'planned')
                ->where(function ($q) {
                    $q->where('source', 'self')->orWhereNull('source');
                })
                ->whereNotNull('weekly_goal_id')
                ->whereNotIn('weekly_goal_id', $selected)
                ->update([
                    'result' => 'dropped',
                    'reason_code' => 'other',
                    'reason_note' => 'Not selected for today',
                ]);

            $position = (int) ($day->tasks()->max('position') ?? 0);
            foreach ($selected as $goalId) {
                $goal = $byId->get($goalId);
                if (! $goal) {
                    continue;
                }
                $exists = $day->tasks()
                    ->where('weekly_goal_id', $goal->id)
                    ->where('result', '!=', 'dropped')
                    ->exists();
                if ($exists) {
                    continue;
                }
                $position++;
                Task::query()->create([
                    'user_id' => $user->id,
                    'day_id' => $day->id,
                    'title' => $goal->title,
                    'tier' => 'must',
                    'source' => $goal->source === 'admin' ? 'admin' : 'self',
                    'assigned_by' => $goal->assigned_by,
                    'position' => $position,
                    'result' => 'planned',
                    'weekly_goal_id' => $goal->id,
                    'added_after_cutoff' => (bool) $day->plan_submitted_at,
                ]);
            }

            $day->update([
                'plan_submitted_at' => now('UTC'),
                'edit_unlocked' => false,
            ]);

            return [
                'day' => $day->fresh('tasks'),
                'plan' => $plan->fresh('goals'),
            ];
        });
    }

    public function renderTable(WeeklyPlan $plan, ?User $user = null, bool $forEvening = false): string
    {
        $user ??= $plan->user;
        $goals = $this->tableGoals($plan->loadMissing('goals'));
        $marks = $this->table->marksFor($goals, $plan->week_start->toDateString());
        $todayByGoal = [];

        $day = $this->days->getOrCreateToday($user);
        $day->load('tasks');
        foreach ($goals as $goal) {
            $task = $day->tasks->first(fn (Task $t) => $t->weekly_goal_id === $goal->id && $t->result !== 'dropped');
            if (! $day->plan_submitted_at) {
                $todayByGoal[$goal->id] = '·';
            } elseif (! $task) {
                $todayByGoal[$goal->id] = '–';
            } else {
                $todayByGoal[$goal->id] = match ($task->result) {
                    'done' => '✓',
                    'not_done' => '✗',
                    'partial' => '~',
                    default => '·',
                };
            }
        }

        $caption = $forEvening ? 'Mark what you finished today.' : null;

        return $this->table->render($plan, $goals, $marks, $todayByGoal, $caption);
    }

    public function payload(WeeklyPlan $plan): array
    {
        $plan->loadMissing(['user', 'goals', 'reviewer']);
        $goals = $plan->goals->sortBy('position')->values();
        $marks = $this->table->marksFor($goals, $plan->week_start->toDateString());

        return [
            'id' => $plan->id,
            'user_id' => $plan->user_id,
            'employee_id' => $plan->user?->employee_id,
            'name' => $plan->user?->name,
            'week_start' => $plan->week_start->toDateString(),
            'status' => $plan->status,
            'submitted_at' => AppTime::toLocalIso($plan->submitted_at),
            'reviewed_at' => AppTime::toLocalIso($plan->reviewed_at),
            'rejection_reason' => $plan->rejection_reason,
            'goals' => $goals->map(fn (WeeklyGoal $g) => [
                'id' => $g->id,
                'title' => $g->title,
                'status' => $g->status,
                'source' => $g->source,
                'position' => $g->position,
                'review_note' => $g->review_note,
                'remove_request_note' => $g->remove_request_note,
                'week_marks' => $marks[$g->id] ?? null,
            ])->all(),
        ];
    }

    private function materializeAdminGoalsForToday(User $user, WeeklyPlan $plan): void
    {
        foreach ($this->tableGoals($plan)->where('source', 'admin') as $goal) {
            $this->materializeGoalOnOpenDay($user, $goal);
        }
    }

    private function materializeGoalOnOpenDay(User $user, WeeklyGoal $goal): ?Task
    {
        if (! $goal->isOnTable()) {
            return null;
        }

        $day = $this->days->getOrCreateToday($user);
        if ($day->closed_at) {
            return null;
        }

        $existing = $day->tasks()
            ->where('weekly_goal_id', $goal->id)
            ->where('result', '!=', 'dropped')
            ->first();
        if ($existing) {
            return $existing;
        }

        // Only auto-add admin goals when day already locked, or always for admin
        if ($goal->source !== 'admin' && ! $day->plan_submitted_at) {
            return null;
        }

        if ($goal->source !== 'admin') {
            return null;
        }

        $position = (int) ($day->tasks()->max('position') ?? 0) + 1;

        return Task::query()->create([
            'user_id' => $user->id,
            'day_id' => $day->id,
            'title' => $goal->title,
            'tier' => 'must',
            'source' => 'admin',
            'assigned_by' => $goal->assigned_by,
            'position' => $position,
            'result' => 'planned',
            'weekly_goal_id' => $goal->id,
            'added_after_cutoff' => (bool) $day->plan_submitted_at,
        ]);
    }

    private function dropPlannedTasksForGoal(WeeklyGoal $goal): void
    {
        Task::query()
            ->where('weekly_goal_id', $goal->id)
            ->where('result', 'planned')
            ->update([
                'result' => 'dropped',
                'reason_code' => 'other',
                'reason_note' => 'Removed from weekly plan',
            ]);
    }
}
