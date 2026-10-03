<?php

namespace App\Connectors;

/**
 * Which connector will serve a capability, or why none can.
 *
 * As with `CapabilityRouting`, the rejected connectors are part of the answer: "voice.transcribe is
 * registered to Google, whose flag is off for this business, and to ElevenLabs, whose key was
 * rejected yesterday" is something a person can act on.
 *
 * `credentials` carries ids only (provider, account_ref, credential_id), in the shape
 * `CredentialVault::open()` takes, so the gateway can open exactly the identities that made the
 * connector ready and no others.
 */
final readonly class ConnectorResolution
{
    /**
     * @param  list<array{provider: string, reason: string}>  $rejected
     * @param  list<array{provider: string, account_ref: string, credential_id: int}>  $credentials
     */
    private function __construct(
        public ConnectorOutcome $outcome,
        public string $capability,
        public ?ConnectorDefinition $connector = null,
        public ?string $reason = null,
        public array $rejected = [],
        public array $credentials = [],
        /** null when the request named no `preferProvider`; otherwise `honoured` or why it was not. */
        public ?string $preference = null,
    ) {}

    public static function resolved(ConnectorDefinition $connector, array $credentials, array $rejected = [], ?string $preference = null): self
    {
        return new self(ConnectorOutcome::Resolved, $connector->capability, $connector, null, $rejected, $credentials, $preference);
    }

    public static function noConnector(string $capability): self
    {
        return new self(ConnectorOutcome::NoConnector, $capability, null,
            "No connector is registered for [{$capability}]. HQ cannot do this yet; nothing was attempted.");
    }

    public static function unavailable(string $capability, array $rejected, ?string $preference = null): self
    {
        return new self(ConnectorOutcome::Unavailable, $capability, null,
            "No registered connector can serve [{$capability}] right now.", $rejected, [], $preference);
    }

    /** @param  list<ConnectorDefinition>  $tied */
    public static function ambiguous(string $capability, array $tied, array $rejected = [], ?string $preference = null): self
    {
        $names = implode(', ', array_map(static fn (ConnectorDefinition $c) => $c->provider, $tied));

        return new self(ConnectorOutcome::Ambiguous, $capability, null,
            "[{$capability}] could be served by more than one connector at the same priority ({$names}) and nothing says which. "
            .'Give one a different priority or mark one a fallback; HQ does not pick a provider by guessing.',
            $rejected, [], $preference);
    }

    public static function unknown(string $capability, string $why): self
    {
        return new self(ConnectorOutcome::UnknownCapability, $capability, null, $why);
    }

    public function resolvedOk(): bool
    {
        return $this->outcome === ConnectorOutcome::Resolved;
    }

    /** Safe for an audit row, a tool result or a model prompt: names, ids and reasons, never a value. */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'capability' => $this->capability,
            'provider' => $this->connector?->provider,
            'transport' => $this->connector?->transport->value,
            'fallback' => $this->connector?->fallback,
            'reason' => $this->reason,
            'rejected' => $this->rejected,
            'credential_ids' => array_map(static fn (array $c) => $c['credential_id'], $this->credentials),
            'preference' => $this->preference,
        ];
    }
}
