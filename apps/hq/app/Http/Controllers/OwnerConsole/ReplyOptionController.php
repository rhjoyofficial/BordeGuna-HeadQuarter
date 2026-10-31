<?php

namespace App\Http\Controllers\OwnerConsole;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\ReplyOptionSet;
use App\OwnerConsole\Refused;
use App\OwnerConsole\Replies\ReplyOptionService;
use App\Support\Authorization\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reply options A/B/C on an inbox thread (docs/143 E). A thread or a set of a business the person may not
 * see is not there (404). The browser supplies a set, a label and, optionally, the person's edited text; the
 * send itself is `messaging.send_reply` through the gateway as that person, inside `ReplyOptionService`.
 */
final class ReplyOptionController extends Controller
{
    public function __construct(private readonly ReplyOptionService $options, private readonly BusinessScope $scope) {}

    /** Turns the proposals a Support run already recorded on this thread into one set of options. */
    public function store(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->assertVisible($request, $conversation->business_id);
        $data = $request->validate(['run_id' => ['required', 'integer']]);
        $run = AgentRun::query()->whereKey($data['run_id'])->where('business_id', $conversation->business_id)->first();
        if ($run === null) {
            abort(404);
        }

        try {
            $this->options->fromRun($run, $conversation);
        } catch (Refused $e) {
            throw ValidationException::withMessages(['options' => $e->getMessage()]);
        }

        return back()->with('status', 'reply-options-proposed');
    }

    public function choose(Request $request, Conversation $conversation, ReplyOptionSet $optionSet): RedirectResponse
    {
        $set = $optionSet;
        $this->assertSet($request, $conversation, $set);
        $data = $request->validate(['label' => ['required', 'in:A,B,C'], 'text' => ['nullable', 'string', 'max:'.ReplyOptionService::MAX_TEXT]]);

        try {
            $done = $this->options->choose($set, $data['label'], $data['text'] ?? null, $request->user());
        } catch (Refused $e) {
            throw ValidationException::withMessages(['options' => $e->getMessage()]);
        }

        return back()->with('status', $done->state === 'chosen' ? 'reply-sent' : 'reply-not-sent');
    }

    public function discard(Request $request, Conversation $conversation, ReplyOptionSet $optionSet): RedirectResponse
    {
        $set = $optionSet;
        $this->assertSet($request, $conversation, $set);

        try {
            $this->options->discard($set, $request->user());
        } catch (Refused $e) {
            throw ValidationException::withMessages(['options' => $e->getMessage()]);
        }

        return back()->with('status', 'reply-options-discarded');
    }

    private function assertSet(Request $request, Conversation $conversation, ReplyOptionSet $set): void
    {
        $this->assertVisible($request, $conversation->business_id);
        if ($set->conversation_id !== $conversation->id) {
            abort(404);
        }
    }

    private function assertVisible(Request $request, ?int $businessId): void
    {
        $all = $this->scope->all($request->user());
        if ($all !== null && ($businessId === null || ! in_array($businessId, $all, true))) {
            abort(404);
        }
    }
}
