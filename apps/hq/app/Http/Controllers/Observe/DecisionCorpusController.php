<?php

namespace App\Http\Controllers\Observe;

use App\Decisions\DecisionCorpusReader;
use App\Decisions\DecisionCorpusWindow;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The decision-corpus page (docs/111, ruling docs/103 §7): what people kept, edited and refused of
 * what the agents proposed, and which corrections recur. It reads; it changes nothing, and it offers
 * no action. Gated on `hq.agents.view`, the permission of the other agent-evaluation screens.
 */
final class DecisionCorpusController extends Controller
{
    public function index(Request $request, DecisionCorpusReader $reader): Response
    {
        $business = $request->query('business');
        $businessId = is_string($business) && ctype_digit($business) ? (int) $business : null;

        return Inertia::render('observe/decision-corpus', $reader->read(
            DecisionCorpusWindow::from($request->query('window')),
            $request->user(),
            $businessId,
        ));
    }
}
