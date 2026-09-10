<?php

namespace App\Platform;

use App\Models\Brand;
use App\Models\PlatformAccount;

/**
 * Which connected account satisfies a business capability, right now (ADR-029).
 *
 * ## The direction that was missing
 *
 * `CapabilityResolver` answers "what can THIS ACCOUNT do", which is the right question when you
 * already hold an account. The question a capability-shaped architecture actually asks is the
 * inverse: **"who can publish for this brand?"** Without it, something upstream has to pick the
 * account - and whatever picks the account has made the vendor decision, whether it meant to or not.
 *
 * ⛔ **`publish_content` must never become "call Meta endpoint X".** An agent names a capability and
 * a scope. This resolves that to an implementation under the permissions and approvals in force at
 * that moment, or refuses and says why. A new provider, a new account or a new protocol is then a
 * row and an adapter, not a change to anything an agent knows.
 *
 * ## Every rule here fails closed
 *
 * - No provider registered for a platform -> that account cannot serve anything. HQ having no code
 *   for a platform and an account being permitted are not the same answer.
 * - Capabilities never recorded -> the account serves nothing. "Nobody wrote down what this account
 *   holds" must not resolve like "it holds everything".
 * - Two equally-valid candidates -> **refused, not chosen** (see `CapabilityRouting::ambiguous`).
 * - Nothing matches -> unavailable, with a per-candidate reason so somebody can act on it.
 *
 * ## What it is not
 *
 * ⚠ Routing is not authorization. This says which implementation COULD serve the capability; the
 * Tool Gateway still decides whether this caller, at this identity, under this policy, consent,
 * autonomy and approval, may actually invoke it. A router that answered "yes" would be a second
 * decision point about permission, and there is exactly one of those.
 */
final readonly class CapabilityRouter
{
    public function __construct(
        private PlatformRegistry $registry,
        private CapabilityResolver $resolver,
    ) {}

    public function route(CapabilityRequest $request): CapabilityRouting
    {
        $candidates = [];
        $rejected = [];

        foreach ($this->accountsInScope($request) as $account) {
            if (! $this->registry->has($account->platform)) {
                $rejected[] = $this->reject($account, "HQ has no provider implementation for [{$account->platform}]");

                continue;
            }

            $resolution = $this->resolver->resolve($account);

            if (! $resolution->supports($request->capability)) {
                $rejected[] = $this->reject(
                    $account,
                    // Per-operation reason first; then the whole-account one, which
                    // `CapabilityResolution::none()` files under `*` (an inactive account, an
                    // unregistered platform); then a plain statement. Never an empty reason: a
                    // rejection nobody can act on is the thing this list exists to avoid.
                    $resolution->reasonFor($request->capability)
                        ?? $resolution->reasonFor('*')
                        ?? "does not currently provide [{$request->capability}]",
                );

                continue;
            }

            $candidates[] = $account;
        }

        if ($candidates === []) {
            return CapabilityRouting::unavailable(
                $rejected === []
                    ? "No connected account in scope could serve [{$request->capability}]. None is configured."
                    : "No connected account in scope can currently serve [{$request->capability}].",
                $rejected,
            );
        }

        if (count($candidates) === 1) {
            return CapabilityRouting::to($candidates[0], $rejected);
        }

        // A caller may break the tie by naming the account. It cannot use this to reach an account
        // that failed the checks above: preference is applied to the candidates, never to the pool.
        if ($request->preferAccountRef !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate->account_ref === $request->preferAccountRef) {
                    return CapabilityRouting::to($candidate, $rejected);
                }
            }
        }

        return CapabilityRouting::ambiguous($request->capability, $candidates, $rejected);
    }

    /**
     * The accounts a request may consider.
     *
     * ⚠ Scope narrows and never widens. A request naming a brand sees that brand's accounts; one
     * naming a business sees that business's brands' accounts; one naming neither sees every active
     * account, which is correct for a group-wide question and is exactly why the Tool Gateway, not
     * this class, decides who may ask it.
     *
     * @return list<PlatformAccount>
     */
    private function accountsInScope(CapabilityRequest $request): array
    {
        $query = PlatformAccount::query()->where('is_active', true);

        if ($request->brandId !== null) {
            $query->where('brand_id', $request->brandId);
        } elseif ($request->businessId !== null) {
            $query->whereIn('brand_id', Brand::where('business_id', $request->businessId)->pluck('id'));
        }

        // Ordered so a routing decision is reproducible: the same rows must always produce the same
        // answer, or an ambiguity that was refused once could be resolved differently the next time.
        return $query->orderBy('platform')->orderBy('account_ref')->get()->all();
    }

    private function reject(PlatformAccount $account, string $reason): array
    {
        return [
            'account' => $account->account_ref,
            'platform' => $account->platform,
            'reason' => $reason,
        ];
    }
}
