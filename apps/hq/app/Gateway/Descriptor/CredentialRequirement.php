<?php

namespace App\Gateway\Descriptor;

/**
 * A credential the tool's connector will need.
 *
 * The gateway uses this to check, before executing, that the credential exists, is active and is
 * unexpired, and to record WHICH credential id was involved. It never carries a value: a credential
 * is an identity at a provider, never an authorization inside HQ (docs/48 E.1), and there is
 * nowhere in this object to put a secret even by accident.
 */
final readonly class CredentialRequirement
{
    /**
     * @param  string  $provider  matches integration_credentials.provider
     * @param  string  $accountRefFrom  where account_ref comes from: an argument path like
     *                                  `args.store_slug`, or `business.slug`, or a literal `=value`
     */
    public function __construct(
        public string $provider,
        public string $accountRefFrom,
        public bool $optional = false,
    ) {}

    public function toArray(): array
    {
        return ['provider' => $this->provider, 'account_ref_from' => $this->accountRefFrom, 'optional' => $this->optional];
    }
}
