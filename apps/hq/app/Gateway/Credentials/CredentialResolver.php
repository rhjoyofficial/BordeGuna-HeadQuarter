<?php

namespace App\Gateway\Credentials;

use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Scope\ResolvedScope;
use App\Gateway\ToolRequest;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\Store;

/**
 * The credential boundary (docs/48 D.3 step 9, R.1).
 *
 * Resolution happens LAST, after every deterministic check has passed. Nothing above the connector
 * ever holds a secret, and this class deliberately has no method that returns one: it answers
 * "does the credential this tool declared exist, is it active, is it unexpired", and it records
 * WHICH credential id was involved.
 *
 * ✅ M2 closed M1's limitation: the connectors no longer read their own secrets. This resolves the
 * ids, the gateway opens them in the CredentialVault for exactly the length of the call, and the
 * connector asks the vault. A missing or revoked credential fails here with a named reason, before
 * a connector produces a confusing 401, and the ids land on the tool_calls row so a trace can say
 * which identity HQ used without ever saying what it was.
 */
final class CredentialResolver
{
    /**
     * @return CredentialResolution ids and status only, never a value
     */
    public function resolve(ToolDescriptor $descriptor, ToolRequest $request, array $args, ?ResolvedScope $scope = null): CredentialResolution
    {
        $found = [];
        $missing = [];

        foreach ($descriptor->credentials as $requirement) {
            $accountRef = $this->accountRef($requirement, $request, $args, $scope);

            $credential = $accountRef === null ? null : IntegrationCredential::query()
                ->where('provider', $requirement->provider)
                ->where('account_ref', $accountRef)
                ->where('status', 'active')
                ->first();

            if ($credential === null || $credential->isExpired() || ! $credential->hasPayload()) {
                if (! $requirement->optional) {
                    $missing[] = $this->describeMissing($requirement, $accountRef, $credential);
                }

                continue;
            }

            $found[] = ['provider' => $requirement->provider, 'account_ref' => $accountRef, 'credential_id' => $credential->id];
        }

        return new CredentialResolution($found, $missing);
    }

    /**
     * Where account_ref comes from. Four forms, all resolved from data the gateway already has,
     * none of them free text from a caller:
     *   `args.store_slug`  an argument path
     *   `business.slug`    the request's business
     *   `store.slug`       the request's store
     *   `=literal`         a fixed value
     *
     * A `|` separates fallbacks, tried left to right, because several tools take a business in
     * their arguments and otherwise use the one the call was filed under. Writing that as
     * `args.business_slug|business.slug` keeps the rule next to the tool that has it rather than
     * hidden in a resolver branch.
     */
    private function accountRef(CredentialRequirement $requirement, ToolRequest $request, array $args, ?ResolvedScope $scope): ?string
    {
        foreach (explode('|', $requirement->accountRefFrom) as $source) {
            $value = $this->resolveOne(trim($source), $request, $args, $scope);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveOne(string $from, ToolRequest $request, array $args, ?ResolvedScope $scope): ?string
    {
        if (str_starts_with($from, '=')) {
            return substr($from, 1);
        }

        if (str_starts_with($from, 'args.')) {
            $value = data_get($args, substr($from, 5));

            return is_string($value) && $value !== '' ? $value : null;
        }

        // The RESOLVED scope first, then the raw request. That ordering matters: the scheduled jobs
        // name their target in their arguments, so the request itself carries nothing and only the
        // resolved scope knows which business or store this call is for.
        if ($from === 'business.slug') {
            return $scope?->businessSlug
                ?? ($request->businessId ? Business::find($request->businessId)?->slug : null);
        }

        if ($from === 'store.slug') {
            return $scope?->storeSlug
                ?? ($request->storeId ? Store::find($request->storeId)?->slug : null);
        }

        return null;
    }

    private function describeMissing(CredentialRequirement $requirement, ?string $accountRef, ?IntegrationCredential $credential): string
    {
        $account = $accountRef ?? 'unresolved account';

        return match (true) {
            $accountRef === null => "credential [{$requirement->provider}] could not be matched: {$requirement->accountRefFrom} resolved to nothing",
            $credential === null => "credential [{$requirement->provider}/{$account}] is missing or not active",
            $credential->isExpired() => "credential [{$requirement->provider}/{$account}] expired",
            default => "credential [{$requirement->provider}/{$account}] has no stored value",
        };
    }
}
