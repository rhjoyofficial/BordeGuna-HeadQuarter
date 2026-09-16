<?php

namespace App\Ask;

use App\Models\AskMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Answers one Ask HQ question off the request, so a model call never holds a browser connection
 * open. Tried once: a failed answer is recorded on the reply, and asking again is a person's choice.
 */
final class AnswerAskTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $replyId) {}

    public function handle(AskHq $ask): void
    {
        $reply = AskMessage::query()->find($this->replyId);

        if ($reply !== null) {
            $ask->answer($reply);
        }
    }
}
