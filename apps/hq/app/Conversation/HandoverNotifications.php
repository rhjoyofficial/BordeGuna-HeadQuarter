<?php

namespace App\Conversation;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Support\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The hand-over ping (docs/95 §5 "Hand-over" and §16 item 4, stream W2a): when a thread enters
 * `human_required`, a person is told, once, on every notification channel HQ has.
 *
 * Once per thread per STATE ENTRY, never per message. The entry is the transition the state machine
 * audited (its audit row id is the key), or the opening of a new thread straight into `human_required`
 * while the support agent is paused (the thread's uuid is the key, because a thread opens once). A
 * second customer message on a thread that already needs a person is not a second entry and sends
 * nothing; the thread leaving and re-entering the state is a new entry and does.
 *
 * ⚠ Sent AFTER COMMIT. The conversation simulator replays scenarios inside a transaction it rolls
 * back, and a ping for a thread that never existed would be a lie in a person's Telegram; a transition
 * the database refuses must not be announced either. Inside the test transaction Laravel runs the
 * callback when the inner transaction commits, so a test sees the ping; a rollback discards it.
 *
 * ⚠ The in-app channel's audit row is the dedupe record, so the rule holds across processes and
 * restarts without a table of its own: the key is written with the notification and read before it.
 *
 * Switched off, without a deploy, by `hq.inbox.handover_notify`.
 */
final class HandoverNotifications
{
    public const CONFIG = 'hq.inbox.handover_notify';

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @param  string  $entry  what entered the state: `transition:<audit id>` or `opened`
     */
    public function threadNeedsPerson(Conversation $conversation, ?string $reasonText, string $entry): void
    {
        if (! (bool) config(self::CONFIG, true)) {
            return;
        }

        $key = "conversation:{$conversation->uuid}:{$entry}";
        $reason = HandoverReason::fromText($reasonText);
        $uuid = (string) $conversation->uuid;

        DB::afterCommit(function () use ($uuid, $key, $reason) {
            try {
                if ($this->alreadySent($key)) {
                    return;
                }
                $conversation = Conversation::query()->where('uuid', $uuid)->first();
                if ($conversation === null) {
                    return;
                }
                $this->notifier->handoverRequired($conversation, $reason, ['handover_key' => $key]);
            } catch (Throwable $e) {
                // A notification that failed must never undo the hand-over it reports on.
                Log::warning('handover notification failed', ['conversation' => $uuid, 'exception' => get_class($e), 'error' => $e->getMessage()]);
            }
        });
    }

    private function alreadySent(string $key): bool
    {
        return AuditLog::query()
            ->where('action', 'notification.raised')
            ->where('after->handover_key', $key)
            ->exists();
    }
}
