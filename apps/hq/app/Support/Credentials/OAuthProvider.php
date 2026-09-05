<?php

namespace App\Support\Credentials;

/**
 * Contract every OAuth provider adapter implements in later phases.
 * Token sets are plain arrays; the credential model encrypts them at rest.
 */
interface OAuthProvider
{
    public function key(): string;

    /** @param list<string> $scopes */
    public function authorizationUrl(array $scopes, string $state, string $redirectUri): string;

    /** @return array{tokens: array, expires_at: ?\DateTimeInterface, scopes: list<string>, account_ref: string} */
    public function exchangeCode(string $code, string $redirectUri): array;

    /** @return array{tokens: array, expires_at: ?\DateTimeInterface} */
    public function refresh(array $tokens): array;

    public function revoke(array $tokens): void;
}
