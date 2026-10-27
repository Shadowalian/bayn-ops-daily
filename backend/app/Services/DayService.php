<?php

namespace App\Services;

use App\Models\Day;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DayService
{
    public function getOrCreateToday(User $user): Day
    {
        return Day::query()->firstOrCreate(
            ['user_id' => $user->id, 'date' => AppTime::today()],
            ['work_status' => 'working']
        )->fresh('tasks');
    }

    public function submitPlan(User $user): Day
    {
        $day = $this->getOrCreateToday($user);

        if ($day->isPlanLocked()) {
            throw ValidationException::withMessages([
                'plan' => 'Plan is locked. Ask the admin to unlock editing.',
            ]);
        }

        $count = $day->tasks()->where('result', '!=', 'dropped')->count();
        if ($count < 1) {
            throw ValidationException::withMessages([
                'plan' => 'Add at least one task before submitting.',
            ]);
        }

        $day->update([
            'plan_submitted_at' => now('UTC'),
            'edit_unlocked' => false,
        ]);

        return $day->fresh('tasks');
    }

    public function closeDay(User $user): Day
    {
        $day = $this->getOrCreateToday($user);

        if ($day->closed_at) {
            return $day->load('tasks');
        }

        if (! $day->plan_submitted_at) {
            throw ValidationException::withMessages([
                'close' => 'Submit a plan before closing the day.',
            ]);
        }

        $open = $day->tasks()
            ->where('result', '!=', 'dropped')
            ->where('result', 'planned')
            ->exists();

        if ($open) {
            throw ValidationException::withMessages([
                'close' => 'Finish every task (done or not done with a roadblock) before sending.',
            ]);
        }

        $missingRoadblock = $day->tasks()
            ->where('result', 'not_done')
            ->where(function ($q) {
                $q->whereNull('reason_note')->orWhere('reason_note', '');
            })
            ->exists();

        if ($missingRoadblock) {
            throw ValidationException::withMessages([
                'close' => 'Explain the roadblock for every task that is not done.',
            ]);
        }

        $day->update(['closed_at' => now('UTC')]);

        return $day->fresh('tasks');
    }

    public function createTask(User $user, array $data): Task
    {
        $day = isset($data['day_id'])
            ? Day::query()->findOrFail($data['day_id'])
            : $this->getOrCreateToday($user);

        if ($day->user_id !== $user->id && ! $user->isManager()) {
            abort(403, 'Cannot add tasks for another member.');
        }

        if ($day->isPlanLocked() && ! $user->isManager()) {
            throw ValidationException::withMessages([
                'plan' => 'Plan is locked. Ask the admin to allow editing.',
            ]);
        }

        if ($day->closed_at && ! $user->isManager()) {
            throw ValidationException::withMessages([
                'plan' => 'Day is already closed.',
            ]);
        }

        $activeCount = $day->tasks()->where('result', '!=', 'dropped')->count();
        if ($activeCount >= 10) {
            throw ValidationException::withMessages([
                'title' => 'Maximum of 10 tasks per day.',
            ]);
        }

        $position = $day->tasks()->max('position');

        return Task::query()->create([
            'user_id' => $day->user_id,
            'day_id' => $day->id,
            'title' => $data['title'],
            'tier' => $data['tier'] ?? 'must',
            'position' => ($position ?? 0) + 1,
            'effort' => $data['effort'] ?? null,
            'added_after_cutoff' => (bool) $day->plan_submitted_at,
            'weekly_goal_id' => $data['weekly_goal_id'] ?? null,
            'carried_from_task_id' => $data['carried_from_task_id'] ?? null,
            'carry_count' => $data['carry_count'] ?? 0,
        ]);
    }

    public function updateTask(User $actor, Task $task, array $data): Task
    {
        if ($task->user_id !== $actor->id && ! $actor->isManager()) {
            abort(403, 'Cannot edit another member\'s task.');
        }

        $day = $task->day;

        $editingPlanFields = array_intersect(array_keys($data), ['title', 'tier', 'position']);
        if ($editingPlanFields !== [] && $day->isPlanLocked() && ! $actor->isManager()) {
            throw ValidationException::withMessages([
                'plan' => 'Plan is locked. Ask the admin to allow editing.',
            ]);
        }

        if ($day->closed_at && ! $actor->isManager()) {
            throw ValidationException::withMessages([
                'plan' => 'Day is closed.',
            ]);
        }

        if (isset($data['result']) && $data['result'] !== 'done' && $data['result'] !== 'planned') {
            $note = $data['reason_note'] ?? $task->reason_note ?? $data['blocker_note'] ?? null;
            if (empty($data['reason_code'] ?? $task->reason_code) && empty($note)) {
                throw ValidationException::withMessages([
                    'reason_note' => 'Explain the roadblock when a task is not done.',
                ]);
            }
            if (empty($data['reason_code'] ?? $task->reason_code)) {
                $data['reason_code'] = 'blocked';
            }
        }

        $tracked = ['result', 'reason_code', 'reason_note', 'blocked', 'blocker_note', 'title'];
        foreach ($tracked as $field) {
            if (array_key_exists($field, $data) && (string) $task->{$field} !== (string) ($data[$field] ?? '')) {
                \App\Models\AuditLog::record(
                    $actor->id,
                    'task',
                    $task->id,
                    $field,
                    $task->{$field},
                    $data[$field],
                    $data['audit_note'] ?? null
                );
            }
        }

        $task->fill(collect($data)->only([
            'title', 'tier', 'result', 'reason_code', 'reason_note',
            'blocked', 'blocker_note', 'effort', 'weekly_goal_id', 'position',
        ])->all());

        if (($data['result'] ?? null) === 'done') {
            $task->reason_code = null;
            $task->reason_note = null;
            $task->blocked = false;
            $task->blocker_note = null;
        }

        if (($data['result'] ?? null) === 'not_done' && ! empty($data['reason_note'] ?? $task->reason_note)) {
            $task->blocked = true;
            $task->blocker_note = $data['reason_note'] ?? $task->reason_note;
        }

        $task->save();

        return $task->fresh();
    }

    public function carryOver(User $user, Task $task): Task
    {
        if ($task->user_id !== $user->id && ! $user->isManager()) {
            abort(403);
        }

        if ($task->result === 'done') {
            throw ValidationException::withMessages([
                'carry_over' => 'Done tasks cannot be carried over.',
            ]);
        }

        $nextDate = AppTime::nextWorkingDay();
        $nextDay = Day::query()->firstOrCreate(
            ['user_id' => $task->user_id, 'date' => $nextDate->toDateString()],
            ['work_status' => 'working']
        );

        $exists = Task::query()
            ->where('day_id', $nextDay->id)
            ->where('carried_from_task_id', $task->id)
            ->first();

        if ($exists) {
            return $exists;
        }

        $position = $nextDay->tasks()->max('position');

        return Task::query()->create([
            'user_id' => $task->user_id,
            'day_id' => $nextDay->id,
            'title' => $task->title,
            'tier' => 'must',
            'position' => ($position ?? 0) + 1,
            'effort' => $task->effort,
            'added_after_cutoff' => false,
            'carried_from_task_id' => $task->id,
            'carry_count' => $task->carry_count + 1,
            'weekly_goal_id' => $task->weekly_goal_id,
        ]);
    }

    public function teamToday(?string $date = null): array
    {
        $date ??= AppTime::today();
        $users = User::query()->where('active', true)->orderBy('name')->get();

        return $users->map(function (User $user) use ($date) {
            $day = Day::query()
                ->with('tasks')
                ->where('user_id', $user->id)
                ->whereDate('date', $date)
                ->first();

            $tasks = ($day?->tasks ?? collect())->where('result', '!=', 'dropped');
            $done = $tasks->where('result', 'done')->count();
            $planned = $tasks->count();
            $blockers = $tasks->where('blocked', true)->values();

            return [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                    'telegram_chat_id' => $user->telegram_chat_id,
                ],
                'day' => $day ? [
                    'id' => $day->id,
                    'date' => $day->date->toDateString(),
                    'state' => $day->state(),
                    'edit_unlocked' => (bool) $day->edit_unlocked,
                    'plan_submitted_at' => AppTime::toLocalIso($day->plan_submitted_at),
                    'closed_at' => AppTime::toLocalIso($day->closed_at),
                    'morning_prompted_at' => AppTime::toLocalIso($day->morning_prompted_at),
                    'evening_prompted_at' => AppTime::toLocalIso($day->evening_prompted_at),
                ] : null,
                'must_done' => $done,
                'must_planned' => $planned,
                'must_completion' => $planned > 0 ? round(($done / $planned) * 100) : null,
                'overall_done' => $done,
                'overall_planned' => $planned,
                'overall_completion' => $planned > 0 ? round(($done / $planned) * 100) : null,
                'blockers' => $blockers->map(fn (Task $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'blocker_note' => $t->blocker_note,
                ])->values(),
                'not_done_reasons' => $tasks
                    ->whereIn('result', ['partial', 'not_done', 'dropped'])
                    ->map(fn (Task $t) => [
                        'task_id' => $t->id,
                        'title' => $t->title,
                        'result' => $t->result,
                        'reason_code' => $t->reason_code,
                        'reason_note' => $t->reason_note,
                        'carry_count' => $t->carry_count,
                    ])->values(),
                'tasks' => $tasks->values(),
            ];
        })->values()->all();
    }
}
