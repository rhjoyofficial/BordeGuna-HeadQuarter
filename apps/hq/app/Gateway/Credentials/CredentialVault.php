<?php

namespace App\Gateway\Credentials;

use App\Commerce\Exceptions\Unsupported;
use App\Models\IntegrationCredential;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place in HQ that decrypts a credential (docs/50, closing M1's known limitation).
 *
 * Before M2 every connector looked up and decrypted its own secret. That was the existing
 * infrastructure and M1 deliberately reused it rather than inventing a second store, but it left a
 * real gap: the gateway could scrub a sensitive ARGUMENT out of an error message, because it was
 * given that argument, and could not scrub a CREDENTIAL a connector had fetched for itself, because
 * it had never seen the value.
 *
 * Now the gateway resolves what a tool declared, opens a scope for the duration of one call, and the
 * connector asks the vault. The secret exists in memory for exactly that call and is cleared in a
 * `finally`, so an exception cannot leave a scope open.
 *
 * ⚠ **Three rules this class exists to enforce, in order of importance:**
 *
 *  1. **A connector may not resolve a credential.** `secret()` reads only from the open scope. If
 *     the gateway did not provide it, the connector gets an `Unsupported` naming the gateway rather
 *     than a silent second lookup. A test greps the codebase to prove no other class touches
 *     `IntegrationCredential`'s payload.
 *  2. **The value never leaves the connector.** It is not in `ToolContext`, `tool_calls`,
 *     `audit_logs`, `ai_runs`, a model prompt or an ordinary log line, and there is no method here
 *     that hands one to a caller who is not about to make the provider call.
 *  3. **A credential is not an authorization.** Holding a token that could technically write does
 *     not make a write permitted; the gateway decided that several steps earlier. The vault only
 *     answers "what do I authenticate as", never "may I".
 */
final class CredentialVault
{
    /** @var array<string, string> "provider|account_ref" => the decrypted secret, only while a scope is open */
    private array $open = [];

    private int $depth = 0;

    /**
     * Runs $fn with the given credentials available, then clears them.
     *
     * @param  list<array{provider: string, account_ref: string, credential_id: int}>  $entries
     */
    public function open(array $entries, Closure $fn): mixed
    {
        $restore = $this->open;
        $this->depth++;

        foreach ($entries as $entry) {
            $secret = $this->read((int) $entry['credential_id']);
            if ($secret !== null) {
                $this->open[$this->slot((string) $entry['provider'], (string) $entry['account_ref'])] = $secret;
            }
        }

        try {
            return $fn();
        } finally {
            // Restore rather than clear, so a nested scope cannot blind the outer one, and clear
            // the values this scope introduced. A `finally` because an exception must not leave a
            // secret sitting in memory for the rest of the process.
            $this->open = $restore;
            $this->depth--;
        }
    }

    /**
     * Opens a scope for named provider/account pairs, resolving the ids itself.
     *
     * This is the DIAGNOSTIC path, used by the probe and inspect commands that legitimately talk to
     * a connector without going through a tool. It is deliberately a separate, obviously named
     * method rather than a quiet fallback inside `secret()`, so that a connector cannot reach it by
     * accident and a reviewer can see every caller.
     *
     * @param  list<array{0: string, 1: string}>  $pairs  [provider, account_ref]
     */
    public function openForAccounts(array $pairs, Closure $fn): mixed
    {
        $entries = [];
        foreach ($pairs as [$provider, $accountRef]) {
            $credential = $this->active($provider, $accountRef);
            if ($credential !== null) {
                $entries[] = ['provider' => $provider, 'account_ref' => $accountRef, 'credential_id' => $credential->id];
            }
        }

        return $this->open($entries, $fn);
    }

    /** The secret for a provider account, from the currently open scope. Throws when absent. */
    public function secret(string $provider, string $accountRef): string
    {
        $value = $this->open[$this->slot($provider, $accountRef)] ?? null;

        if ($value === null) {
            throw new Unsupported(
                "No [{$provider}] credential is open for [{$accountRef}]. Connectors do not resolve credentials: "
                .'the gateway resolves what the tool declared and opens it for the duration of the call. '
                .'If this tool needs that credential, declare it in its ToolDescriptor.'
            );
        }

        return $value;
    }

    /** For a credential a connector can work without. Returns null rather than throwing. */
    public function optional(string $provider, string $accountRef): ?string
    {
        return $this->open[$this->slot($provider, $accountRef)] ?? null;
    }

    public function has(string $provider, string $accountRef): bool
    {
        return isset($this->open[$this->slot($provider, $accountRef)]);
    }

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Metadata only: which account references have an active credential for a provider.
     *
     * Safe outside a scope because it reads no payload. Used by the probe commands to answer
     * "which businesses have a token" without decrypting anything.
     *
     * @return list<string>
     */
    public function accountsFor(string $provider): array
    {
        return IntegrationCredential::query()
            ->where('provider', $provider)
            ->where('status', 'active')
            ->pluck('account_ref')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The one active credential for a provider account, or null when there is none.
     *
     * ⚠ **Never an unordered `first()`.** Until 9 Sep this read
     * `->where('status','active')->first()` with no `ORDER BY`, so with two active rows for one
     * identity PostgreSQL was free to return either and in practice returned the OLDER one: a
     * replacement key was stored, looked stored, and was then never used. The rule now is
     * fail-closed - two active rows for one identity is an operational error, not a choice HQ is
     * entitled to make quietly, so this refuses and names the ids.
     *
     * `limit(2)` because the question is only "is there more than one", and there is no reason to
     * drag a whole table of duplicates into memory to answer it.
     *
     * @throws AmbiguousCredential when more than one active credential exists for the identity
     */
    public function active(string $provider, string $accountRef): ?IntegrationCredential
    {
        $matches = IntegrationCredential::query()
            ->where('provider', $provider)
            ->where('account_ref', $accountRef)
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            $ids = IntegrationCredential::query()
                ->where('provider', $provider)
                ->where('account_ref', $accountRef)
                ->where('status', 'active')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            // No secret in this line: ids and an account reference only, nothing decrypted.
            Log::warning('Ambiguous credential refused', ['provider' => $provider, 'account_ref' => $accountRef, 'credential_ids' => $ids]);

            throw new AmbiguousCredential($provider, $accountRef, array_map('intval', $ids));
        }

        return $matches->first();
    }

    /**
     * The only decryption in the application.
     *
     * A failure here returns null rather than throwing, so a corrupt or unreadable credential
     * becomes "the credential is missing", which the gateway already refuses cleanly with a named
     * reason. A decryption exception carrying a partial payload into a stack trace is exactly the
     * kind of leak this class exists to prevent.
     */
    private function read(int $credentialId): ?string
    {
        try {
            $credential = IntegrationCredential::find($credentialId);
            if ($credential === null || ! $credential->hasPayload()) {
                return null;
            }
            $payload = $credential->payload;

            return is_array($payload) && isset($payload['value']) && is_string($payload['value']) ? $payload['value'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function slot(string $provider, string $accountRef): string
    {
        return $provider.'|'.$accountRef;
    }
}
