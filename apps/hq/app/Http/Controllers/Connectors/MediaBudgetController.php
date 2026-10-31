<?php

namespace App\Http\Controllers\Connectors;

use App\Media\Budget\MediaBudgetRefused;
use App\Media\Budget\MediaBudgets;
use App\Media\Budget\MediaCost;
use App\Models\MediaBudgetEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ruling 31 Oct item 12 (docs/147-r2): the owner's two acts on a business's media budget, on the Connectors screen. Setting the
 * monthly limit in BDT, and recording what a provider actually billed for one ledger line. A person's acts only: no tool, job or
 * agent reaches either (`MediaBudgetTest`). Business scope fails closed (another business is 404).
 */
final class MediaBudgetController extends ConnectorController
{
    public function setLimit(Request $request, MediaBudgets $budgets, string $business): RedirectResponse
    {
        $data = $request->validate([
            'monthly_limit_bdt' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $target = $this->scopedBusiness($request->user(), $business);

        try {
            $budgets->setLimit($request->user(), $target, (string) $data['monthly_limit_bdt'], (string) $data['reason']);
        } catch (MediaBudgetRefused $e) {
            throw ValidationException::withMessages(['media_budget' => $e->getMessage()]);
        }

        return back()->with('status', 'media-budget-set');
    }

    public function reconcile(Request $request, MediaBudgets $budgets, string $entry): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'actual_currency' => ['required', 'string', 'size:3', 'alpha'],
        ]);
        $row = MediaBudgetEntry::query()->visibleTo($request->user())->where('uuid', $entry)->first();
        abort_if($row === null, 404);

        try {
            $budgets->reconcile($row, new MediaCost((string) $data['actual_amount'], strtoupper((string) $data['actual_currency']), 'provider reported'), $request->user());
        } catch (MediaBudgetRefused $e) {
            throw ValidationException::withMessages(['media_budget' => $e->getMessage()]);
        }

        return back()->with('status', 'media-cost-reconciled');
    }
}
