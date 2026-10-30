<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WeeklyGoal;
use App\Models\WeeklyPlan;
use App\Services\AppTime;
use App\Services\TelegramClient;
use App\Services\WeeklyPlanService;
use App\Services\WeeklyPlanTable;
use Illuminate\Http\Request;

class AdminWeeklyPlanController extends Controller
{
    public function index(Request $request, WeeklyPlanService $plans)
    {
        $weekStart = $request->query('week_start') ?: AppTime::weekStart();

        $rows = WeeklyPlan::query()
            ->whereDate('week_start', $weekStart)
            ->whereHas('user', fn ($q) => $q->where('active', true))
            ->with(['user', 'goals'])
            ->orderByDesc('submitted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WeeklyPlan $p) => $plans->payload($p));

        $pendingCount = collect($rows)->filter(function (array $p) {
            if (($p['status'] ?? '') === 'pending') {
                return true;
            }

            return collect($p['goals'] ?? [])->contains(fn ($g) => in_array($g['status'] ?? '', ['pending', 'remove_requested'], true));
        })->count();

        return response()->json([
            'week_start' => $weekStart,
            'pending_count' => $pendingCount,
            'plans' => $rows,
        ]);
    }

    public function approve(Request $request, WeeklyPlan $weekly_plan, WeeklyPlanService $plans, TelegramClient $telegram, WeeklyPlanTable $table)
    {
        $plan = $plans->approvePlan($weekly_plan, $request->user());
        $this->notifyEmployee(
            $telegram,
            $plan,
            "Your weekly plan is <b>approved</b>.\n\n".$plans->renderTable($plan)."\n\n"
            .'Each working morning you will choose which of these you are pushing today.'
        );

        return response()->json($plans->payload($plan));
    }

    public function reject(Request $request, WeeklyPlan $weekly_plan, WeeklyPlanService $plans, TelegramClient $telegram)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $plan = $plans->rejectPlan($weekly_plan, $request->user(), $data['reason']);
        $this->notifyEmployee(
            $telegram,
            $plan,
            "Your weekly plan was <b>not approved</b>.\n"
            .'Reason: '.e($plan->rejection_reason)."\n\n"
            .'Send a revised list with /week.'
        );

        return response()->json($plans->payload($plan));
    }

    public function approveGoal(Request $request, WeeklyGoal $weekly_goal, WeeklyPlanService $plans, TelegramClient $telegram)
    {
        $goal = $plans->approveGoal($weekly_goal, $request->user());
        $plan = $goal->plan?->load('user') ?? WeeklyPlan::query()->find($goal->weekly_plan_id);
        if ($plan?->user?->telegram_chat_id) {
            $telegram->sendMessage(
                $plan->user->telegram_chat_id,
                'Added to your week: <b>'.e($goal->title)."</b>\n\n".$plans->renderTable($plan->fresh('goals'))
            );
        }

        return response()->json(['goal' => $this->goalPayload($goal), 'plan' => $plan ? $plans->payload($plan->fresh('goals')) : null]);
    }

    public function rejectGoal(Request $request, WeeklyGoal $weekly_goal, WeeklyPlanService $plans, TelegramClient $telegram)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $goal = $plans->rejectGoal($weekly_goal, $request->user(), $data['reason']);
        $owner = User::query()->find($goal->owner_id);
        if ($owner?->telegram_chat_id) {
            $telegram->sendMessage(
                $owner->telegram_chat_id,
                'Not added: <b>'.e($goal->title)."</b>\nReason: ".e($goal->review_note)
            );
        }

        return response()->json(['goal' => $this->goalPayload($goal)]);
    }

    public function approveRemoval(Request $request, WeeklyGoal $weekly_goal, WeeklyPlanService $plans, TelegramClient $telegram)
    {
        $goal = $plans->approveRemoval($weekly_goal, $request->user());
        $owner = User::query()->find($goal->owner_id);
        if ($owner?->telegram_chat_id) {
            $telegram->sendMessage($owner->telegram_chat_id, 'Removed from your week: <b>'.e($goal->title).'</b>');
        }

        return response()->json(['goal' => $this->goalPayload($goal)]);
    }

    public function denyRemoval(Request $request, WeeklyGoal $weekly_goal, WeeklyPlanService $plans, TelegramClient $telegram)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $goal = $plans->denyRemoval($weekly_goal, $request->user(), $data['reason']);
        $owner = User::query()->find($goal->owner_id);
        if ($owner?->telegram_chat_id) {
            $telegram->sendMessage(
                $owner->telegram_chat_id,
                'Still on your week: <b>'.e($goal->title)."</b>\nReason: ".e($goal->review_note)
            );
        }

        return response()->json(['goal' => $this->goalPayload($goal)]);
    }

    public function remove(Request $request, WeeklyGoal $weekly_goal, WeeklyPlanService $plans, TelegramClient $telegram)
    {
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $goal = $plans->removeGoal($weekly_goal, $request->user(), $data['note'] ?? null);
        $owner = User::query()->find($goal->owner_id);
        if ($owner?->telegram_chat_id) {
            $telegram->sendMessage($owner->telegram_chat_id, 'Removed from your week: <b>'.e($goal->title).'</b>');
        }

        return response()->json(['goal' => $this->goalPayload($goal)]);
    }

    private function notifyEmployee(TelegramClient $telegram, WeeklyPlan $plan, string $text): void
    {
        $plan->loadMissing('user');
        if ($plan->user?->telegram_chat_id) {
            $telegram->sendMessage($plan->user->telegram_chat_id, $text);
        }
    }

    private function goalPayload(WeeklyGoal $goal): array
    {
        return [
            'id' => $goal->id,
            'title' => $goal->title,
            'status' => $goal->status,
            'source' => $goal->source,
            'review_note' => $goal->review_note,
            'remove_request_note' => $goal->remove_request_note,
        ];
    }
}
