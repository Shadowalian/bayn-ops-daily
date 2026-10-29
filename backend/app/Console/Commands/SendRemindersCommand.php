<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\AppTime;
use App\Services\TelegramBotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendRemindersCommand extends Command
{
    protected $signature = 'ops:reminders {slot? : morning|evening} {--force : Ignore daily lock}';

    protected $description = 'Send Telegram morning/evening prompts within admin from–to windows (or manual slot)';

    public function handle(TelegramBotService $bot): int
    {
        if (! AppTime::isWorkingDay()) {
            $this->info('Not a working day; skipping.');

            return self::SUCCESS;
        }

        $slot = $this->argument('slot');

        if ($slot) {
            return $this->sendSlot($bot, $slot);
        }

        $windows = Setting::reminderWindows();
        $nowHm = AppTime::now()->format('H:i');

        foreach (['morning', 'evening'] as $name) {
            if (Setting::timeInWindow($nowHm, $windows[$name])) {
                $this->sendSlot($bot, $name);
            }
        }

        return self::SUCCESS;
    }

    private function sendSlot(TelegramBotService $bot, string $slot): int
    {
        if (! in_array($slot, ['morning', 'evening'], true)) {
            $this->error("Unknown slot: {$slot}");

            return self::FAILURE;
        }

        $lockKey = 'ops_reminder_sent:'.$slot.':'.AppTime::today();
        if (! $this->option('force') && ! $this->argument('slot') && Cache::has($lockKey)) {
            $this->info("Already sent {$slot} today.");

            return self::SUCCESS;
        }

        if (! $this->argument('slot') && Cache::has($lockKey) && ! $this->option('force')) {
            return self::SUCCESS;
        }

        $count = $slot === 'morning' ? $bot->promptAllMorning() : $bot->promptAllEvening();
        Cache::put($lockKey, true, now()->endOfDay());
        $this->info("Sent {$slot} prompts to {$count} member(s).");

        return self::SUCCESS;
    }
}
