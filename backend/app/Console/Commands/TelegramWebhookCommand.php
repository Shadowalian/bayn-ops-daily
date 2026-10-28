<?php

namespace App\Console\Commands;

use App\Services\TelegramClient;
use Illuminate\Console\Command;

class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {--set : Register webhook with Telegram} {--delete : Remove webhook}';

    protected $description = 'Register or remove the Telegram webhook URL';

    public function handle(TelegramClient $client): int
    {
        if (! $client->enabled()) {
            $this->error('TELEGRAM_BOT_TOKEN is not set.');

            return self::FAILURE;
        }

        if ($this->option('delete')) {
            $result = $client->deleteWebhook();
            $this->info($result ? 'Webhook deleted.' : 'Failed to delete webhook.');

            return $result ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('set')) {
            $this->error('Pass --set or --delete.');

            return self::FAILURE;
        }

        $base = rtrim((string) (config('app.url') ?: env('RENDER_EXTERNAL_URL')), '/');
        if ($base === '') {
            $this->error('APP_URL (or RENDER_EXTERNAL_URL) is required to set webhook.');

            return self::FAILURE;
        }

        $url = $base.'/api/v1/telegram/webhook';
        $secret = config('services.telegram.webhook_secret');
        $result = $client->setWebhook($url, $secret ?: null);

        if (! $result) {
            $this->error("Failed to set webhook to {$url}");

            return self::FAILURE;
        }

        $this->info("Webhook set: {$url}");

        return self::SUCCESS;
    }
}
