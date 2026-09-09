<?php

namespace App\Gateway\Credentials;

use RuntimeException;

/**
 * Two or more ACTIVE credentials exist for one provider/account identity, so HQ cannot say which
 * one it authenticates as.
 *
 * This is deliberately an operational error rather than a quiet choice. The old
 * `->where('status','active')->first()` had no `ORDER BY`, so PostgreSQL was free to return either
 * row and in practice returned the older one: adding a replacement key looked like it had worked
 * while every call kept using the key it replaced. A wrong answer that looks right is worse than a
 * refusal, and there is no rule ("newest wins") that is correct often enough to be safe here,
 * because the operator's intent is not recorded in the rows.
 *
 * It extends RuntimeException rather than a commerce exception on purpose: `FailureClassifier`
 * maps an unclassified throwable to `Internal`, which is what this is - a defect in HQ's own data,
 * not the far side saying no. On the gateway path it never gets that far: `CredentialResolver`
 * catches it and turns it into a named missing-credential reason, so a tool call refuses cleanly
 * with the ids written down.
 *
 * The message names the provider, the account reference and the row ids. It never names a value:
 * nothing here has been decrypted.
 */
final class AmbiguousCredential extends RuntimeException
{
    /** @param  list<int>  $credentialIds */
    public function __construct(
        public readonly string $provider,
        public readonly ?string $accountRef,
        public readonly array $credentialIds,
    ) {
        parent::__construct(self::describe($provider, $accountRef, $credentialIds));
    }

    /** @param  list<int>  $credentialIds */
    public static function describe(string $provider, ?string $accountRef, array $credentialIds): string
    {
        $account = $accountRef ?? 'no account';
        $ids = implode(', ', array_map(static fn (int $id) => '#'.$id, $credentialIds));

        return "credential [{$provider}/{$account}] is ambiguous: ".count($credentialIds)
            ." rows are active ({$ids}). HQ will not guess which identity to use. Revoke all but one on the credentials screen.";
    }
}
