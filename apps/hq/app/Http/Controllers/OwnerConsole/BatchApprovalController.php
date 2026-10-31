<?php

namespace App\Http\Controllers\OwnerConsole;

use App\Gateway\Approval\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\OwnerConsole\Flag;
use App\Tools\ToolRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The batch overview of waiting customer messages (docs/143 B as ruled on 31 Oct 2026, ruling 2: SEQUENCE ONLY).
 *
 * READ-ONLY. It lists the pending customer-message approvals this person may see, in full, each linking to its own
 * approval page with `?queue=messages`. A message is approved there, on its own, through the one approve action
 * (`ApprovalController::approve`: one approval, one payload hash, one gateway pre-check, one `ApprovalExecutor` call,
 * one audit result); the page then offers the next message. There is no multi-select, no shared batch authorization
 * and no write route here: the old multi-approval executor and its endpoint were removed (ruling 2), as was the
 * approve-in-place card action (ruling 3). Held by `tests/Feature/OwnerConsole/ApprovalPathStructureTest.php`.
 */
final class BatchApprovalController extends Controller
{
    public function __construct(private readonly ToolRegistry $tools) {}

    public function index(Request $request): Response
    {
        $messages = [];
        if (Flag::on(Flag::BATCH_APPROVAL)) {
            $messages = Approval::query()->visibleTo($request->user())->where('status', ApprovalStatus::Pending->value)
                ->with('business:id,name')->orderBy('id')->limit(100)->get()
                ->filter(fn (Approval $a) => $this->tools->has($a->tool_key) && $this->tools->get($a->tool_key)->descriptor()->customerFacing)
                ->map(fn (Approval $a) => [
                    'id' => $a->id, 'tool_key' => $a->tool_key, 'business' => $a->business?->name, 'agent_key' => $a->agent_key,
                    'payload' => $a->payload, 'created_at' => $a->created_at?->toIso8601String(),
                    'decidable' => $a->isDecidableBy($request->user()), 'href' => "/approvals/{$a->id}?queue=messages",
                ])->values()->all();
        }

        return Inertia::render('approvals/batch', ['enabled' => Flag::on(Flag::BATCH_APPROVAL), 'messages' => $messages]);
    }
}
