<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramClient
{
    public function enabled(): bool
    {
        return (bool) config('services.telegram.bot_token');
    }

    public function token(): ?string
    {
        return config('services.telegram.bot_token');
    }

    public function api(string $method, array $payload = [], int $timeoutSeconds = 20): ?array
    {
        $token = $this->token();
        if (! $token) {
            Log::warning('telegram.no_token', ['method' => $method]);

            return null;
        }

        $response = Http::timeout($timeoutSeconds)
            ->connectTimeout(15)
            ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::warning('telegram.api_failed', [
                'method' => $method,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            // 409 = another getUpdates client is running; back off hard
            if ($response->status() === 409) {
                sleep(5);
            }

            return null;
        }

        return $response->json();
    }

    public function sendMessage(string|int $chatId, string $text, ?array $replyMarkup = null): ?array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->api('sendMessage', $payload);
    }

    public function editMessageText(string|int $chatId, int $messageId, string $text, ?array $replyMarkup = null): ?array
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->api('editMessageText', $payload);
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        $this->api('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId,
            'text' => $text,
        ]));
    }

    public function getUpdates(int $offset = 0): array
    {
        // Long-poll ~25s on Telegram side; HTTP client must wait longer than that.
        $result = $this->api('getUpdates', [
            'offset' => $offset,
            'timeout' => 25,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ], 40);

        return $result['result'] ?? [];
    }

    public function setWebhook(string $url, ?string $secretToken = null): ?array
    {
        $payload = [
            'url' => $url,
            'drop_pending_updates' => false,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ];
        if ($secretToken) {
            $payload['secret_token'] = $secretToken;
        }

        return $this->api('setWebhook', $payload);
    }

    public function deleteWebhook(): ?array
    {
        return $this->api('deleteWebhook', [
            'drop_pending_updates' => false,
        ]);
    }

    public function setMyCommands(array $commands): ?array
    {
        return $this->api('setMyCommands', [
            'commands' => json_encode($commands),
        ]);
    }
}
