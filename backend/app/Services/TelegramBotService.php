<?php

namespace App\Services;

use App\Models\Day;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

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

    public function registerBotUi(): void
    {
        $this->telegram->setMyCommands([
            ['command' => 'start', 'description' => 'Start BAYN OPS / link employee code'],
            ['command' => 'tasks', 'description' => 'Enter today’s daily tasks'],
            ['command' => 'help', 'description' => 'How to use the bot'],
        ]);
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
        $this->askForDailyTasks($user, $day);

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
        $normalized = mb_strtolower($text);
        $isStart = str_starts_with($text, '/start')
            || in_array($normalized, ['start', '🚀 start', '🚀start'], true)
            || str_starts_with($normalized, '🚀 start');

        if ($isStart) {
            $this->handleStart($chatId, $text, $user);

            return;
        }

        if (str_starts_with($text, '/help') || $normalized === 'help') {
            $this->sendHelp($chatId, $user);

            return;
        }

        if (str_starts_with($text, '/tasks') || $normalized === '📝 today\'s tasks' || $normalized === "📝 today's tasks") {
            if (! $user) {
                $this->askToPressStart($chatId);

                return;
            }
            $this->promptMorning($user);

            return;
        }

        $state = $this->getState($chatId);
        $phase = $state['phase'] ?? 'idle';

        if ($phase === 'awaiting_employee_code') {
            $this->receiveEmployeeCode($chatId, $text);

            return;
        }

        if (! $user) {
            $this->askToPressStart($chatId);

            return;
        }

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
            "Hi <b>{$user->name}</b>. Tap <b>📝 Today's tasks</b> to submit your plan, or wait for the admin morning/evening prompt.",
            $this->linkedKeyboard()
        );
    }

    private function handleStart(string $chatId, string $text, ?User $user): void
    {
        $parts = preg_split('/\s+/', $text, 2) ?: [];
        $maybeCode = isset($parts[1]) ? strtoupper(trim($parts[1])) : null;
        if ($maybeCode && preg_match('/^[A-Z0-9_-]+$/', $maybeCode) && ! str_starts_with(strtolower($maybeCode), 'start')) {
            $this->linkEmployee($chatId, $maybeCode);

            return;
        }

        if ($user) {
            $this->telegram->sendMessage(
                $chatId,
                "Welcome back, <b>{$user->name}</b> ({$user->employee_id}).\n\n"
                .'Tap <b>📝 Today\'s tasks</b> when you are ready to plan your day.',
                $this->linkedKeyboard()
            );
            $this->promptMorning($user);

            return;
        }

        $this->setState($chatId, ['phase' => 'awaiting_employee_code']);
        $this->telegram->sendMessage(
            $chatId,
            "Welcome to <b>BAYN OPS</b> 👋\n\n"
            .'Please send your <b>employee code</b> (example: <code>EMP001</code> or <code>MGR001</code>).\n'
            .'Your admin creates and assigns that code.',
            $this->startKeyboard()
        );
    }

    private function receiveEmployeeCode(string $chatId, string $text): void
    {
        $code = strtoupper(trim($text));
        $code = preg_replace('/^\/start\s+/i', '', $code) ?? $code;
        if (! preg_match('/^[A-Z0-9_-]+$/', $code)) {
            $this->telegram->sendMessage(
                $chatId,
                'That does not look like an employee code. Send something like <code>EMP001</code>.'
            );

            return;
        }

        $this->linkEmployee($chatId, $code);
    }

    private function linkEmployee(string $chatId, string $employeeId): void
    {
        $found = User::query()
            ->where('employee_id', $employeeId)
            ->where('active', true)
            ->first();

        if (! $found) {
            $this->setState($chatId, ['phase' => 'awaiting_employee_code']);
            $this->telegram->sendMessage(
                $chatId,
                'No active employee found for that code. Ask your admin to create/assign your code, then try again.'
            );

            return;
        }

        // One Telegram chat per employee; free previous chat if re-linking
        User::query()
            ->where('telegram_chat_id', $chatId)
            ->where('id', '!=', $found->id)
            ->update(['telegram_chat_id' => null]);

        $found->update(['telegram_chat_id' => $chatId]);
        $this->clearState($chatId);

        $this->telegram->sendMessage(
            $chatId,
            "Hello <b>{$found->name}</b> 👋\n"
            ."Welcome to <b>BAYN OPS</b>.\n\n"
            .'Kindly provide your daily tasks using the table below, then send your list.',
            $this->linkedKeyboard()
        );

        $day = $this->days->getOrCreateToday($found);
        $this->askForDailyTasks($found, $day);
    }

    private function askForDailyTasks(User $user, Day $day): void
    {
        $this->setState((string) $user->telegram_chat_id, [
            'phase' => 'awaiting_plan',
            'day_id' => $day->id,
        ]);

        $table = "<pre>"
            ."+----+---------------------------+\n"
            ."| #  | Task                      |\n"
            ."+----+---------------------------+\n"
            ."| 1  |                           |\n"
            ."| 2  |                           |\n"
            ."| 3  |                           |\n"
            ."+----+---------------------------+\n"
            ."</pre>";

        $this->telegram->sendMessage(
            $user->telegram_chat_id,
            "Kindly provide your daily tasks:\n\n"
            .$table
            ."\nReply like this (then send):\n"
            ."<code>1) First task\n"
            ."2) Second task\n"
            ."3) Third task</code>\n\n"
            .'When you submit, your plan is locked for the day unless an admin unlocks edit.\n'
            .'Admin-assigned tasks stay on your list even when you update your own tasks.'
        );
    }

    private function askToPressStart(string $chatId): void
    {
        $this->telegram->sendMessage(
            $chatId,
            "Welcome to <b>BAYN OPS</b>.\nTap the <b>🚀 Start</b> button below to begin, then enter your employee code.",
            $this->startKeyboard()
        );
    }

    private function sendHelp(string $chatId, ?User $user): void
    {
        if (! $user) {
            $this->askToPressStart($chatId);

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            "<b>BAYN OPS help</b>\n"
            ."• Your code: <code>{$user->employee_id}</code>\n"
            ."• Tap <b>📝 Today's tasks</b> to submit your morning plan\n"
            ."• Evening: answer ✅/❌ on each task when prompted\n"
            .'• Only your admin can create codes and unlock edits',
            $this->linkedKeyboard()
        );
    }

    private function startKeyboard(): array
    {
        return [
            'keyboard' => [[['text' => '🚀 Start']]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    private function linkedKeyboard(): array
    {
        return [
            'keyboard' => [[
                ['text' => "📝 Today's tasks"],
                ['text' => '🚀 Start'],
            ]],
            'resize_keyboard' => true,
        ];
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
                "I couldn't read any tasks. Send them numbered, for example:\n<code>1) Call client\n2) Fix bug</code>"
            );

            return;
        }

        if (count($titles) > 10) {
            $this->telegram->sendMessage($chatId, 'Please keep it to 10 tasks or fewer.');

            return;
        }

        // Replace only self-added tasks; keep admin-assigned work
        if ($day->edit_unlocked || ! $day->plan_submitted_at) {
            $day->tasks()
                ->where('result', '!=', 'dropped')
                ->where(function ($q) {
                    $q->where('source', 'self')->orWhereNull('source');
                })
                ->update([
                    'result' => 'dropped',
                    'reason_code' => 'other',
                    'reason_note' => 'Replaced after plan update',
                ]);
        }

        $position = (int) ($day->tasks()->max('position') ?? 0);
        foreach ($titles as $title) {
            $position++;
            Task::query()->create([
                'user_id' => $user->id,
                'day_id' => $day->id,
                'title' => mb_substr($title, 0, 200),
                'tier' => 'must',
                'source' => 'self',
                'position' => $position,
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
        $quote = MotivationalQuote::forDate();

        $this->telegram->sendMessage(
            $chatId,
            "Thank you for your input, <b>{$user->name}</b> ✅\n"
            ."Have a nice and productive day!\n\n"
            ."Your plan:\n{$list}\n\n"
            ."💬 <i>{$quote}</i>",
            $this->linkedKeyboard()
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
            $day->update(['closed_at' => now('UTC')]);
            $this->clearState((string) $user->telegram_chat_id);

            $done = $day->tasks->where('result', 'done')->count();
            $total = $day->tasks->count();
            $this->telegram->sendMessage(
                $user->telegram_chat_id,
                "Saved: {$done}/{$total} done.\n\nGood night <b>{$user->name}</b> 🌙",
                $this->linkedKeyboard()
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
