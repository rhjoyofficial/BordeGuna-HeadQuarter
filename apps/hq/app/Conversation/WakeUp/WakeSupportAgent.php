<?php

namespace App\Conversation\WakeUp;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

/**
 * One queued wake-up of the Support agent for one inbound customer message (docs/95 stream W2a).
 *
 * Carries two uuids and nothing else: the thread and the message. Everything it needs it reads fresh
 * when it runs, because a flag, an agent row or a thread state read at dispatch time is a fact about
 * then, not about now. Horizon may deliver it more than once; `SupportWakeUp::run()` is idempotent on
 * the message, so a second delivery finds the first run and does nothing. The job itself never retries:
 * a run that failed is a run row with its error, and a model call is not something to repeat blindly.
 */
final class WakeSupportAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $conversationUuid,
        public readonly string $messageUuid,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('hq.inbox.wake_up_queue', 'ai'));
    }

    public function handle(SupportWakeUp $wakeUp): void
    {
        Context::add(['correlation_id' => $this->correlationId ?? $this->conversationUuid, 'conversation_uuid' => $this->conversationUuid]);

        $wakeUp->run($this->conversationUuid, $this->messageUuid);
    }
}
