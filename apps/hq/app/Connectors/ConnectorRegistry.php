<?php

namespace App\Connectors;

use App\Connectors\Legacy\LegacyConnector;
use App\Connectors\Legacy\LegacyConnectors;
use App\Gateway\Credentials\AmbiguousCredential;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Models\Brand;
use App\Models\Business;
use App\Models\PlatformAccount;
use App\Support\Features\Features;
use InvalidArgumentException;

/**
 * The single provider-resolution path for capabilities (docs/97 §6 D2, APPROVED AND MANDATORY for new
 * integrations): Capability → ConnectorRegistry → Adapter → Transport → Tool Gateway.
 *
 * ## What it answers
 *
 * "Which connector serves `voice.transcribe` for this business right now?" The first connector, by
 * priority, that is registered for the capability, in scope, switched on and credential-ready. A
 * fallback is tried only when no primary is. Nothing registered is the typed `NoConnector`; two ready
 * connectors at the same priority are refused, never chosen (the `CapabilityRouter` and
 * `AmbiguousCredential` precedent: there is no tie-break rule right often enough to be safe), unless
 * HQ's own trusted configuration names one of the tied, ready connectors in `preferProvider`
 * (ruling 9.2; `ConnectorRequest` says how narrow that is).
 *
 * ## What it is not
 *
 * ⚠ **Resolution is not authorization.** This says which implementation COULD serve; the Tool Gateway
 * still decides whether this caller, under this policy, consent, autonomy and approval, may invoke it.
 * The credential check here reads metadata only (the vault's `active()`), never a value: the gateway
 * opens the ids it returns, and only then can the adapter ask the vault for a secret.
 *
 * ## Legacy shapes
 *
 * Five older shapes still resolve their own providers (`PlatformRegistry`, `CommerceRegistry`,
 * `ErpRegistry`, `ProviderRegistry`, `SpeechProviders`). They are not rewritten here (docs/97 §6 D2:
 * "no blind rewrite"); `legacy()` only REPORTS what they serve, through read-only bridges, so readiness
 * shows one table. Every NEW adapter enters through this class; a structural test holds that.
 *
 * Populated from `config/hq-connectors.php`, which is an empty list in this release.
 */
final class ConnectorRegistry
{
    /** @var list<ConnectorDefinition> */
    private array $connectors = [];

    /**
     * @param  list<array<string, mixed>>  $rows  `config('hq-connectors.connectors')`
     *
     * @throws InvalidArgumentException on a malformed row or a duplicate capability/provider pair
     */
    public function __construct(
        array $rows,
        private readonly Features $features,
        private readonly CredentialVault $vault,
        private readonly CredentialHealth $health,
        private readonly LegacyConnectors $legacy,
    ) {
        $seen = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException("config/hq-connectors.php connectors[{$index}] must be an array.");
            }
            $definition = ConnectorDefinition::fromArray($row, $index);
            $pair = $definition->capability.'|'.$definition->provider;
            if (isset($seen[$pair])) {
                throw new InvalidArgumentException("config/hq-connectors.php registers [{$definition->provider}] for [{$definition->capability}] twice.");
            }
            $seen[$pair] = true;
            $this->connectors[] = $definition;
        }
    }

    /** @return list<ConnectorDefinition> every registered connector, in a reproducible order */
    public function all(): array
    {
        $all = $this->connectors;
        usort($all, static fn (ConnectorDefinition $a, ConnectorDefinition $b) => [$a->capability, $a->fallback, $a->priority, $a->provider]
            <=> [$b->capability, $b->fallback, $b->priority, $b->provider]);

        return $all;
    }

    /** @return list<ConnectorDefinition> */
    public function for(string $capability): array
    {
        return array_values(array_filter($this->all(), static fn (ConnectorDefinition $c) => $c->capability === $capability));
    }

    /** @return list<LegacyConnector> what the five legacy shapes serve today, reported, not resolved */
    public function legacy(): array
    {
        return $this->legacy->all();
    }

    public function resolve(ConnectorRequest $request): ConnectorResolution
    {
        $capability = $request->capability;

        if (! Capabilities::known($capability)) {
            return ConnectorResolution::unknown($capability, "[{$capability}] is not a capability HQ knows. Capabilities are named in App\\Connectors\\Capabilities.");
        }
        if (Capabilities::reserved($capability)) {
            return ConnectorResolution::unknown($capability, "[{$capability}] is reserved: its contract is not written yet, so no connector can serve it.");
        }

        $registered = $this->for($capability);
        if ($registered === []) {
            return ConnectorResolution::noConnector($capability);
        }

        $scope = $this->scope($request);
        if (is_string($scope)) {
            // The request's own scope is inconsistent; every connector is refused for the same reason.
            return ConnectorResolution::unavailable($capability, array_map(
                static fn (ConnectorDefinition $c) => ['provider' => $c->provider, 'reason' => $scope], $registered));
        }

        $rejected = [];
        $ready = ['primary' => [], 'fallback' => []];

        foreach ($registered as $connector) {
            [$why, $credentials] = $this->check($connector, $scope);
            if ($why !== null) {
                $rejected[] = ['provider' => $connector->provider, 'reason' => $why];

                continue;
            }
            $ready[$connector->fallback ? 'fallback' : 'primary'][] = [$connector, $credentials];
        }

        foreach (['primary', 'fallback'] as $tier) {
            if ($ready[$tier] === []) {
                continue;
            }

            // ruling 9.2: a preference is read only HERE, after scope, switch and credential checks have
            // already decided who is ready, and only inside the one tier that serves. A preferred
            // fallback is therefore never reached while a primary is ready, and a preferred connector
            // that is not ready is not in this list at all.
            if ($request->preferProvider !== null) {
                foreach ($ready[$tier] as [$connector, $credentials]) {
                    if ($connector->provider === $request->preferProvider) {
                        return ConnectorResolution::resolved($connector, $credentials, $rejected, 'honoured');
                    }
                }
            }
            $preference = $this->preferenceNotHonoured($request, $registered, $rejected, $tier);

            // `all()` sorted by priority, so the first ready connector holds the best priority.
            $best = $ready[$tier][0][0]->priority;
            $tied = array_values(array_filter($ready[$tier], static fn (array $r) => $r[0]->priority === $best));

            if (count($tied) > 1) {
                return ConnectorResolution::ambiguous($capability, array_map(static fn (array $r) => $r[0], $tied), $rejected, $preference);
            }

            return ConnectorResolution::resolved($tied[0][0], $tied[0][1], $rejected, $preference);
        }

        return ConnectorResolution::unavailable($capability, $rejected, $this->preferenceNotHonoured($request, $registered, $rejected, null));
    }

    /**
     * Why a preference named on the request did not decide the answer, for the audit row; null when
     * none was named. The answer itself is then exactly what it would be with no preference at all:
     * a preference that cannot be honoured never makes a resolution worse, and never better either.
     *
     * @param  list<ConnectorDefinition>  $registered
     * @param  list<array{provider: string, reason: string}>  $rejected
     */
    private function preferenceNotHonoured(ConnectorRequest $request, array $registered, array $rejected, ?string $servingTier): ?string
    {
        $preferred = $request->preferProvider;
        if ($preferred === null) {
            return null;
        }

        foreach ($rejected as $row) {
            if ($row['provider'] === $preferred) {
                return "not honoured: [{$preferred}] is not ready ({$row['reason']})";
            }
        }
        foreach ($registered as $connector) {
            if ($connector->provider === $preferred && $servingTier === 'primary' && $connector->fallback) {
                return "not honoured: [{$preferred}] is a fallback and a primary is ready";
            }
        }

        return "not honoured: [{$preferred}] is not registered for [{$request->capability}]";
    }

    /**
     * The adapter for a resolved connector, built by the container so it gets its dependencies.
     * It holds no secret: it asks the vault, inside the scope the gateway opens with the resolution's ids.
     */
    public function adapter(ConnectorResolution $resolution): object
    {
        $connector = $resolution->connector
            ?? throw new InvalidArgumentException("[{$resolution->capability}] did not resolve ({$resolution->outcome->value}); there is no adapter to build.");

        $adapter = app($connector->adapter);
        $contract = (string) Capabilities::contract($connector->capability);
        if (! $adapter instanceof $contract) {
            throw new InvalidArgumentException("[{$connector->adapter}] does not implement [{$contract}].");
        }

        return $adapter;
    }

    /**
     * Whether a connector is switched on, for readiness. A rollout-narrowed flag reads as on here,
     * because readiness has no business; resolution checks the business.
     */
    public function enabled(ConnectorDefinition $connector, ?int $businessId = null): bool
    {
        [$source, $key] = explode(':', $connector->enabledBy, 2);

        return $source === 'flag'
            ? $this->features->enabled($key, $businessId)
            : config($key) === true;
    }

    /**
     * Why a connector cannot serve this scope, or null with the credential ids that make it ready.
     *
     * @param  array{business: ?Business, brand: ?Brand, account: ?PlatformAccount}  $scope
     * @return array{0: string|null, 1: list<array{provider: string, account_ref: string, credential_id: int}>}
     */
    private function check(ConnectorDefinition $connector, array $scope): array
    {
        $business = $scope['business'];

        if ($connector->businesses !== null && ($business === null || ! in_array($business->slug, $connector->businesses, true))) {
            return [$business === null
                ? 'serves named businesses only and the request names none'
                : "does not serve business [{$business->slug}]", []];
        }

        if ($connector->platform !== null && $scope['account']?->platform !== $connector->platform) {
            return ["serves platform [{$connector->platform}] accounts only", []];
        }

        if (! $this->enabled($connector, $business?->id)) {
            return ["switched off ({$connector->enabledBy})", []];
        }

        $found = [];
        foreach ($connector->credentials as $requirement) {
            $accountRef = $this->accountRef($requirement, $scope);
            [$why, $credentialId] = $accountRef === null
                ? ["credential [{$requirement->provider}] could not be matched: {$requirement->accountRefFrom} resolved to nothing", null]
                : $this->credential($requirement->provider, $accountRef);

            if ($why === null) {
                $found[] = ['provider' => $requirement->provider, 'account_ref' => (string) $accountRef, 'credential_id' => (int) $credentialId];
            } elseif (! $requirement->optional) {
                return [$why, []];
            }
        }

        return [null, $found];
    }

    /**
     * The same rules as `CredentialResolver`: metadata only, ambiguity refused, a known rejection is not ready.
     *
     * @return array{0: string|null, 1: int|null} why it is not ready, or null and the credential id
     */
    private function credential(string $kind, string $accountRef): array
    {
        try {
            $credential = $this->vault->active($kind, $accountRef);
        } catch (AmbiguousCredential $e) {
            return [$e->getMessage(), null];
        }

        $why = match (true) {
            $credential === null => "credential [{$kind}/{$accountRef}] is missing or not active",
            ! $credential->hasPayload() => "credential [{$kind}/{$accountRef}] has no stored value",
            $credential->isExpired() => "credential [{$kind}/{$accountRef}] expired",
            $this->health->isKnownInvalid($credential) => "credential [{$kind}/{$accountRef}] is known to be rejected by the provider",
            default => null,
        };

        return [$why, $why === null ? (int) $credential?->id : null];
    }

    /** @param  array{business: ?Business, brand: ?Brand, account: ?PlatformAccount}  $scope */
    private function accountRef(CredentialRequirement $requirement, array $scope): ?string
    {
        $from = $requirement->accountRefFrom;

        return match (true) {
            str_starts_with($from, '=') => substr($from, 1),
            $from === 'business.slug' => $scope['business']?->slug,
            $from === 'brand.slug' => $scope['brand']?->slug,
            $from === 'platform_account.ref' => $scope['account']?->account_ref,
            default => null,
        };
    }

    /**
     * The request's scope as models, narrowing only. A brand implies its business and an account its
     * brand; a request whose parts disagree is refused rather than reconciled.
     *
     * @return array{business: ?Business, brand: ?Brand, account: ?PlatformAccount}|string a reason when inconsistent
     */
    private function scope(ConnectorRequest $request): array|string
    {
        $account = $request->platformAccountId === null ? null : PlatformAccount::find($request->platformAccountId);
        if ($request->platformAccountId !== null && ($account === null || ! $account->is_active)) {
            return "platform account [{$request->platformAccountId}] does not exist or is not active";
        }

        $brandId = $request->brandId ?? $account?->brand_id;
        if ($account !== null && $request->brandId !== null && $account->brand_id !== $request->brandId) {
            return "platform account [{$account->account_ref}] does not belong to brand [{$request->brandId}]";
        }

        $brand = $brandId === null ? null : Brand::find($brandId);
        if ($brandId !== null && $brand === null) {
            return "brand [{$brandId}] does not exist";
        }

        $businessId = $request->businessId ?? $brand?->business_id;
        if ($brand !== null && $request->businessId !== null && $brand->business_id !== $request->businessId) {
            return "brand [{$brand->slug}] does not belong to business [{$request->businessId}]";
        }

        $business = $businessId === null ? null : Business::find($businessId);
        if ($businessId !== null && $business === null) {
            return "business [{$businessId}] does not exist";
        }

        return ['business' => $business, 'brand' => $brand, 'account' => $account];
    }
}
