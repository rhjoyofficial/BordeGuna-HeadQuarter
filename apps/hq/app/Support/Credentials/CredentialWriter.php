<?php

namespace App\Support\Credentials;

use App\Models\IntegrationCredential;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * The one place that WRITES an integration credential, as `CredentialVault` is the one place that
 * reads one (docs/50). Storing a credential is a replacement, never an accumulation.
 *
 * ⚠ **The defect this closes, recorded on 8 Sep and fixed on 9 Sep.** `storeCredential()` always
 * `create()`d, and `CredentialVault::active()` was an unordered `first()`. Adding a replacement key
 * therefore left TWO active rows for one identity and the vault was free to keep using the OLD one.
 * During the M4 safety gate that turned a genuine key replacement into a silent no-op: the screen
 * said stored, the audit log said stored, and every call still authenticated as the key that had
 * already failed. Two rows, two truths, and the wrong one won.
 *
 * The rule now: **replacing is revoke-then-create inside one transaction**, so at no point are two
 * rows active for `(provider, account_ref)`, and a partial unique index makes the database refuse
 * the state even if some future code path forgets to come through here.
 *
 * ⭐ **A replacement deliberately produces a NEW id and a NEW `credential.stored` audit record.**
 * That is not an accident of the old implementation worth preserving by luck: it is the only
 * evidence that a save actually reached the server. A stale credentials tab expires to a 419 that
 * looks exactly like a successful submit, and "same id, no new `credential.stored`" is how an
 * operator tells a key that never arrived from a key that arrived and was rejected.
 *
 * ⛔ **Nothing here reads a payload.** It writes one and it nulls one on revocation. The vault
 * remains the only decryption point, and no method on this class returns a secret.
 */
final class CredentialWriter
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Store a credential for `(provider, account_ref)`, revoking whatever was active for that
     * identity first.
     *
     * Revocation uses the lifecycle that already exists - `status = revoked`, payload nulled - so
     * the history stays in the table and in `audit_logs`. Rows are never deleted to keep the list
     * tidy: a revoked row is the record that a key was in use until a moment, which is exactly what
     * an incident asks about later.
     *
     * @param  array<string, mixed>  $payload  encrypted at rest by the model cast
     * @param  array<string, mixed>  $attributes  business_id, brand_id, credential_type, scopes, expires_at...
     * @param  string|null  $reason  recorded on the audit records, never on the row's secret
     */
    public function replace(string $provider, ?string $accountRef, array $payload, array $attributes = [], ?string $reason = null): IntegrationCredential
    {
        return DB::transaction(function () use ($provider, $accountRef, $payload, $attributes, $reason) {
            // Every active row, not just one: if a duplicate identity already exists - a row this
            // class did not write, or one from before the unique index - a replacement is the
            // moment to heal it rather than add a third.
            $superseded = IntegrationCredential::query()
                ->where('provider', $provider)
                ->where('account_ref', $accountRef)
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $before = $superseded->mapWithKeys(fn (IntegrationCredential $c) => [$c->id => $c->toSafeArray()])->all();

            foreach ($superseded as $old) {
                $old->forceFill(['status' => 'revoked', 'payload' => null])->save();
            }

            $meta = $attributes['meta'] ?? [];
            if ($superseded->isNotEmpty()) {
                $meta['replaces'] = $superseded->pluck('id')->map(fn ($id) => (int) $id)->all();
            }

            $credential = IntegrationCredential::create([
                ...$attributes,
                'provider' => $provider,
                'account_ref' => $accountRef,
                'payload' => $payload,
                'status' => 'active',
                'meta' => $meta === [] ? null : $meta,
            ]);

            // The revocations are audited AFTER the create so each one can name the id that
            // replaced it. Same transaction, so a failed create leaves neither the rows nor the
            // records behind.
            foreach ($superseded as $old) {
                $this->auditor->record(
                    'credential.revoked', $old,
                    before: $before[$old->id], after: $old->toSafeArray(),
                    reason: "superseded by credential #{$credential->id}".($reason === null ? '' : ": {$reason}"),
                );
            }

            $this->auditor->record(
                'credential.stored', $credential,
                after: $credential->toSafeArray() + ['replaced_credential_ids' => $superseded->pluck('id')->map(fn ($id) => (int) $id)->all()],
                reason: $reason,
            );

            return $credential;
        });
    }
}
