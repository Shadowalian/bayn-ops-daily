<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Day;
use App\Models\User;
use App\Services\AppTime;
use App\Services\DayService;
use App\Services\TelegramBotService;
use Illuminate\Http\Request;

class AdminBotController extends Controller
{
    public function sendMorning(TelegramBotService $bot)
    {
        $count = $bot->promptAllMorning();

        return response()->json([
            'ok' => true,
            'sent' => $count,
            'message' => "Morning prompts sent to {$count} member(s).",
        ]);
    }

    public function sendEvening(TelegramBotService $bot)
    {
        $count = $bot->promptAllEvening();

        return response()->json([
            'ok' => true,
            'sent' => $count,
            'message' => "Evening prompts sent to {$count} member(s).",
        ]);
    }

    public function unlockEdit(Request $request, Day $day)
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $day->update(['edit_unlocked' => true]);

        \App\Models\AuditLog::record(
            $request->user()->id,
            'day',
            $day->id,
            'edit_unlocked',
            false,
            true,
            $data['note']
        );

        $user = $day->user;
        if ($user?->telegram_chat_id) {
            app(TelegramBotService::class)->promptMorning($user);
        }

        return response()->json([
            'ok' => true,
            'day_id' => $day->id,
            'edit_unlocked' => true,
        ]);
    }

    public function updateUserTelegram(Request $request, User $user)
    {
        $data = $request->validate([
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
        ]);

        $user->update(['telegram_chat_id' => $data['telegram_chat_id'] ?: null]);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'telegram_chat_id' => $user->telegram_chat_id,
        ]);
    }

    public function status(DayService $days)
    {
        $team = $days->teamToday();

        return response()->json([
            'date' => AppTime::today(),
            'bot_token_set' => (bool) config('services.telegram.bot_token'),
            'members' => collect($team)->map(fn ($row) => [
                'user' => $row['user'],
                'telegram_linked' => User::query()->where('id', $row['user']['id'])->value('telegram_chat_id') !== null,
                'state' => $row['day']['state'] ?? 'not_started',
                'edit_unlocked' => $row['day']['edit_unlocked'] ?? false,
                'day_id' => $row['day']['id'] ?? null,
                'planned' => $row['overall_planned'],
                'done' => $row['overall_done'],
            ]),
        ]);
    }
}
