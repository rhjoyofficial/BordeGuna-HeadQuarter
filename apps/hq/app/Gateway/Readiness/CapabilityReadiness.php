<?php

namespace App\Gateway\Readiness;

use App\Ai\ProviderCheck;
use App\Ai\Providers\ProviderRegistry;
use App\Ai\ServingProvider;
use App\Commerce\Support\CommerceRegistry;
use App\Connectors\ConnectorDefinition;
use App\Connectors\ConnectorRegistry;
use App\Erp\ErpRegistry;
use App\Gateway\Credentials\AmbiguousCredential;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeClass;
use App\Gateway\Registry\ToolAvailability;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\Store;
use Throwable;

/**
 * Whether HQ COULD execute against a target right now, apart from whether an agent is safe and
 * apart from whether a call is authorised (ADR-043, docs/74 C.3).
 *
 * Gate 8 found the gap by accident, on the first agent run that ever reached a shop live: a
 * LaLaDia token that had never been accepted, two stores with no token, and nothing in HQ that
 * could have said so beforehand. This answers it deterministically, from data HQ already holds and
 * from what its own calls have taught it, for every store and every ERP business and the model
 * provider. Never a value: ids, statuses, times, classes.
 *
 * Ten states (docs/77 E.1, from ChatGPT's list of 16 September). `unsupported`: HQ has no connector
 * for the target (the Econic WooCommerce store, the ERP counter), which is by design and never an
 * incident. `disabled`: the tool switch is off for the business. `credential_missing`: no active row
 * with a value. `credential_invalid`: expired, or rejected on authentication by the provider on the
 * last verification or the last live call, and nothing newer stored. `credential_unverified`: a
 * credential exists and nothing has yet accepted or rejected it. `provider_blocked_by_credit`: the
 * provider refused because the account has no credit, which judges the account and not the key.
 * `provider_unauthorized`: the provider refused a permission the key lacks, not condemned.
 * `provider_unavailable` and `connector_error`: the last check could not get a usable answer, which
 * says nothing about the credential. `verified`: the provider accepted it. An incident is a supported
 * target a person must act on, and that is what the health command's exit code says.
 */
final class CapabilityReadiness
{
    public function __construct(
        private readonly CredentialHealth $health,
        private readonly CredentialVault $vault,
        private readonly ToolAvailability $availability,
        private readonly CommerceRegistry $commerce,
        private readonly ErpRegistry $erp,
        private readonly ProviderCheck $providerCheck,
        private readonly ServingProvider $serving,
        private readonly ProviderRegistry $providers,
        private readonly ConnectorRegistry $connectors,
    ) {}

    /** @return list<ReadinessRow> */
    public function inventory(): array
    {
        $rows = [];

        foreach (Store::query()->where('is_active', true)->orderBy('id')->get() as $store) {
            $supported = CommerceRegistry::supports($store);
            $why = $supported ? null : "no connector for adapter [{$store->adapter}]; unsupported by design (ADR-009, ADR-015)";
            $available = $this->availability->check('commerce.list_orders', (int) $store->business_id);

            foreach ([CommerceRegistry::ADMIN_PROVIDER, CommerceRegistry::ASSISTANT_PROVIDER] as $provider) {
                $rows[] = $this->row('store', (string) $store->slug, (string) $store->name, $provider, (string) $store->slug, $supported, $why,
                    $available['available'] ? null : (string) $available['reason']);
            }
        }

        foreach (Business::query()->where('is_active', true)->orderBy('id')->get() as $business) {
            // The ERP is intended for a business when a token was ever stored for it, whatever
            // its status now; a business that never had one is not an ERP business.
            $intended = IntegrationCredential::query()->where('provider', ErpRegistry::PROVIDER)->where('account_ref', $business->slug)->exists();
            if (! $intended) {
                continue;
            }
            $available = $this->availability->check('erp.read_snapshot', (int) $business->id);
            $rows[] = $this->row('business', (string) $business->slug, (string) $business->name, ErpRegistry::PROVIDER, (string) $business->slug, true, null,
                $available['available'] ? null : (string) $available['reason']);
        }

        // docs/81 C.7: the model provider row is the provider that SERVES, since that is the key a
        // call would use; one row per provider when more than one serves, so the mix is visible;
        // Anthropic when none does, which keeps an install with no active row reading as before.
        foreach ($this->serving->active() ?: [ServingProvider::DEFAULT] as $provider) {
            if (! $this->providers->has($provider)) {
                continue;
            }
            [$credentialProvider, $account] = $this->providers->get($provider)->credentialPair();
            $rows[] = $this->row('hq', 'hq', 'HQ model provider', $credentialProvider, $account, true, null, null);
        }

        return $rows;
    }

    /**
     * The connector view (docs/98 §3): one row per connector the registry holds, then one per provider
     * a legacy shape serves. Read beside `inventory()`, never mixed into it, so no existing row,
     * incident or exit code changes. Never a value, and nothing here calls a provider.
     *
     * @return list<ConnectorReadinessRow>
     */
    public function connectors(): array
    {
        $rows = array_map(fn (ConnectorDefinition $c) => $this->connectorRow($c), $this->connectors->all());

        foreach ($this->connectors->legacy() as $legacy) {
            $rows[] = new ConnectorReadinessRow(
                $legacy->capability, $legacy->provider, $legacy->transport->value, $legacy->adapter,
                ConnectorReadinessRow::ORIGIN_LEGACY, ConnectorReadinessRow::LEGACY,
                'served by '.class_basename($legacy->shape).', not the registry: '.$legacy->note,
                $legacy->credentials, shape: $legacy->shape,
            );
        }

        return $rows;
    }

    private function connectorRow(ConnectorDefinition $connector): ConnectorReadinessRow
    {
        $credentials = array_map(static fn ($c) => $c->provider.' <- '.$c->accountRefFrom, $connector->credentials);
        $make = static fn (string $state, string $reason) => new ConnectorReadinessRow(
            $connector->capability, $connector->provider, $connector->transport->value, $connector->adapter,
            ConnectorReadinessRow::ORIGIN_REGISTRY, $state, $reason, $credentials, $connector->priority, $connector->fallback,
        );

        if (! $this->connectors->enabled($connector)) {
            return $make(ReadinessRow::DISABLED, "switched off ({$connector->enabledBy})");
        }

        if (! $connector->credentialsAreLiteral()) {
            return $make(ConnectorReadinessRow::SCOPE_DEPENDENT,
                'its credentials are looked up per business, brand or account, so they are checked when a request resolves');
        }

        // The first credential that is not verified decides the row, in the order a person must act on.
        $worst = null;
        foreach ($connector->credentials as $requirement) {
            $row = $this->row('connector', $connector->provider, $connector->capability, $requirement->provider, substr($requirement->accountRefFrom, 1), true, null, null);
            if ($requirement->optional && $row->state !== ReadinessRow::VERIFIED) {
                continue;
            }
            if ($worst === null || $this->severity($row->state) > $this->severity($worst->state)) {
                $worst = $row;
            }
        }

        return $worst === null
            ? $make(ConnectorReadinessRow::READY, 'registered and switched on; it needs no stored credential')
            : $make($worst->state, $worst->reason);
    }

    private function severity(string $state): int
    {
        return match ($state) {
            ReadinessRow::CREDENTIAL_INVALID => 7,
            ReadinessRow::CREDENTIAL_MISSING => 6,
            ReadinessRow::PROVIDER_BLOCKED_BY_CREDIT => 5,
            ReadinessRow::PROVIDER_UNAUTHORIZED => 4,
            ReadinessRow::CREDENTIAL_UNVERIFIED => 3,
            ReadinessRow::PROVIDER_UNAVAILABLE => 2,
            ReadinessRow::CONNECTOR_ERROR => 1,
            default => 0,
        };
    }

    /** @return list<ReadinessRow> the rows a human must act on */
    public function incidents(): array
    {
        return array_values(array_filter($this->inventory(), static fn (ReadinessRow $r) => $r->incident()));
    }

    /**
     * Run the cheap read-only probe for one row and record what the provider said on the
     * credential, then read the row again. The model provider is not probed here: a probe that
     * spends money belongs to a flag that says so (`verifyModel`, `--verify-model`).
     */
    public function verify(ReadinessRow $row): ReadinessRow
    {
        if (! $row->supported || $row->credentialId === null || $row->targetKind === 'hq') {
            return $row;
        }

        $credential = IntegrationCredential::find($row->credentialId);
        if ($credential === null) {
            return $row;
        }

        try {
            [$ok, $class, $message] = $row->targetKind === 'store'
                ? $this->probeStore($row)
                : $this->probeErp($row);
        } catch (Throwable $e) {
            [$ok, $class, $message] = [false, OutcomeClass::fromThrowable($e), class_basename($e).': '.$e->getMessage()];
        }

        if ($ok) {
            $this->health->recordAccepted($credential, 'health_check');
        } else {
            $this->health->recordFailed($credential, $class ?? OutcomeClass::ConnectorError, $message, 'health_check');
        }

        return $this->row($row->targetKind, $row->target, $row->targetName, $row->provider, $row->accountRef, true, null, null);
    }

    /**
     * The model provider's own check (docs/77 E.1): one 8-token call with the vault's key, recorded on
     * the credential as the class the provider's answer deserves, then the row read again.
     */
    public function verifyModel(ReadinessRow $row): ReadinessRow
    {
        if ($row->targetKind !== 'hq' || $row->credentialId === null) {
            return $row;
        }

        $this->providerCheck->run(ProviderCheck::SOURCE, $this->modelProviderFor($row->provider));

        return $this->row($row->targetKind, $row->target, $row->targetName, $row->provider, $row->accountRef, true, null, null);
    }

    /** The model provider whose credential a readiness row names. */
    private function modelProviderFor(string $credentialProvider): ?string
    {
        foreach ($this->providers->keys() as $key) {
            if ($this->providers->get($key)->credentialPair()[0] === $credentialProvider) {
                return $key;
            }
        }

        return null;
    }

    private function row(
        string $kind, string $target, string $name, string $provider, string $accountRef, bool $supported, ?string $unsupportedWhy, ?string $disabledWhy,
    ): ReadinessRow {
        if (! $supported) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, false, ReadinessRow::UNSUPPORTED, (string) $unsupportedWhy);
        }

        try {
            $credential = $this->vault->active($provider, $accountRef);
        } catch (AmbiguousCredential $e) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::CREDENTIAL_INVALID, $e->getMessage());
        }

        $base = [
            'credentialId' => $credential?->id,
            'credentialStatus' => $credential?->status,
            'hasPayload' => $credential?->hasPayload() ?? false,
            'lastVerifiedAt' => $credential?->last_verified_at?->toIso8601String(),
            'lastResult' => $credential === null ? null : $this->health->lastVerification($credential),
        ];

        if ($disabledWhy !== null) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::DISABLED, $disabledWhy, ...$base);
        }

        if ($credential === null) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::CREDENTIAL_MISSING,
                "no active credential stored for [{$provider}/{$accountRef}]", ...$base);
        }

        if (! $credential->hasPayload()) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::CREDENTIAL_MISSING,
                "credential [{$provider}/{$accountRef}] is stored without a value", ...$base);
        }

        if ($credential->isExpired()) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::CREDENTIAL_INVALID,
                "credential [{$provider}/{$accountRef}] expired at ".$credential->expires_at?->toIso8601String(), ...$base);
        }

        $last = $base['lastResult'];

        if ($this->health->isKnownInvalid($credential)) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::CREDENTIAL_INVALID,
                'rejected by the provider at '.($last['at'] ?? '?').' ('.($last['source'] ?? '?').'); store a new one on the Credentials screen', ...$base);
        }

        if ($last === null) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::CREDENTIAL_UNVERIFIED,
                'a credential is stored and nothing has verified it yet; HQ would attempt the call, and nothing says it would succeed', ...$base);
        }

        if ($last['ok']) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::VERIFIED,
                'accepted by the provider at '.$last['at'].' ('.$last['source'].')', ...$base);
        }

        // The provider answered, and not with an authentication rejection (that one condemned the row above).
        $class = (string) ($last['class'] ?? 'failed');
        [$state, $why] = match ($class) {
            OutcomeClass::ProviderBlockedByCredit->value => [ReadinessRow::PROVIDER_BLOCKED_BY_CREDIT,
                'the provider refused at '.$last['at'].' because the account has no credit ('.$last['source'].'); the credential itself is not judged. Add credit to the provider account'],
            OutcomeClass::ProviderUnauthorized->value => [ReadinessRow::PROVIDER_UNAUTHORIZED,
                'the provider refused a permission at '.$last['at'].' ('.$last['source'].'); the credential is not condemned. Check what the key is allowed to do'],
            OutcomeClass::ProviderUnavailable->value, OutcomeClass::NetworkError->value, OutcomeClass::Timeout->value => [ReadinessRow::PROVIDER_UNAVAILABLE,
                'last check at '.$last['at'].' ended '.$class.' ('.$last['source'].'), which says nothing about the credential; HQ would attempt the call'],
            default => [ReadinessRow::CONNECTOR_ERROR,
                'last check at '.$last['at'].' ended '.$class.' ('.$last['source'].'), which says nothing about the credential; HQ would attempt the call'],
        };

        return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, $state, $why, ...$base);
    }

    /** @return array{0: bool, 1: OutcomeClass|null, 2: string|null} */
    private function probeStore(ReadinessRow $row): array
    {
        $probe = $this->vault->openForAccounts($this->commerce->credentialPairsFor($row->target), fn () => $this->commerce->for($row->target)->probe());

        $key = $row->provider === CommerceRegistry::ADMIN_PROVIDER ? 'admin_orders' : 'assistant_products';
        $status = $probe[$key] ?? null;

        if (! is_int($status)) {
            return [false, OutcomeClass::CredentialMissing, 'the probe had no '.$row->provider.' to send'];
        }

        return match (true) {
            $status >= 200 && $status < 300 => [true, null, null],
            $status === 401 || $status === 403 => [false, OutcomeClass::ProviderUnauthorized, "{$row->target} answered HTTP {$status} to the probe"],
            $status >= 500 => [false, OutcomeClass::ProviderUnavailable, "{$row->target} answered HTTP {$status} to the probe"],
            default => [false, OutcomeClass::ConnectorError, "{$row->target} answered HTTP {$status} to the probe"],
        };
    }

    /** @return array{0: bool, 1: OutcomeClass|null, 2: string|null} */
    private function probeErp(ReadinessRow $row): array
    {
        $probe = $this->vault->openForAccounts([[ErpRegistry::PROVIDER, $row->target]], fn () => $this->erp->for($row->target)->probe());

        if (($probe['ok'] ?? false) === true) {
            return [true, null, null];
        }

        $error = (string) ($probe['error'] ?? 'the token cannot see its own business');
        $unauthorized = str_contains($error, '401') || str_contains($error, '403') || str_starts_with($error, 'Unsupported');

        return [false, $unauthorized ? OutcomeClass::ProviderUnauthorized : OutcomeClass::ProviderUnavailable, $error];
    }
}
