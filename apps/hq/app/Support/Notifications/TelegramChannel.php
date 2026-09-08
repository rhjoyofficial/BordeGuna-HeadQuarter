<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram, which is decision V.4's channel and is configured in production.
 *
 * ⚠ Corrects an earlier record: `docs/48`, `docs/49` and the system dossier all state that the bot
 * token and chat id are NOT set. They are, and were verified on the production host on 8 September
 * 2026 (a 46-character token of the right shape and a numeric chat id). The earlier statement was
 * written from the configuration file's defaults rather than from the deployed environment, which is
 * exactly the mistake `docs/45` recorded about the ERP hostname: **never infer an environment value
 * from a config default.**
 *
 * The bot token is read from configuration and never logged, never surfaced in an error, and never
 * included in a notification body. A failure to deliver is logged WITHOUT the response body, because
 * Telegram echoes the request URL, which contains the token.
 */
final class TelegramChannel implements NotificationChannel
{
    private const TIMEOUT_SECONDS = 5;

    public function name(): string
    {
        return 'telegram';
    }

    public function available(): bool
    {
        return $this->token() !== '' && $this->chatId() !== '';
    }

    public function send(string $subject, string $body, array $context = []): bool
    {
        if (! $this->available()) {
            return false;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post("https://api.telegram.org/bot{$this->token()}/sendMessage", [
                    'chat_id' => $this->chatId(),
                    'text' => trim($subject."\n\n".$body),
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful()) {
                return true;
            }

            // Status only. The response body and the request URL both contain the bot token.
            Log::warning('telegram notification rejected', ['status' => $response->status(), 'subject' => $subject]);
        } catch (Throwable $e) {
            // Class only, not the message: an HTTP client exception embeds the URL, and the URL
            // embeds the token.
            Log::warning('telegram notification failed', ['exception' => get_class($e), 'subject' => $subject]);
        }

        return false;
    }

    private function token(): string
    {
        return (string) config('hq.alerts.telegram_bot_token', '');
    }

    private function chatId(): string
    {
        return (string) config('hq.alerts.telegram_chat_id', '');
    }
}
