<?php

namespace App\Http\Controllers\Connectors;

use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * What the connector screens share: a screen never acts by itself, it asks the Tool Gateway as the signed-in person
 * (so permission, flag, approval, audit and business scope all apply), and says plainly what came back.
 */
abstract class ConnectorController extends Controller
{
    /** @param  array<string, mixed>  $args */
    protected function runTool(User $user, string $tool, array $args, ?Business $business = null): ToolOutcome
    {
        return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, $tool, $args, $business?->id));
    }

    /**
     * A refusal becomes a validation error the screen shows; a request that now waits for a person's approval is a
     * success of its own kind (`approval-requested`); anything else succeeded.
     */
    protected function outcomeResponse(ToolOutcome $outcome, string $field, string $doneStatus): RedirectResponse
    {
        if ($outcome->status === OutcomeStatus::AwaitingApproval) {
            return back()->with('status', 'approval-requested');
        }
        if (! in_array($outcome->status, [OutcomeStatus::Succeeded, OutcomeStatus::Replayed], true)) {
            throw ValidationException::withMessages([$field => (string) ($outcome->error ?? $outcome->policyReason ?? 'This was refused.')]);
        }

        return back()->with('status', $doneStatus);
    }

    /** The business, 404 when it is not one this person may use (it does not exist for them). */
    protected function scopedBusiness(User $user, string $slug): Business
    {
        $business = Business::query()->where('slug', $slug)->first();
        abort_if($business === null || ! app(BusinessScope::class)->allows($user, $business->id), 404);

        return $business;
    }
}
