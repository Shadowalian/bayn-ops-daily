<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use App\Services\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Long-poll Telegram updates (local/dev). Use a webhook in production.';

    public function handle(TelegramClient $client, TelegramBotService $bot): int
    {
        if (! $client->enabled()) {
            $this->error('Set TELEGRAM_BOT_TOKEN in .env first.');

            return self::FAILURE;
        }

        $this->info('Polling Telegram… Ctrl+C to stop.');
        $offset = (int) Cache::get('tg_update_offset', 0);

        while (true) {
            try {
                $updates = $client->getUpdates($offset);
                if ($updates === []) {
                    // empty poll or API failure — brief pause so we don't spin
                    usleep(200000);
                }
                foreach ($updates as $update) {
                    $offset = ((int) ($update['update_id'] ?? 0)) + 1;
                    Cache::put('tg_update_offset', $offset, now()->addDays(7));
                    try {
                        $bot->handleUpdate($update);
                    } catch (\Throwable $e) {
                        $this->error('Handler: '.$e->getMessage());
                    }
                }
            } catch (ConnectionException $e) {
                $this->warn('Telegram timeout/network — retrying…');
                sleep(2);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                sleep(3);
            }
        }
    }
}
