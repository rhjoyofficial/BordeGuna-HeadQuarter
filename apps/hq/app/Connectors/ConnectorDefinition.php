<?php

namespace App\Connectors;

use App\Gateway\Descriptor\CredentialRequirement;
use InvalidArgumentException;

/**
 * One registered connector: which provider serves which capability, through which adapter and
 * transport, needing which credentials, switched on by what (docs/97 §6 D2).
 *
 * Built only from `config/hq-connectors.php` and validated when the registry loads. A malformed row
 * throws rather than being skipped: a typo that silently disabled a provider would look exactly like
 * a provider that is switched off, and the two must never read the same (the `ProviderRegistry`
 * precedent).
 *
 * Never a value: credentials are named by kind (`integration_credentials.provider`) and by where the
 * account reference comes from, exactly as a `ToolDescriptor` declares them.
 */
final readonly class ConnectorDefinition
{
    /** `flag:<feature flag key>` or `config:<config key>`. There is no "always on". */
    private const ENABLED_BY = '/^(flag|config):[A-Za-z0-9_.\-]+$/';

    private const PROVIDER = '/^[a-z0-9][a-z0-9_.\-]*$/';

    /**
     * Account reference sources a connector may use. The same grammar as `CredentialRequirement`,
     * narrowed to what a capability request carries: no `args.*`, because a connector is chosen
     * before any tool argument exists.
     */
    private const ACCOUNT_FROM = '/^(=[^|]+|business\.slug|brand\.slug|platform_account\.ref)$/';

    /**
     * @param  class-string  $adapter
     * @param  list<CredentialRequirement>  $credentials
     * @param  list<string>|null  $businesses  business slugs this connector may serve; null = any
     * @param  string|null  $platform  a `platform_accounts.platform` this connector is tied to
     */
    public function __construct(
        public string $capability,
        public string $provider,
        public string $adapter,
        public Transport $transport,
        public array $credentials,
        public int $priority,
        public string $enabledBy,
        public bool $fallback = false,
        public ?array $businesses = null,
        public ?string $platform = null,
    ) {}

    /** @throws InvalidArgumentException naming the row and the field */
    public static function fromArray(array $row, int|string $index = '?'): self
    {
        $where = "config/hq-connectors.php connectors[{$index}]";

        $capability = self::string($row, 'capability', $where);
        if (! Capabilities::known($capability)) {
            throw new InvalidArgumentException("{$where}: [{$capability}] is not a capability in App\\Connectors\\Capabilities.");
        }
        if (Capabilities::reserved($capability)) {
            throw new InvalidArgumentException("{$where}: [{$capability}] is reserved and has no contract yet; write its interface first (docs/98 §4).");
        }

        $provider = self::string($row, 'provider', $where);
        if (preg_match(self::PROVIDER, $provider) !== 1) {
            throw new InvalidArgumentException("{$where}: provider [{$provider}] must be a lower-case key.");
        }

        $adapter = self::string($row, 'adapter', $where);
        $contract = (string) Capabilities::contract($capability);
        if (! class_exists($adapter)) {
            throw new InvalidArgumentException("{$where}: adapter [{$adapter}] does not exist.");
        }
        if (! is_a($adapter, $contract, true)) {
            throw new InvalidArgumentException("{$where}: adapter [{$adapter}] does not implement [{$contract}], the contract of [{$capability}].");
        }

        $transport = Transport::tryFrom((string) ($row['transport'] ?? ''))
            ?? throw new InvalidArgumentException("{$where}: transport must be one of direct_api, mcp, n8n, internal.");

        $enabledBy = self::string($row, 'enabled_by', $where);
        if (preg_match(self::ENABLED_BY, $enabledBy) !== 1) {
            throw new InvalidArgumentException("{$where}: enabled_by must be `flag:<key>` or `config:<key>`; a connector is never on by default.");
        }

        if (! array_key_exists('priority', $row) || ! is_int($row['priority'])) {
            throw new InvalidArgumentException("{$where}: priority must be an integer (lower is tried first).");
        }

        $credentials = [];
        foreach ((array) ($row['credentials'] ?? []) as $i => $credential) {
            $kind = is_array($credential) ? ($credential['kind'] ?? null) : null;
            $from = is_array($credential) ? ($credential['account_ref_from'] ?? null) : null;
            if (! is_string($kind) || $kind === '' || ! is_string($from) || preg_match(self::ACCOUNT_FROM, $from) !== 1) {
                throw new InvalidArgumentException("{$where}: credentials[{$i}] needs a kind and an account_ref_from of `=literal`, `business.slug`, `brand.slug` or `platform_account.ref`.");
            }
            $credentials[] = new CredentialRequirement($kind, $from, (bool) ($credential['optional'] ?? false));
        }

        $businesses = $row['businesses'] ?? null;
        if ($businesses !== null && (! is_array($businesses) || $businesses === [] || array_filter($businesses, static fn ($b) => ! is_string($b) || $b === '') !== [])) {
            throw new InvalidArgumentException("{$where}: businesses must be null (any) or a non-empty list of business slugs.");
        }

        $platform = $row['platform'] ?? null;
        if ($platform !== null && (! is_string($platform) || $platform === '')) {
            throw new InvalidArgumentException("{$where}: platform must be null or a platform_accounts.platform value.");
        }

        return new self(
            capability: $capability,
            provider: $provider,
            adapter: $adapter,
            transport: $transport,
            credentials: $credentials,
            priority: $row['priority'],
            enabledBy: $enabledBy,
            fallback: (bool) ($row['fallback'] ?? false),
            businesses: $businesses === null ? null : array_values($businesses),
            platform: $platform,
        );
    }

    /** Whether every credential's account reference is fixed, so readiness can check it without a request. */
    public function credentialsAreLiteral(): bool
    {
        foreach ($this->credentials as $credential) {
            if (! str_starts_with($credential->accountRefFrom, '=')) {
                return false;
            }
        }

        return true;
    }

    /** Safe for an audit row or a model prompt: names only. */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability,
            'provider' => $this->provider,
            'adapter' => $this->adapter,
            'transport' => $this->transport->value,
            'credentials' => array_map(static fn (CredentialRequirement $c) => $c->toArray(), $this->credentials),
            'priority' => $this->priority,
            'enabled_by' => $this->enabledBy,
            'fallback' => $this->fallback,
            'businesses' => $this->businesses,
            'platform' => $this->platform,
        ];
    }

    private static function string(array $row, string $key, string $where): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$where}: {$key} is required.");
        }

        return $value;
    }
}
