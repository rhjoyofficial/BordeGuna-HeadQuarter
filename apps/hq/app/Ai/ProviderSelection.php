<?php

namespace App\Ai;

use App\Ai\Providers\ProviderRegistry;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\ShadowPeriod;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\GroupWideAuthority;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one lever that moves HQ's model calls from one provider to another (docs/81 C.7).
 *
 * ⚠ Which provider serves is which `model_tiers` rows are active, and the router falls back across
 * providers on an outage, so a measurement is about one model only while one provider's rows alone are
 * on. This switches every chat-tier row of the chosen provider on and every other provider's off, in one
 * transaction, audited under the named person who decided it. It refuses, changing nothing, unless a
 * real enabled person asks for a stated reason, the provider has a driver, a stored key and a row at
 * every chat tier, and no shadow period is open (a shadow's evidence must be about one model).
 *
 * It touches nothing else: not `ai.runtime`, not an agent, a grant or a flag. It does not test the key;
 * `hq:credentials:health --verify-model` or the gate's bypass step does, before or after.
 *
 * ⚠ It switches on ALL of the chosen provider's chat rows, including one an operator had switched off.
 * The dry run names every row it would change, so that is seen before it happens.
 */
final class ProviderSelection
{
    /** The tiers a provider must cover before it can serve. */
    private const CHAT_TIERS = [ModelTier::Low, ModelTier::Medium, ModelTier::High];

    /**
     * docs/138 ruling 13: which provider serves is AI activation for every business, so the person
     * needs the authority of the `ai.runtime` switch (`hq.flags.manage`) and no business restriction.
     */
    public const PERMISSION = 'hq.flags.manage';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ServingProvider $serving,
        private readonly Auditor $auditor,
        private readonly GroupWideAuthority $authority,
    ) {}

    /**
     * Why the provider cannot be selected, whoever asks. The person and the reason are checked by
     * `personRefusals()`, because a dry run may be asked for without them.
     *
     * @return list<string>
     */
    public function refusals(string $provider): array
    {
        if (! $this->providers->has($provider)) {
            return ["[{$provider}] has no registered driver (config hq.ai.providers: ".implode(', ', $this->providers->keys()).').'];
        }

        $out = [];
        $driver = $this->providers->get($provider);
        if (! $driver->available()) {
            [$credential, $account] = $driver->credentialPair();
            $out[] = "{$provider} has no credential stored ({$credential}/{$account}): store the key on the Credentials screen first.";
        }

        foreach (self::CHAT_TIERS as $tier) {
            if (! AiModel::query()->where('provider', $provider)->where('tier', $tier->value)->exists()) {
                $out[] = "{$provider} has no row at the {$tier->value} tier; every chat tier (low, medium, high) needs one before it can serve. Run the ModelTierSeeder.";
            }
        }

        $open = ShadowPeriod::query()->where('status', ShadowPeriod::STATUS_OPEN)->pluck('agent_key')->all();
        if ($open !== []) {
            $out[] = 'A shadow period is open ('.implode(', ', $open).'); its evidence must be about one model, so close it before the provider changes.';
        }

        return $out;
    }

    /** @return list<string> */
    public function personRefusals(?string $email, ?string $reason): array
    {
        $out = [];

        if ($email === null || trim($email) === '') {
            $out[] = 'A provider is selected by a named person: pass --by with the email of an enabled HQ user.';
        } else {
            $active = User::query()->where('email', $email)->toBase()->value('is_active');
            if ($active === null) {
                $out[] = "There is no HQ user with the email [{$email}].";
            } elseif ($active !== true) {
                $out[] = "[{$email}] is disabled, so nothing changes on their word.";
            } elseif (($refusal = $this->authority->refusal(User::query()->where('email', $email)->first(), self::PERMISSION)) !== null) {
                $out[] = $refusal;
            }
        }

        if ($reason === null || trim($reason) === '') {
            $out[] = 'A provider is selected for a stated reason; none was given (--reason).';
        }

        return $out;
    }

    /**
     * The rows the selection would switch, without switching them.
     *
     * @return array{on: list<AiModel>, off: list<AiModel>}
     */
    public function plan(string $provider): array
    {
        $chat = array_map(fn (ModelTier $t) => $t->value, self::CHAT_TIERS);
        $rows = AiModel::query()->whereIn('tier', $chat)->orderBy('provider')->orderBy('tier')->orderBy('priority')->get();

        return [
            'on' => $rows->filter(fn (AiModel $m) => $m->provider === $provider && ! $m->is_active)->values()->all(),
            'off' => $rows->filter(fn (AiModel $m) => $m->provider !== $provider && $m->is_active)->values()->all(),
        ];
    }

    /**
     * Select the provider. Returns the audit row, or null when it already served alone and nothing
     * changed.
     *
     * @throws RuntimeException when any refusal holds; nothing is changed then
     */
    public function select(string $provider, string $email, string $reason): ?AuditLog
    {
        $refusals = [...$this->personRefusals($email, $reason), ...$this->refusals($provider)];
        if ($refusals !== []) {
            throw new RuntimeException("[{$provider}] was not selected:\n- ".implode("\n- ", $refusals));
        }

        $user = User::query()->where('email', $email)->firstOrFail();

        return DB::transaction(function () use ($provider, $user, $reason) {
            // Read again under the transaction, so two operators cannot interleave half a switch.
            AiModel::query()->lockForUpdate()->get(['id']);
            $plan = $this->plan($provider);

            if ($plan['on'] === [] && $plan['off'] === [] && $this->serving->current() === $provider) {
                return null;
            }

            $before = $this->snapshot();

            foreach ($plan['on'] as $row) {
                $row->forceFill(['is_active' => true])->save();
            }
            foreach ($plan['off'] as $row) {
                $row->forceFill(['is_active' => false])->save();
            }

            return $this->auditor->record(
                'ai.provider_selected',
                'model_tiers',
                $before,
                $this->snapshot() + ['switched_on' => $this->labels($plan['on']), 'switched_off' => $this->labels($plan['off'])],
                $reason,
                actorUserId: (int) $user->getKey(),
                actorLabel: (string) $user->email,
                context: $this->authority->auditContext(self::PERMISSION, $user),
            );
        });
    }

    /** @return array{serving: string|null, active: list<string>} */
    private function snapshot(): array
    {
        $active = AiModel::query()->where('is_active', true)->orderBy('provider')->orderBy('tier')->orderBy('priority')->get();

        return [
            'serving' => $this->serving->current() ?? $this->serving->state(),
            'active' => $this->labels($active->all()),
        ];
    }

    /**
     * @param  list<AiModel>  $rows
     * @return list<string>
     */
    private function labels(array $rows): array
    {
        return array_values(array_map(fn (AiModel $m) => "#{$m->id} {$m->provider} {$m->tier} {$m->model_id}", $rows));
    }
}
