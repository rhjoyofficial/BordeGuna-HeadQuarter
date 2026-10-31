<?php

namespace App\Http\Controllers\OwnerConsole;

use App\Gateway\Approval\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\OwnerConsole\Batch\ApprovalActs;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\Tools\ToolRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Batch review of customer-message approvals and one-click approval from a console card (docs/143 B, C).
 *
 * The browser supplies approval ids and the payload hash it was SHOWN, nothing else. `ApprovalActs` decides
 * each through the one approval path; this class holds no tool key, no payload and no execute call.
 * The review page lists only approvals the person may see, so another business's messages are never here.
 */
final class BatchApprovalController extends Controller
{
    public function __construct(private readonly ApprovalActs $acts, private readonly ToolRegistry $tools) {}

    public function index(Request $request): Response
    {
        $messages = [];
        if (Flag::on(Flag::BATCH_APPROVAL)) {
            $messages = Approval::query()->visibleTo($request->user())->where('status', ApprovalStatus::Pending->value)
                ->with('business:id,name')->orderBy('id')->limit(100)->get()
                ->filter(fn (Approval $a) => $this->tools->has($a->tool_key) && $this->tools->get($a->tool_key)->descriptor()->customerFacing)
                ->map(fn (Approval $a) => [
                    'id' => $a->id, 'tool_key' => $a->tool_key, 'business' => $a->business?->name, 'agent_key' => $a->agent_key,
                    'payload' => $a->payload, 'payload_hash' => $a->payload_hash, 'created_at' => $a->created_at?->toIso8601String(),
                    'decidable' => $a->isDecidableBy($request->user()), 'href' => "/approvals/{$a->id}",
                ])->values()->all();
        }

        return Inertia::render('approvals/batch', ['enabled' => Flag::on(Flag::BATCH_APPROVAL), 'messages' => $messages, 'max' => ApprovalActs::MAX_BATCH]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.ApprovalActs::MAX_BATCH],
            'items.*.id' => ['required', 'integer'],
            'items.*.payload_hash' => ['required', 'string', 'max:128'],
        ]);

        try {
            $batch = $this->acts->batch($request->user(), $data['items']);
        } catch (Refused $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return back()->with('status', 'approval-batch-done')->with('batch', ['executed' => $batch->executed_count, 'refused' => $batch->refused_count, 'results' => $batch->results]);
    }

    public function quick(Request $request, Approval $approval): RedirectResponse
    {
        $data = $request->validate(['payload_hash' => ['required', 'string', 'max:128']]);

        try {
            $result = $this->acts->oneClick($request->user(), $approval->id, $data['payload_hash']);
        } catch (Refused $e) {
            throw ValidationException::withMessages(['approval' => $e->getMessage()]);
        }

        if ($result['result'] !== 'executed') {
            throw ValidationException::withMessages(['approval' => $result['reason'] ?? 'The approval was not carried out.']);
        }

        return back()->with('status', 'approval-executed');
    }
}
