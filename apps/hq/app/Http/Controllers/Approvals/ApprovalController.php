<?php

namespace App\Http\Controllers\Approvals;

use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalService;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\Validation\ArgumentValidator;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Business;
use App\Models\Store;
use App\Models\User;
use App\Support\Notifications\Notifier;
use App\Tools\ToolRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The Approval Center.
 *
 * ⚠ **The browser supplies an approval identifier and a decision. Nothing else.** It never supplies
 * a tool key or a payload as an execution instruction: the server loads the persisted approval and
 * executes the stored, hashed payload a human actually read (brief section 19).
 *
 * There is deliberately no endpoint here that takes `(toolKey, args)` and runs it. The only way an
 * action reaches the world through this screen is by approving a row that policy already created.
 */
final class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ApprovalExecutor $executor,
        private readonly ArgumentValidator $validator,
        private readonly ToolRegistry $registry,
        private readonly Notifier $notifier,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'pending');
        // `resultingToolCall` is loaded because the card's label depends on whether the action
        // actually ran, not merely on whether the approval was spent.
        // docs/136: only the businesses this person may see; a row with no business only for a group-wide person.
        $query = Approval::query()->visibleTo($request->user())->with([
            'requestedBy:id,email', 'enabledBy:id,email', 'decidedBy:id,email', 'delegatedTo:id,email',
            'resultingToolCall:id,status,error',
        ]);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $approvals = $query->latest('id')->limit(100)->get()->map(fn (Approval $a) => $a->toCard() + $this->readable($a) + $this->people($a) + [
            'decided_by' => $a->decidedBy?->email,
            'delegated_to' => $a->delegatedTo?->email,
            'decidable' => $request->user() !== null && $a->status->isOpen() && $a->isDecidableBy($request->user()),
        ]);

        return Inertia::render('approvals/index', [
            'approvals' => $approvals,
            'filters' => ['status' => $status],
            'counts' => [
                'pending' => Approval::pending()->visibleTo($request->user())->count(),
                'overdue' => Approval::pastSla()->visibleTo($request->user())->count(),
            ],
            'channels' => $this->notifier->channelStatus(),
            'approvers' => User::query()->get(['id', 'email'])
                ->filter(fn (User $u) => $u->can('hq.approvals.decide'))
                ->map(fn (User $u) => ['id' => $u->id, 'email' => $u->email])
                ->values(),
        ]);
    }

    public function show(Request $request, Approval $approval): Response
    {
        $this->assertVisible($request, $approval);

        return Inertia::render('approvals/show', [
            'approval' => $approval->toCard() + $this->readable($approval) + $this->people($approval) + [
                'decided_by' => $approval->decidedBy?->email,
                'delegated_to' => $approval->delegatedTo?->email,
                'decidable' => $request->user() !== null && $approval->status->isOpen() && $approval->isDecidableBy($request->user()),
                // Shown so a reviewer can see the guarantee, not so they can verify it by eye.
                'payload_intact' => $approval->payloadIntact(),
                'superseded_by' => Approval::where('supersedes_id', $approval->id)->value('id'),
            ],
        ]);
    }

    /**
     * Who asked and who enabled, kept apart (docs/81 P0-4).
     *
     * ⚠ `requester_recorded` is false for an agent's row from before the two were kept apart: its
     * requester column holds the enabling user, and the screen says so rather than naming that
     * person as the one who asked.
     *
     * @return array<string, mixed>
     */
    private function people(Approval $approval): array
    {
        return [
            'requested_by' => $approval->requestedBy?->email,
            'enabled_by' => $approval->enabledBy?->email,
            'requester_kind' => $approval->requester_kind,
            'requester_recorded' => $approval->agent_key === null || $approval->requesterRecordedApart(),
        ];
    }

    /**
     * The words a person reads on the card: the tool's title and purpose from the registry, and the
     * business and store by name. The key and the ids stay on the card underneath for anyone who
     * needs them; a tool that is no longer registered simply has no title.
     *
     * @return array<string, string|null>
     */
    private function readable(Approval $approval): array
    {
        static $businesses = null;
        static $stores = null;
        $businesses ??= Business::query()->pluck('name', 'id');
        $stores ??= Store::query()->pluck('name', 'id');

        $descriptor = $this->registry->has($approval->tool_key) ? $this->registry->get($approval->tool_key)->descriptor() : null;

        return [
            'tool_title' => $descriptor?->title,
            'tool_purpose' => $descriptor?->purpose,
            'business' => $approval->business_id !== null ? $businesses[$approval->business_id] ?? null : null,
            'store' => $approval->store_id !== null ? $stores[$approval->store_id] ?? null : null,
        ];
    }

    /**
     * Approve, optionally with an edit.
     *
     * An edit is validated against the tool's own schema before it is stored, so an edited approval
     * is held to exactly the same contract as the original proposal.
     */
    public function approve(Request $request, Approval $approval): RedirectResponse
    {
        $this->assertVisible($request, $approval);
        $this->assertDecidable($request, $approval);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'edited_payload' => ['nullable', 'array'],
        ]);

        $target = $approval;

        if (! empty($data['edited_payload'])) {
            if (! $this->registry->has($approval->tool_key)) {
                throw ValidationException::withMessages(['edited_payload' => "[{$approval->tool_key}] is no longer registered."]);
            }
            $validation = $this->validator->validate($data['edited_payload'], $this->registry->get($approval->tool_key)->inputSchema());
            if (! $validation->valid) {
                throw ValidationException::withMessages(['edited_payload' => $validation->message()]);
            }

            // Creates a SUCCESSOR: the original is never mutated, so what the person first read
            // survives, and the delta between the two is the behaviour-alignment signal.
            $target = $this->approvals->approveWithEdit($approval, $validation->normalised, $request->user(), $data['note'] ?? null);
        }

        try {
            $outcome = $this->executor->approveAndExecute($target, $request->user(), $data['note'] ?? null);
        } catch (Throwable $e) {
            return back()->with('status', 'approval-failed')->withErrors(['approval' => $e->getMessage()]);
        }

        return back()->with('status', $outcome->ok() ? 'approval-executed' : 'approval-refused')
            ->with('outcome', $outcome->toArray());
    }

    public function reject(Request $request, Approval $approval): RedirectResponse
    {
        $this->assertVisible($request, $approval);
        $this->assertDecidable($request, $approval);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        // A rejection needs a reason: it is the feedback the proposer receives, and in M5 it is what
        // an agent learns from. "No" with no reason teaches nothing.
        $this->approvals->reject($approval, $request->user(), $data['reason']);

        return back()->with('status', 'approval-rejected');
    }

    public function cancel(Request $request, Approval $approval): RedirectResponse
    {
        $this->assertVisible($request, $approval);

        if (! $approval->status->isOpen()) {
            throw ValidationException::withMessages(['approval' => "This approval is {$approval->status->label()} and can no longer be cancelled."]);
        }

        // The requester may withdraw their own request; anybody who could decide it may also cancel.
        // So may the enabling user of the agent that parked it: they could before the requester and
        // the enabler were kept apart (docs/81 P0-4), and a withdrawal executes nothing.
        $user = $request->user();
        $isRequester = $approval->requested_by_user_id === $user->getKey();
        $isEnabler = $approval->enabled_by_user_id !== null && $approval->enabled_by_user_id === $user->getKey();
        if (! $isRequester && ! $isEnabler && ! $approval->isDecidableBy($user)) {
            abort(403);
        }

        $this->approvals->cancel($approval, $user, $request->input('reason'));

        return back()->with('status', 'approval-cancelled');
    }

    public function delegate(Request $request, Approval $approval): RedirectResponse
    {
        $this->assertVisible($request, $approval);

        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $to = User::findOrFail($data['user_id']);
        // A delegate who could not open the approval could never decide it, and handing it over would
        // tell them it exists (docs/136).
        if (! Approval::query()->visibleTo($to)->whereKey($approval->getKey())->exists()) {
            throw ValidationException::withMessages(['user_id' => 'That person may not see this business, so the approval cannot be handed to them.']);
        }

        try {
            $this->approvals->delegate($approval, $to, $request->user());
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['user_id' => $e->getMessage()]);
        }

        return back()->with('status', 'approval-delegated');
    }

    /**
     * docs/136 (rulings docs/120 A4, docs/131 §1): an approval of a business this person may not see is
     * not there. 404, not 403, as on the agent, inbox and customer screens: its existence is a disclosure.
     */
    private function assertVisible(Request $request, Approval $approval): void
    {
        if (! Approval::query()->visibleTo($request->user())->whereKey($approval->getKey())->exists()) {
            abort(404);
        }
    }

    private function assertDecidable(Request $request, Approval $approval): void
    {
        if ($approval->status !== ApprovalStatus::Pending) {
            throw ValidationException::withMessages(['approval' => "This approval is {$approval->status->label()}."]);
        }
        if (($barred = $approval->decisionRefusalReason($request->user())) !== null) {
            abort(403, $barred);
        }
    }
}
