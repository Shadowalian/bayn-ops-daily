<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\AppTime;
use App\Services\DayService;
use App\Services\TelegramClient;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminAssignController extends Controller
{
    public function store(Request $request, DayService $days, TelegramClient $telegram)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:200'],
            'date' => ['sometimes', 'date'],
        ]);

        $employee = User::query()->findOrFail($data['user_id']);
        if (! $employee->active) {
            throw ValidationException::withMessages([
                'user_id' => 'Cannot assign work to an inactive employee.',
            ]);
        }

        $date = $data['date'] ?? AppTime::today();
        $day = $days->getOrCreateForDate($employee, $date);

        $activeCount = $day->tasks()->where('result', '!=', 'dropped')->count();
        if ($activeCount >= 15) {
            throw ValidationException::withMessages([
                'title' => 'This person already has 15 tasks for that day.',
            ]);
        }

        $position = (int) ($day->tasks()->max('position') ?? 0) + 1;
        $task = Task::query()->create([
            'user_id' => $employee->id,
            'day_id' => $day->id,
            'title' => trim($data['title']),
            'tier' => 'must',
            'source' => 'admin',
            'assigned_by' => $request->user()->id,
            'position' => $position,
            'result' => 'planned',
            'added_after_cutoff' => (bool) $day->plan_submitted_at,
        ]);

        AuditLog::record(
            $request->user()->id,
            'task',
            $task->id,
            'assigned',
            null,
            $task->title,
            "Admin assigned to {$employee->employee_id}"
        );

        if ($employee->telegram_chat_id) {
            $telegram->sendMessage(
                $employee->telegram_chat_id,
                "📌 Admin assigned you a task for <b>{$date}</b>:\n"
                ."<b>{$task->title}</b>\n\n"
                .'It is added to your day alongside your own tasks.'
            );
        }

        return response()->json([
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'source' => $task->source,
                'user_id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'name' => $employee->name,
                'date' => $date,
            ],
            'message' => "Assigned to {$employee->name} ({$employee->employee_id})",
        ], 201);
    }
}
