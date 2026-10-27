<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\DayService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(private DayService $days) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'tier' => ['nullable', Rule::in(['must', 'support'])],
            'effort' => ['nullable', Rule::in(['S', 'M', 'L'])],
            'weekly_goal_id' => ['nullable', 'integer', 'exists:weekly_goals,id'],
            'day_id' => ['nullable', 'integer', 'exists:days,id'],
        ]);

        $task = $this->days->createTask($request->user(), $data);

        return response()->json($task, 201);
    }

    public function update(Request $request, Task $task)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'tier' => ['sometimes', Rule::in(['must', 'support'])],
            'result' => ['sometimes', Rule::in(['planned', 'done', 'partial', 'not_done', 'dropped'])],
            'reason_code' => ['nullable', Rule::in(Task::REASON_CODES)],
            'reason_note' => ['nullable', 'string', 'max:500'],
            'blocked' => ['sometimes', 'boolean'],
            'blocker_note' => ['nullable', 'string', 'max:500'],
            'effort' => ['nullable', Rule::in(['S', 'M', 'L'])],
            'weekly_goal_id' => ['nullable', 'integer', 'exists:weekly_goals,id'],
            'position' => ['sometimes', 'integer', 'min:1'],
            'audit_note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($request->user()->isManager() && $request->user()->id !== $task->user_id) {
            $request->validate(['audit_note' => ['required', 'string', 'max:500']]);
        }

        $task = $this->days->updateTask($request->user(), $task, $data);

        return response()->json($task);
    }

    public function carryOver(Request $request, Task $task)
    {
        $newTask = $this->days->carryOver($request->user(), $task);

        return response()->json($newTask, 201);
    }
}
