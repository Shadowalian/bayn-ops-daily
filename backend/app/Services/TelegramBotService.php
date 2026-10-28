<?php

namespace App\Services;

use App\Models\Day;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    public function __construct(
        private TelegramClient $telegram,
        private DayService $days,
    ) {}

    public function handleUpdate(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    public function promptMorning(User $user): bool
    {
        if (! $user->telegram_chat_id || ! $user->active) {
            return false;
        }

        $day = $this->days->getOrCreateToday($user);

        if ($day->plan_submitted_at && ! $day->edit_unlocked) {
            $this->telegram->sendMessage(
                $user->telegram_chat_id,
                "Good morning <b>{$user->name}</b> — your plan for today is already locked. Ask your admin if you need to change it."
            );

            return true;
        }

        $day->update(['morning_prompted_at' => now('UTC')]);
        $this->setState($user->telegram_chat_id, [
            'phase' => 'awaiting_plan',
            'day_id' => $day->id,
        ]);

        $this->telegram->sendMessage(
            $user->telegram_chat_id,
            "Good morning <b>{$user->name}</b> ☀️\n\n"
            ."Could you give me your daily tasks?\n"
            ."Reply like this:\n\n"
            ."1) First task\n"
            ."2) Second task\n"
            ."3) Third task\n\n"
            .'When you send that message, your plan is locked. To edit later you must ask the admin.'
        );

        return true;
    }

    public function promptEvening(User $user): bool
    {
        if (! $user->telegram_chat_id || ! $user->active) {
            return false;
        }

        $day = $this->days->getOrCreateToday($user);

        if (! $day->plan_submitted_at || $day->tasks()->count() === 0) {
            $this->telegram->sendMessage(
                $user->telegram_chat_id,
                "Good evening <b>{$user->name}</b> — you have no submitted plan for today yet."
            );

            return true;
        }

        if ($day->closed_at) {
            $this->telegram->sendMessage(
                $user->telegram_chat_id,
                "Good evening <b>{$user->name}</b> — your day is already closed. Good night!"
            );

            return true;
        }

        $day->update(['evening_prompted_at' => now('UTC')]);

        $this->telegram->sendMessage(
            $user->telegram_chat_id,
            "Good evening <b>{$user->name}</b> 🌙\n\n"
            .'How much of your plan did you accomplish? I will go task by task. '
            .'Tap ✅ if done, or ❌ if not — and explain the roadblock.'
        );

        $this->askNextEveningTask($user, $day);

        return true;
    }

    public function promptAllMorning(): int
    {
        $count = 0;
        User::query()->where('active', true)->whereNotNull('telegram_chat_id')->each(function (User $user) use (&$count) {
            if ($this->promptMorning($user)) {
                $count++;
            }
        });

        return $count;
    }

    public function promptAllEvening(): int
    {
        $count = 0;
        User::query()->where('active', true)->whereNotNull('telegram_chat_id')->each(function (User $user) use (&$count) {
            if ($this->promptEvening($user)) {
                $count++;
            }
        });

        return $count;
    }

    private function handleMessage(array $message): void
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        if ($chatId === '' || $text === '') {
            return;
        }

        $user = User::query()->where('telegram_chat_id', $chatId)->first();

        if (str_starts_with($text, '/start')) {
            $this->handleStart($chatId, $text, $user);

            return;
        }

        if (! $user) {
            $this->telegram->sendMessage(
                $chatId,
                "I don't know this chat yet.\nSend: <code>/start your@email.com</code>\n(use the email registered in Ops Daily Check)"
            );

            return;
        }

        $state = $this->getState($chatId);
        $phase = $state['phase'] ?? 'idle';

        if ($phase === 'awaiting_plan') {
            $this->receivePlan($user, $chatId, $text, $state);

            return;
        }

        if ($phase === 'awaiting_roadblock') {
            $this->receiveRoadblock($user, $chatId, $text, $state);

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            "Hi {$user->name}. Wait for the morning or evening prompt from your admin, or ask them if you need an edit unlock."
        );
    }

    private function handleStart(string $chatId, string $text, ?User $user): void
    {
        $parts = preg_split('/\s+/', $text, 2);
        $employeeId = isset($parts[1]) ? strtoupper(trim($parts[1])) : null;

        if ($user) {
            $this->telegram->sendMessage(
                $chatId,
                "You're linked as <b>{$user->name}</b> ({$user->employee_id}). Wait for today's morning prompt."
            );

            return;
        }

        if (! $employeeId) {
            $this->telegram->sendMessage(
                $chatId,
                "Welcome to Ops Daily Check.\nLink your account with your employee ID:\n<code>/start EMP001</code>"
            );

            return;
        }

        $found = User::query()
            ->whereRaw('UPPER(employee_id) = ?', [$employeeId])
            ->where('active', true)
            ->first();

        if (! $found) {
            $this->telegram->sendMessage($chatId, 'No active employee found for that ID. Ask your admin.');

            return;
        }

        $found->update(['telegram_chat_id' => $chatId]);
        $this->telegram->sendMessage(
            $chatId,
            "Linked! Hi <b>{$found->name}</b> ({$found->employee_id}). Your admin will send the morning task prompt when ready."
        );
    }

    private function receivePlan(User $user, string $chatId, string $text, array $state): void
    {
        $day = Day::query()->find($state['day_id'] ?? 0) ?? $this->days->getOrCreateToday($user);

        if ($day->plan_submitted_at && ! $day->edit_unlocked) {
            $this->clearState($chatId);
            $this->telegram->sendMessage($chatId, 'Your plan is locked. Ask the admin to allow editing.');

            return;
        }

        $titles = $this->parseNumberedTasks($text);
        if ($titles === []) {
            $this->telegram->sendMessage(
                $chatId,
                "I couldn't read any tasks. Send them numbered, for example:\n1) Call client\n2) Fix bug"
            );

            return;
        }

        if (count($titles) > 10) {
            $this->telegram->sendMessage($chatId, 'Please keep it to 10 tasks or fewer.');

            return;
        }

        // Replace plan when unlocked re-submit (keep history as dropped)
        if ($day->edit_unlocked || ! $day->plan_submitted_at) {
            $day->tasks()->where('result', '!=', 'dropped')->update([
                'result' => 'dropped',
                'reason_code' => 'other',
                'reason_note' => 'Replaced after admin unlock',
            ]);
        }

        foreach ($titles as $i => $title) {
            Task::query()->create([
                'user_id' => $user->id,
                'day_id' => $day->id,
                'title' => mb_substr($title, 0, 200),
                'tier' => 'must',
                'position' => $i + 1,
                'result' => 'planned',
                'added_after_cutoff' => false,
            ]);
        }

        $day->update([
            'plan_submitted_at' => now('UTC'),
            'edit_unlocked' => false,
        ]);

        $this->clearState($chatId);

        $list = collect($titles)->map(fn ($t, $i) => ($i + 1).') '.$t)->implode("\n");
        $this->telegram->sendMessage(
            $chatId,
            "Got it, <b>{$user->name}</b>. Plan locked ✅\n\n{$list}\n\n"
            .'No more edits unless the admin unlocks you.'
        );
    }

    private function receiveRoadblock(User $user, string $chatId, string $text, array $state): void
    {
        $task = Task::query()->find($state['task_id'] ?? 0);
        if (! $task || $task->user_id !== $user->id) {
            $this->clearState($chatId);
            $this->telegram->sendMessage($chatId, 'That task is gone. Wait for the next evening prompt.');

            return;
        }

        $task->update([
            'result' => 'not_done',
            'reason_code' => 'blocked',
            'reason_note' => mb_substr($text, 0, 500),
            'blocked' => true,
            'blocker_note' => mb_substr($text, 0, 500),
        ]);

        $day = $task->day;
        $this->askNextEveningTask($user, $day);
    }

    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        $user = User::query()->where('telegram_chat_id', $chatId)->first();

        if (! $user) {
            $this->telegram->answerCallback($callbackId, 'Not linked');

            return;
        }

        // eve:{taskId}:done|not
        if (! preg_match('/^eve:(\d+):(done|not)$/', $data, $m)) {
            $this->telegram->answerCallback($callbackId);

            return;
        }

        $task = Task::query()->find((int) $m[1]);
        if (! $task || $task->user_id !== $user->id) {
            $this->telegram->answerCallback($callbackId, 'Unknown task');

            return;
        }

        $day = $task->day;
        if ($day->closed_at) {
            $this->telegram->answerCallback($callbackId, 'Day already closed');

            return;
        }

        if ($m[2] === 'done') {
            $task->update([
                'result' => 'done',
                'reason_code' => null,
                'reason_note' => null,
                'blocked' => false,
                'blocker_note' => null,
            ]);
            $this->telegram->answerCallback($callbackId, 'Marked done');
            $this->askNextEveningTask($user, $day->fresh('tasks'));

            return;
        }

        $this->setState($chatId, [
            'phase' => 'awaiting_roadblock',
            'day_id' => $day->id,
            'task_id' => $task->id,
        ]);
        $this->telegram->answerCallback($callbackId, 'Not done');
        $this->telegram->sendMessage(
            $chatId,
            "What roadblock stopped <b>{$task->title}</b> from getting finished?\n(Reply with a short explanation.)"
        );
    }

    private function askNextEveningTask(User $user, Day $day): void
    {
        $day->load('tasks');
        $next = $day->tasks->first(fn (Task $t) => $t->result === 'planned');

        if (! $next) {
            // Must have a result on every task
            $unfinished = $day->tasks->contains(fn (Task $t) => $t->result === 'planned');
            if ($unfinished) {
                return;
            }

            $day->update(['closed_at' => now('UTC')]);
            $this->clearState((string) $user->telegram_chat_id);

            $done = $day->tasks->where('result', 'done')->count();
            $total = $day->tasks->count();
            $this->telegram->sendMessage(
                $user->telegram_chat_id,
                "Saved: {$done}/{$total} done.\n\nGood night <b>{$user->name}</b> 🌙"
            );

            return;
        }

        $this->setState((string) $user->telegram_chat_id, [
            'phase' => 'awaiting_evening_choice',
            'day_id' => $day->id,
            'task_id' => $next->id,
        ]);

        $this->telegram->sendMessage(
            $user->telegram_chat_id,
            "Task {$next->position}/{$day->tasks->count()}:\n<b>{$next->title}</b>",
            [
                'inline_keyboard' => [[
                    ['text' => '✅ Done', 'callback_data' => "eve:{$next->id}:done"],
                    ['text' => '❌ Not done', 'callback_data' => "eve:{$next->id}:not"],
                ]],
            ]
        );
    }

    /** @return list<string> */
    public function parseNumberedTasks(string $text): array
    {
        $lines = preg_split('/\R+/', $text) ?: [];
        $titles = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\s*(?:\d+[\)\.\-:]\s*|[-*•]\s+)(.+)$/u', $line, $m)) {
                $title = trim($m[1]);
                if ($title !== '') {
                    $titles[] = $title;
                }
            }
        }

        // Fallback: if user sent a single paragraph without numbers, treat each non-empty line as a task
        if ($titles === [] && count($lines) > 0) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '/')) {
                    $titles[] = $line;
                }
            }
        }

        return $titles;
    }

    private function stateKey(string $chatId): string
    {
        return 'tg_bot_state:'.$chatId;
    }

    private function getState(string $chatId): array
    {
        return Cache::get($this->stateKey($chatId), []);
    }

    private function setState(string $chatId, array $state): void
    {
        Cache::put($this->stateKey($chatId), $state, now()->addDay());
    }

    private function clearState(string $chatId): void
    {
        Cache::forget($this->stateKey($chatId));
    }
}
