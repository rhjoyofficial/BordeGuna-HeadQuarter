<?php

namespace App\Gateway\Readiness;

use App\Ai\Providers\AnthropicProvider;
use App\Commerce\Support\CommerceRegistry;
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
 * Six states. `unsupported`: HQ has no connector for the target (the Econic WooCommerce store, the
 * ERP counter), which is by design and never an incident. `disabled`: the tool switch is off for
 * the business. `credential_missing`: no active row with a value. `credential_invalid`: expired, or
 * rejected by the provider on the last verification or the last live call, and nothing newer
 * stored. `unverified`: a credential exists and nothing has yet accepted or rejected it; HQ would
 * attempt the call. `ready`: the provider accepted it. An incident is a supported target that is
 * not executable, and that is what the health command's exit code says.
 */
final class CapabilityReadiness
{
    public function __construct(
        private readonly CredentialHealth $health,
        private readonly CredentialVault $vault,
        private readonly ToolAvailability $availability,
        private readonly CommerceRegistry $commerce,
        private readonly ErpRegistry $erp,
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

        $rows[] = $this->row('hq', 'hq', 'HQ model provider', AnthropicProvider::CREDENTIAL_PROVIDER, AnthropicProvider::ACCOUNT_REF, true, null, null);

        return $rows;
    }

    /** @return list<ReadinessRow> the rows a human must act on */
    public function incidents(): array
    {
        return array_values(array_filter($this->inventory(), static fn (ReadinessRow $r) => $r->incident()));
    }

    /**
     * Run the cheap read-only probe for one row and record what the provider said on the
     * credential, then read the row again. The model provider is not probed here: the gate's own
     * `hq:ai:probe` covers it, and a probe that spends money belongs to a command that says so.
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

        if ($last !== null && $last['ok']) {
            return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::READY,
                'accepted by the provider at '.$last['at'].' ('.$last['source'].')', ...$base);
        }

        return new ReadinessRow($kind, $target, $name, $provider, $accountRef, true, ReadinessRow::UNVERIFIED,
            $last === null ? 'a credential is stored and nothing has verified it yet; HQ would attempt the call'
                : 'last check at '.$last['at'].' ended '.($last['class'] ?? 'failed').' ('.$last['source'].'), which says nothing about the credential; HQ would attempt the call',
            ...$base);
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
