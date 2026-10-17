<?php

namespace App\Http\Controllers\Admin;

use App\Gateway\OutcomeStatus;
use App\Gateway\Policy\PolicyRepository;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Registry\ToolSwitch;
use App\Gateway\ToolGateway;
use App\Http\Controllers\Controller;
use App\Models\ToolCall;
use App\Support\Authorization\BusinessScope;
use App\Tools\ToolRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Tool Registry screen: what HQ can do, at what risk, and the audited runtime kill switch.
 *
 * Read-only for `hq.tools.view`; switching needs `hq.tools.manage`. The full registry screen with
 * schemas and per-call history is M2 work; this is the half that makes "an operator can disable a
 * tool without a deployment" true rather than aspirational.
 */
final class ToolRegistryController extends Controller
{
    public function __construct(
        private readonly ToolGateway $gateway,
        private readonly ToolAvailability $availability,
        private readonly ToolSwitch $switch,
        private readonly PolicyRepository $policies,
        private readonly BusinessScope $businessScope,
    ) {}

    public function index(Request $request): Response
    {
        $state = $this->availability->overview();
        $registry = app(ToolRegistry::class);

        // One grouped query rather than three per tool: the screen lists every registered tool and
        // a per-tool count each would be a query multiplied by the registry's size.
        // docs/136: the calls of the businesses this person may see; a call with no business only for a group-wide person.
        $calls = fn () => $this->businessScope->restrict(ToolCall::query(), $request->user());
        $recent = $calls()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('tool_key, count(*) as calls, sum(case when status in (?, ?) then 1 else 0 end) as ok, max(created_at) as last_at', [
                OutcomeStatus::Succeeded->value, OutcomeStatus::Replayed->value,
            ])
            ->groupBy('tool_key')
            ->get()
            ->keyBy('tool_key');

        $tools = [];
        foreach ($this->gateway->descriptors() as $key => $descriptor) {
            $row = $recent->get($key);
            $tool = $registry->get($key);

            $tools[] = $descriptor->toArray() + [
                'runtime' => $state[$key] ?? ['enabled' => true, 'has_row' => false, 'disabled_reason' => null, 'disabled_at' => null],
                'calls_24h' => (int) ($row->calls ?? 0),
                'ok_24h' => (int) ($row->ok ?? 0),
                'last_call_at' => $row->last_at ?? null,
                'input_schema' => $tool->inputSchema(),
                'output_schema' => $tool->outputSchema(),
                'governing_policy' => $this->governingPolicy($key),
                'recent' => $calls()
                    ->where('tool_key', $key)
                    ->latest('id')
                    ->limit(5)
                    ->get(['id', 'status', 'policy_result', 'caller_kind', 'actor_label', 'duration_ms', 'created_at'])
                    ->toArray(),
            ];
        }

        // Filtering is applied after assembly because the descriptor list is small and comes from
        // code rather than from the database.
        $filter = trim((string) $request->query('q', ''));
        $only = (string) $request->query('state', 'all');
        if ($filter !== '') {
            $tools = array_values(array_filter($tools, fn (array $t) => str_contains(strtolower($t['key'].' '.$t['title'].' '.$t['purpose']), strtolower($filter))));
        }
        if ($only === 'enabled' || $only === 'disabled') {
            $want = $only === 'enabled';
            $tools = array_values(array_filter($tools, fn (array $t) => (bool) $t['runtime']['enabled'] === $want));
        }

        return Inertia::render('tools/index', [
            'tools' => $tools,
            'orphans' => $this->availability->orphans(),
            'environment' => app()->environment(),
            'filters' => ['q' => $filter, 'state' => $only],
        ]);
    }

    /**
     * The policy row that would govern this tool right now, with no business named.
     *
     * Shown so an operator can see that a tool is under a guardrail without reading the database,
     * and see which VERSION is in force.
     */
    private function governingPolicy(string $toolKey): ?array
    {
        $policy = $this->policies->forCall($toolKey, null);

        return $policy === null ? null : [
            'key' => $policy->key,
            'version' => $policy->version,
            'rules' => $policy->rules,
            'active_from' => $policy->active_from?->toIso8601String(),
        ];
    }

    public function toggle(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['enabled']) {
            $this->switch->enable($key, $data['reason'] ?? null, $request->user());

            return back()->with('status', 'tool-enabled');
        }

        // Disabling without a reason is refused here as well as in the service, so the screen can
        // say why rather than showing a 500 from an exception the user cannot act on.
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why this tool is being disabled. The record has to answer that question later.']);
        }

        $this->switch->disable($key, (string) $data['reason'], $request->user());

        return back()->with('status', 'tool-disabled');
    }
}
