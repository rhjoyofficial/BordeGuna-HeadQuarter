<?php

namespace App\Http\Controllers\Connectors;

use App\Connectors\ConnectorHub;
use App\Media\Budget\MediaBudgets;
use App\Tools\Connectors\CheckMetaTokenTool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The Connectors screen: honest state for every external capability, and the Meta token health (docs/144-*). */
final class ConnectorsController extends ConnectorController
{
    public function index(Request $request, ConnectorHub $hub): Response
    {
        return Inertia::render('connectors/index', [
            'families' => $hub->families($request->user()),
            'meta' => $hub->meta($request->user()),
            'can' => ['check_tokens' => $request->user()->can('hq.tools.meta.health')],
            // Ruling 31 Oct item 12: the per-business monthly media budget (owner-controlled, BDT 0 by default).
            'media_budget' => app(MediaBudgets::class)->overviewFor($request->user()),
        ]);
    }

    /** Ask Meta about one stored token, through the gateway (read-only at Meta). */
    public function checkToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(CheckMetaTokenTool::KINDS)],
            'account_ref' => ['required', 'string', 'max:64'],
        ]);

        return $this->outcomeResponse($this->runTool($request->user(), 'meta.check_token_health', $data), 'token', 'meta-token-checked');
    }
}
