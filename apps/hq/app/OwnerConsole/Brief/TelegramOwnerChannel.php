<?php

namespace App\OwnerConsole\Brief;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram to the owner's own chat only (docs/143 D, ruling 10). Needs, from configuration:
 *   hq.owner_console.telegram.bot_token      the owner's bot (a secret; env only, never logged)
 *   hq.owner_console.telegram.chat_id        the owner's PRIVATE chat: a positive numeric id
 *   hq.owner_console.telegram.chat_verified  true only after the owner confirmed that chat received a test
 *
 * A group, a supergroup or a channel has a negative id, so it is refused: the brief goes to one person.
 * An unconfirmed chat is refused. The URL carries the token, so no log line carries a response body, a URL
 * or an exception message (the same rule as `TelegramChannel`).
 *
 * Credential needed to make it real: the bot token and the owner's private chat id, plus
 * `HQ_OWNER_TELEGRAM_CHAT_VERIFIED=true` once the owner saw the test message.
 */
final class TelegramOwnerChannel implements OwnerChannel
{
    private const TIMEOUT_SECONDS = 8;

    public function name(): string
    {
        return 'telegram';
    }

    /** Why the destination is not usable, or null when it is the owner's confirmed private chat. */
    public static function destinationRefusal(): ?string
    {
        $chat = (string) config('hq.owner_console.telegram.chat_id', '');
        if ((string) config('hq.owner_console.telegram.bot_token', '') === '') {
            return 'The owner Telegram bot token is not configured.';
        }
        if (preg_match('/^[1-9]\d{4,14}$/', $chat) !== 1) {
            return 'The owner chat must be a private chat id (digits only); groups and channels are refused.';
        }
        if (config('hq.owner_console.telegram.chat_verified') !== true) {
            return 'The owner chat has not been confirmed as the owner\'s own.';
        }

        return null;
    }

    public function send(string $text): OwnerSend
    {
        if (($why = self::destinationRefusal()) !== null) {
            return OwnerSend::refused($why);
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->asJson()->post(
                'https://api.telegram.org/bot'.config('hq.owner_console.telegram.bot_token').'/sendMessage',
                ['chat_id' => (string) config('hq.owner_console.telegram.chat_id'), 'text' => $text, 'disable_web_page_preview' => true],
            );
        } catch (ConnectionException) {
            // A timeout may have delivered: uncertain, not failed, and never repeated blindly.
            return OwnerSend::uncertain('The Telegram request did not complete; delivery is unknown.');
        } catch (Throwable $e) {
            Log::warning('owner telegram brief failed', ['exception' => get_class($e)]);

            return OwnerSend::refused('The Telegram request failed.');
        }

        if ($response->successful()) {
            return OwnerSend::sent((string) data_get($response->json(), 'result.message_id', ''));
        }
        Log::warning('owner telegram brief rejected', ['status' => $response->status()]);

        return OwnerSend::refused('Telegram refused the message (status '.$response->status().').');
    }
}
