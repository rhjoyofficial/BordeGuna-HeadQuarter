<?php

namespace App\Http\Controllers\Admin;

use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Registry\ToolSwitch;
use App\Gateway\ToolGateway;
use App\Http\Controllers\Controller;
use App\Models\ToolCall;
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
    ) {}

    public function index(): Response
    {
        $state = $this->availability->overview();

        $tools = [];
        foreach ($this->gateway->descriptors() as $key => $descriptor) {
            $tools[] = $descriptor->toArray() + [
                'runtime' => $state[$key] ?? ['enabled' => true, 'has_row' => false, 'disabled_reason' => null, 'disabled_at' => null],
                'calls_24h' => ToolCall::where('tool_key', $key)->where('created_at', '>=', now()->subDay())->count(),
                'last_call_at' => ToolCall::where('tool_key', $key)->max('created_at'),
            ];
        }

        return Inertia::render('tools/index', [
            'tools' => $tools,
            'orphans' => $this->availability->orphans(),
            'environment' => app()->environment(),
        ]);
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
