<?php

namespace App\Services;

use App\Models\Day;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyGoal;
use App\Models\WeeklyPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class TelegramBotService
{
    public function __construct(
        private TelegramClient $telegram,
        private DayService $days,
        private WeeklyPlanService $weekly,
        private WeeklyPlanTable $weeklyTable,
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
            ['command' => 'week', 'description' => 'Set or change this week’s plan'],
            ['command' => 'tasks', 'description' => 'Today’s checkpoints / daily tasks'],
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
            if ($this->weekly->enabled()) {
                $plan = $this->weekly->currentPlan($user);
                $body = "Good morning <b>{$user->name}</b> — today is locked.\nAsk admin if you need to change which checkpoints you are on.";
                if ($plan && $plan->isApproved()) {
                    $body .= "\n\n".$this->weekly->renderTable($plan);
                }
                $this->telegram->sendMessage($user->telegram_chat_id, $body, $this->linkedKeyboard());
            } else {
                $this->telegram->sendMessage(
                    $user->telegram_chat_id,
                    "Good morning <b>{$user->name}</b> — your plan for today is already locked. Ask your admin if you need to change it."
                );
            }

            return true;
        }

        $day->update(['morning_prompted_at' => now('UTC')]);

        if ($this->weekly->enabled()) {
            $this->askWeeklyMorning($user, $day);

            return true;
        }

        $this->askForDailyTasks($user, $day);

        return true;
    }

    public function promptEvening(User $user): bool
    {
        if (! $user->telegram_chat_id || ! $user->active) {
            return false;
        }

        $day = $this->days->getOrCreateToday($user);

        if (! $day->plan_submitted_at || $day->tasks()->where('result', '!=', 'dropped')->count() === 0) {
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

        if ($this->weekly->enabled()) {
            $this->askWeeklyEvening($user, $day);

            return true;
        }

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
            $day = $this->days->getOrCreateToday($user);
            if ($this->weekly->enabled() && $day->plan_submitted_at && ! $day->closed_at && ! $day->edit_unlocked) {
                $this->askWeeklyEvening($user, $day);

                return;
            }
            $this->promptMorning($user);

            return;
        }

        if (str_starts_with($text, '/week') || $normalized === '🗓 week plan' || $normalized === '🗓️ week plan') {
            if (! $user) {
                $this->askToPressStart($chatId);

                return;
            }
            $this->handleWeekCommand($user, $chatId, $text);

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

        if ($phase === 'awaiting_week_plan') {
            $this->receiveWeekPlan($user, $chatId, $text);

            return;
        }

        if ($phase === 'awaiting_week_add') {
            $this->receiveWeekAdd($user, $chatId, $text);

            return;
        }

        if ($phase === 'awaiting_week_remove') {
            $this->receiveWeekRemove($user, $chatId, $text, $state);

            return;
        }

        if ($phase === 'awaiting_today_focus') {
            $this->receiveTodayFocus($user, $chatId, $text, $state);

            return;
        }

        if ($phase === 'awaiting_roadblock') {
            $this->receiveRoadblock($user, $chatId, $text, $state);

            return;
        }

        $hint = $this->weekly->enabled()
            ? "Tap <b>🗓 Week plan</b> to set checkpoints, or <b>📝 Today's tasks</b> for today."
            : "Tap <b>📝 Today's tasks</b> to submit your plan, or wait for the admin morning/evening prompt.";

        $this->telegram->sendMessage(
            $chatId,
            "Hi <b>{$user->name}</b>. {$hint}",
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

        if ($this->weekly->enabled()) {
            $this->telegram->sendMessage(
                $chatId,
                "Hello <b>{$found->name}</b> 👋\n"
                ."Welcome to <b>BAYN OPS</b>.\n\n"
                .'First set this week\'s checkpoints with <b>🗓 Week plan</b>. Admin will approve them, then you pick what to push each day.',
                $this->linkedKeyboard()
            );
            $this->beginWeekPlanCapture($found);

            return;
        }

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

        $extra = $this->weekly->enabled()
            ? "• <b>/week</b> — submit weekly checkpoints for admin approval\n"
              ."• Morning — pick today’s rows from your weekly table\n"
              ."• <code>/week add</code> / <code>/week remove</code> — mid-week changes\n"
            : "• Tap <b>📝 Today's tasks</b> to submit your morning plan\n";

        $this->telegram->sendMessage(
            $chatId,
            "<b>BAYN OPS help</b>\n"
            ."• Your code: <code>{$user->employee_id}</code>\n"
            .$extra
            ."• Evening: mark ✅/❌ on today’s work\n"
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
        $row = [['text' => "📝 Today's tasks"]];
        if ($this->weekly->enabled()) {
            $row[] = ['text' => '🗓 Week plan'];
        }
        $row[] = ['text' => '🚀 Start'];

        return [
            'keyboard' => [$row],
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
        if ($this->weekly->enabled() && ($state['mode'] ?? '') === 'weekly') {
            $this->clearState($chatId);
            $this->askWeeklyEvening($user, $day->fresh('tasks'));

            return;
        }

        $this->askNextEveningTask($user, $day);
    }

    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $user = User::query()->where('telegram_chat_id', $chatId)->first();

        if (! $user) {
            $this->telegram->answerCallback($callbackId, 'Not linked');

            return;
        }

        if (str_starts_with($data, 'wf:')) {
            $this->handleFocusCallback($user, $chatId, $callbackId, $data, $messageId);

            return;
        }

        if (str_starts_with($data, 'wx:')) {
            $this->handleWeeklyEveningCallback($user, $chatId, $callbackId, $data, $messageId);

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
            'What roadblock stopped <b>'.e($task->title)."</b> from getting finished?\n(Reply with a short explanation.)"
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

    private function askWeeklyMorning(User $user, Day $day): void
    {
        $plan = $this->weekly->currentPlan($user);

        if (! $plan || $plan->isRejected()) {
            $reason = $plan?->rejection_reason;
            $msg = "Good morning <b>{$user->name}</b>.\n";
            if ($reason) {
                $msg .= 'Your weekly plan was not approved.\nReason: '.e($reason)."\n\n";
            } else {
                $msg .= "Set this week's checkpoints first — admin must approve before daily work starts.\n\n";
            }
            $this->telegram->sendMessage($user->telegram_chat_id, $msg, $this->linkedKeyboard());
            $this->beginWeekPlanCapture($user);

            return;
        }

        if ($plan->isPending()) {
            $this->telegram->sendMessage(
                $user->telegram_chat_id,
                "Good morning <b>{$user->name}</b>.\nYour weekly plan is <b>waiting for admin approval</b>.\n"
                .'You will get a message when it is approved or returned.',
                $this->linkedKeyboard()
            );
            $this->clearState((string) $user->telegram_chat_id);

            return;
        }

        $goals = $this->weekly->tableGoals($plan);
        if ($goals->isEmpty()) {
            $this->telegram->sendMessage(
                $user->telegram_chat_id,
                "Good morning <b>{$user->name}</b>.\nYour week is approved but has no live checkpoints yet. Use /week add or wait for admin assign.",
                $this->linkedKeyboard()
            );

            return;
        }

        $this->promptTodayFocus($user, $plan, $day);
    }

    private function promptTodayFocus(User $user, WeeklyPlan $plan, Day $day): void
    {
        $goals = $this->weekly->tableGoals($plan);
        $focusIds = $goals->where('source', 'admin')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $this->setState((string) $user->telegram_chat_id, [
            'phase' => 'awaiting_today_focus',
            'day_id' => $day->id,
            'plan_id' => $plan->id,
            'focus_ids' => $focusIds,
        ]);

        $body = "Good morning <b>{$user->name}</b>.\n\n"
            .$this->weekly->renderTable($plan)
            ."\n\nReply with the numbers you will push today: <code>1, 3</code> or <code>all</code>.\n"
            .'Rows marked <code>adm</code> are already on your day.';

        $sent = $this->telegram->sendMessage(
            $user->telegram_chat_id,
            $body,
            $this->focusKeyboard($goals, $focusIds)
        );

        if ($messageId = (int) ($sent['result']['message_id'] ?? 0)) {
            $state = $this->getState((string) $user->telegram_chat_id);
            $state['table_message_id'] = $messageId;
            $this->setState((string) $user->telegram_chat_id, $state);
        }
    }

    private function askWeeklyEvening(User $user, Day $day): void
    {
        $plan = $this->weekly->currentPlan($user);
        $day->load('tasks');
        $active = $day->tasks->filter(fn (Task $t) => $t->result !== 'dropped')->values();

        $intro = "Good evening <b>{$user->name}</b> 🌙\n\n";
        if ($plan && $plan->isApproved()) {
            $intro .= $this->weekly->renderTable($plan, $user, true)."\n\n";
        } else {
            $intro .= "Mark today's tasks:\n";
        }

        $keyboard = ['inline_keyboard' => []];
        foreach ($active as $task) {
            $label = mb_substr($task->title, 0, 28);
            $mark = match ($task->result) {
                'done' => '✅',
                'not_done' => '❌',
                default => '·',
            };
            $keyboard['inline_keyboard'][] = [
                ['text' => "{$task->position} {$mark} {$label}", 'callback_data' => 'wx:'.$task->id.':info'],
                ['text' => '✅', 'callback_data' => 'wx:'.$task->id.':d'],
                ['text' => '❌', 'callback_data' => 'wx:'.$task->id.':n'],
            ];
        }
        $keyboard['inline_keyboard'][] = [['text' => 'Finish day', 'callback_data' => 'wx:close']];

        $this->setState((string) $user->telegram_chat_id, [
            'phase' => 'awaiting_evening_choice',
            'day_id' => $day->id,
            'mode' => 'weekly',
        ]);

        $sent = $this->telegram->sendMessage($user->telegram_chat_id, $intro.'Tap ✅ or ❌, then <b>Finish day</b>.', $keyboard);
        if ($messageId = (int) ($sent['result']['message_id'] ?? 0)) {
            $state = $this->getState((string) $user->telegram_chat_id);
            $state['table_message_id'] = $messageId;
            $this->setState((string) $user->telegram_chat_id, $state);
        }
    }

    private function handleWeekCommand(User $user, string $chatId, string $text): void
    {
        if (! $this->weekly->enabled()) {
            $this->telegram->sendMessage($chatId, 'Weekly plans are not enabled yet. Use 📝 Today\'s tasks.', $this->linkedKeyboard());

            return;
        }

        $parts = preg_split('/\s+/', trim($text)) ?: [];
        $sub = mb_strtolower($parts[1] ?? '');

        if ($sub === 'add') {
            $plan = $this->weekly->currentPlan($user);
            if (! $plan?->isApproved()) {
                $this->telegram->sendMessage($chatId, 'Your week must be approved before adding work. Use /week first.');

                return;
            }
            $this->setState($chatId, ['phase' => 'awaiting_week_add']);
            $this->telegram->sendMessage(
                $chatId,
                "Send new checkpoints to add (for admin approval):\n<code>1) Extra item\n2) Another</code>"
            );

            return;
        }

        if ($sub === 'remove') {
            $plan = $this->weekly->currentPlan($user);
            if (! $plan?->isApproved()) {
                $this->telegram->sendMessage($chatId, 'No approved week to remove from.');

                return;
            }
            $goals = $this->weekly->tableGoals($plan);
            if ($goals->isEmpty()) {
                $this->telegram->sendMessage($chatId, 'Nothing to remove.');

                return;
            }
            $list = $goals->values()->map(fn (WeeklyGoal $g, $i) => ($i + 1).') '.$g->title)->implode("\n");
            $this->setState($chatId, [
                'phase' => 'awaiting_week_remove',
                'plan_id' => $plan->id,
                'goal_ids' => $goals->pluck('id')->values()->all(),
            ]);
            $this->telegram->sendMessage(
                $chatId,
                "Which numbers should be removed?\n{$list}\n\nReply like: <code>2 — client moved deadline</code>"
            );

            return;
        }

        $plan = $this->weekly->currentPlan($user);
        if ($plan?->isApproved()) {
            $this->telegram->sendMessage(
                $chatId,
                "Your week is <b>approved</b>.\n\n".$this->weekly->renderTable($plan)."\n\n"
                ."To add work for approval: <code>/week add</code>\n"
                .'To ask to remove work: <code>/week remove</code>',
                $this->linkedKeyboard()
            );

            return;
        }

        if ($plan?->isPending()) {
            $titles = $plan->goals->where('source', 'self')->where('status', WeeklyGoal::STATUS_PENDING)
                ->map(fn (WeeklyGoal $g) => '• '.$g->title)->implode("\n");
            $this->telegram->sendMessage(
                $chatId,
                "Your plan is <b>pending</b> admin review:\n{$titles}\n\nSend a new numbered list to replace it."
            );
        } elseif ($plan?->isRejected()) {
            $this->telegram->sendMessage(
                $chatId,
                'Last plan was rejected. Reason: '.e((string) $plan->rejection_reason)."\nSend a revised numbered list."
            );
        }

        $this->beginWeekPlanCapture($user);
    }

    private function beginWeekPlanCapture(User $user): void
    {
        $this->setState((string) $user->telegram_chat_id, ['phase' => 'awaiting_week_plan']);
        $this->telegram->sendMessage(
            $user->telegram_chat_id,
            "Send this week's checkpoints, one per line (max 12):\n\n"
            ."<code>1) Close Acme invoice\n2) Ship ops bot\n3) Reconcile stock</code>\n\n"
            .'Admin reviews this before it becomes your daily table.'
        );
    }

    private function receiveWeekPlan(User $user, string $chatId, string $text): void
    {
        $titles = $this->parseNumberedTasks($text);
        if ($titles === []) {
            $this->telegram->sendMessage($chatId, "Couldn't read checkpoints. Send numbered lines like <code>1) Task</code>");

            return;
        }

        try {
            $plan = $this->weekly->submitPlan($user, $titles);
        } catch (ValidationException $e) {
            $this->telegram->sendMessage($chatId, collect($e->errors())->flatten()->first() ?: 'Could not save plan.');

            return;
        }

        $this->clearState($chatId);
        $this->telegram->sendMessage(
            $chatId,
            'Sent to admin. Open the <b>Weekly plans</b> tab in the admin web app to approve it. You will also get a Telegram message when it is approved or returned.',
            $this->linkedKeyboard()
        );
        $this->pingManagers(
            "Weekly plan from <b>{$user->name}</b> (<code>{$user->employee_id}</code>) is waiting.\n"
            .'Open <b>Weekly plans</b> on the admin site to approve or reject.',
            $user->id
        );
    }

    private function receiveWeekAdd(User $user, string $chatId, string $text): void
    {
        $titles = $this->parseNumberedTasks($text);
        if ($titles === []) {
            $this->telegram->sendMessage($chatId, 'Send numbered checkpoints to add.');

            return;
        }

        try {
            $this->weekly->requestAdd($user, $titles);
        } catch (ValidationException $e) {
            $this->telegram->sendMessage($chatId, collect($e->errors())->flatten()->first() ?: 'Could not request add.');

            return;
        }

        $this->clearState($chatId);
        $list = collect($titles)->map(fn ($t) => '• '.$t)->implode("\n");
        $this->telegram->sendMessage($chatId, "Sent for approval:\n{$list}", $this->linkedKeyboard());
        $this->pingManagers(
            "Mid-week add from <b>{$user->name}</b> (<code>{$user->employee_id}</code>) needs review in <b>Weekly plans</b>.",
            $user->id
        );
    }

    private function receiveWeekRemove(User $user, string $chatId, string $text, array $state): void
    {
        $goalIds = $state['goal_ids'] ?? [];
        if (! preg_match_all('/\d+/', $text, $m)) {
            $this->telegram->sendMessage($chatId, 'Reply with the number(s) to remove, e.g. <code>2 — reason</code>');

            return;
        }

        $note = null;
        if (preg_match('/[-—:]\s*(.+)$/u', $text, $nm)) {
            $note = trim($nm[1]);
        }

        $selected = [];
        foreach ($m[0] as $num) {
            $idx = (int) $num - 1;
            if (isset($goalIds[$idx])) {
                $selected[] = (int) $goalIds[$idx];
            }
        }
        $selected = array_values(array_unique($selected));
        if ($selected === []) {
            $this->telegram->sendMessage($chatId, 'Those numbers do not match the list. Try again.');

            return;
        }

        try {
            $this->weekly->requestRemoval($user, $selected, $note);
        } catch (ValidationException $e) {
            $this->telegram->sendMessage($chatId, collect($e->errors())->flatten()->first() ?: 'Could not request removal.');

            return;
        }

        $this->clearState($chatId);
        $this->telegram->sendMessage(
            $chatId,
            'Removal request sent. Those rows stay on your table as <code>cut</code> until admin decides.',
            $this->linkedKeyboard()
        );
        $this->pingManagers(
            "Removal request from <b>{$user->name}</b> (<code>{$user->employee_id}</code>) needs review in <b>Weekly plans</b>.",
            $user->id
        );
    }

    private function receiveTodayFocus(User $user, string $chatId, string $text, array $state): void
    {
        $plan = WeeklyPlan::query()->with('goals')->find($state['plan_id'] ?? 0) ?? $this->weekly->currentPlan($user);
        if (! $plan || ! $plan->isApproved()) {
            $this->clearState($chatId);
            $this->telegram->sendMessage($chatId, 'Weekly plan is not available. Use /week.');

            return;
        }

        $goals = $this->weekly->tableGoals($plan);
        $map = $this->weeklyTable->positionMap($goals);
        $normalized = mb_strtolower(trim($text));

        if ($normalized === 'all') {
            $selected = $goals->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            if (! preg_match_all('/\d+/', $text, $m) || $m[0] === []) {
                $this->telegram->sendMessage($chatId, 'Reply with numbers like <code>1, 3</code> or <code>all</code>.');

                return;
            }
            $selected = [];
            foreach ($m[0] as $num) {
                $pos = (int) $num;
                if (isset($map[$pos])) {
                    $selected[] = (int) $map[$pos];
                }
            }
            $selected = array_values(array_unique($selected));
        }

        $this->finalizeTodayFocus($user, $chatId, $selected);
    }

    /** @param  list<int>  $selected */
    private function finalizeTodayFocus(User $user, string $chatId, array $selected): void
    {
        try {
            $result = $this->weekly->lockTodayFocus($user, $selected);
        } catch (ValidationException $e) {
            $this->telegram->sendMessage($chatId, collect($e->errors())->flatten()->first() ?: 'Could not lock today.');

            return;
        }

        $this->clearState($chatId);
        $quote = MotivationalQuote::forDate();
        $this->telegram->sendMessage(
            $chatId,
            "Today locked ✅\n\n".$this->weekly->renderTable($result['plan'], $user)."\n\n💬 <i>{$quote}</i>",
            $this->linkedKeyboard()
        );
    }

    private function handleFocusCallback(User $user, string $chatId, string $callbackId, string $data, int $messageId): void
    {
        $state = $this->getState($chatId);
        if (($state['phase'] ?? '') !== 'awaiting_today_focus') {
            $this->telegram->answerCallback($callbackId, 'Session expired — tap Today\'s tasks');

            return;
        }

        $plan = WeeklyPlan::query()->with('goals')->find($state['plan_id'] ?? 0);
        if (! $plan) {
            $this->telegram->answerCallback($callbackId, 'Plan gone');

            return;
        }

        $goals = $this->weekly->tableGoals($plan);
        $focusIds = array_map('intval', $state['focus_ids'] ?? []);
        $action = substr($data, 3);

        if ($action === 'all') {
            $focusIds = $goals->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->telegram->answerCallback($callbackId, 'All selected');
        } elseif ($action === 'lock') {
            $this->telegram->answerCallback($callbackId, 'Locking…');
            $this->finalizeTodayFocus($user, $chatId, $focusIds);

            return;
        } elseif (ctype_digit($action)) {
            $gid = (int) $action;
            if ($goals->firstWhere('id', $gid)?->source === 'admin') {
                $this->telegram->answerCallback($callbackId, 'Admin rows stay on');

                return;
            }
            if (in_array($gid, $focusIds, true)) {
                $focusIds = array_values(array_filter($focusIds, fn ($id) => $id !== $gid));
            } else {
                $focusIds[] = $gid;
            }
            $this->telegram->answerCallback($callbackId, 'Updated');
        } else {
            $this->telegram->answerCallback($callbackId);

            return;
        }

        $state['focus_ids'] = $focusIds;
        $this->setState($chatId, $state);

        $body = "Good morning <b>{$user->name}</b>.\n\n"
            .$this->weekly->renderTable($plan)
            ."\n\nReply with numbers or tap below, then <b>Lock today</b>.";
        if ($messageId > 0) {
            $this->telegram->editMessageText($chatId, $messageId, $body, $this->focusKeyboard($goals, $focusIds));
        }
    }

    private function handleWeeklyEveningCallback(User $user, string $chatId, string $callbackId, string $data, int $messageId): void
    {
        $day = $this->days->getOrCreateToday($user);
        if ($day->closed_at) {
            $this->telegram->answerCallback($callbackId, 'Day already closed');

            return;
        }

        if ($data === 'wx:close') {
            $planned = $day->tasks()->where('result', 'planned')->count();
            $blockedMissing = $day->tasks()
                ->where('result', 'not_done')
                ->where(function ($q) {
                    $q->whereNull('reason_note')->orWhere('reason_note', '');
                })
                ->count();
            if ($planned > 0 || $blockedMissing > 0) {
                $this->telegram->answerCallback($callbackId, 'Finish every task first');
                $this->telegram->sendMessage($chatId, 'Mark every row ✅ or ❌ (with a roadblock note) before finishing the day.');

                return;
            }

            $day->update(['closed_at' => now('UTC')]);
            $this->clearState($chatId);
            $done = $day->tasks()->where('result', 'done')->count();
            $total = $day->tasks()->where('result', '!=', 'dropped')->count();
            $this->telegram->answerCallback($callbackId, 'Day closed');
            $this->telegram->sendMessage(
                $chatId,
                "Saved: {$done}/{$total} done.\n\nGood night <b>{$user->name}</b> 🌙",
                $this->linkedKeyboard()
            );

            return;
        }

        if (! preg_match('/^wx:(\d+):(d|n|info)$/', $data, $m)) {
            $this->telegram->answerCallback($callbackId);

            return;
        }

        $task = Task::query()->find((int) $m[1]);
        if (! $task || $task->user_id !== $user->id) {
            $this->telegram->answerCallback($callbackId, 'Unknown task');

            return;
        }

        if ($m[2] === 'info') {
            $this->telegram->answerCallback($callbackId, $task->title);

            return;
        }

        if ($m[2] === 'd') {
            $task->update([
                'result' => 'done',
                'reason_code' => null,
                'reason_note' => null,
                'blocked' => false,
                'blocker_note' => null,
            ]);
            $this->telegram->answerCallback($callbackId, 'Done');
            $this->askWeeklyEvening($user, $day->fresh('tasks'));

            return;
        }

        $this->setState($chatId, [
            'phase' => 'awaiting_roadblock',
            'day_id' => $day->id,
            'task_id' => $task->id,
            'mode' => 'weekly',
        ]);
        $this->telegram->answerCallback($callbackId, 'Not done');
        $this->telegram->sendMessage(
            $chatId,
            'What roadblock stopped <b>'.e($task->title).'</b>?\n(Reply with a short explanation.)'
        );
    }

    /** @param  \Illuminate\Support\Collection<int, WeeklyGoal>  $goals
     *  @param  list<int>  $focusIds */
    private function focusKeyboard($goals, array $focusIds): array
    {
        $rows = [];
        $chunk = [];
        $i = 0;
        foreach ($goals as $goal) {
            $i++;
            $on = in_array((int) $goal->id, $focusIds, true);
            $chunk[] = [
                'text' => ($on ? "{$i} ✓" : (string) $i),
                'callback_data' => 'wf:'.$goal->id,
            ];
            if (count($chunk) === 4) {
                $rows[] = $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $rows[] = $chunk;
        }
        $rows[] = [
            ['text' => 'All', 'callback_data' => 'wf:all'],
            ['text' => 'Lock today', 'callback_data' => 'wf:lock'],
        ];

        return ['inline_keyboard' => $rows];
    }

    private function pingManagers(string $html, ?int $exceptUserId = null): void
    {
        $managers = User::query()
            ->where('role', 'manager')
            ->where('active', true)
            ->whereNotNull('telegram_chat_id')
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->get();

        // If the only linked manager is the submitter, still notify them so the ping is visible.
        if ($managers->isEmpty() && $exceptUserId) {
            $managers = User::query()
                ->where('role', 'manager')
                ->where('active', true)
                ->whereNotNull('telegram_chat_id')
                ->where('id', $exceptUserId)
                ->get();
        }

        foreach ($managers as $mgr) {
            $this->telegram->sendMessage($mgr->telegram_chat_id, '🔔 '.$html);
        }
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
