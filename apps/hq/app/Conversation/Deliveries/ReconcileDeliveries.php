<?php

namespace App\Conversation\Deliveries;

use App\Conversation\Channels\DeliveryReceipt;
use App\Conversation\Channels\DeliveryStatus;
use App\Conversation\Channels\OutboundChannelAdapter;
use App\Gateway\Credentials\CredentialVault;
use App\Models\MessageDelivery;
use App\Platform\PlatformRegistry;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Settles the attempts the sender could not (docs/95 §5 "Delivery", ADR-031, docs/88-q-record open
 * question 2): a `sending` row a crash left behind, an `uncertain` row after a timeout or a 2xx that
 * named no message. Each blocks its message from ever being sent again, by design; until now only a
 * person could settle one. This asks the provider instead, by the provider's own message id, and
 * writes its answer through the ledger, forward-only.
 *
 * What it never does: send. There is no send call in this class, and a test holds that. An attempt
 * the provider cannot speak for (no id was ever recorded, no provider is registered for the account,
 * the provider cannot read a message back) is counted and left exactly as it was, for a person.
 *
 * Today `PlatformRegistry` is empty, so every pass ends in one `deliveries.reconcile_skipped` audit
 * row with the counts and touches nothing. Registering a provider (gate P3-G4) is what makes it act.
 */
final class ReconcileDeliveries extends Command
{
    protected $signature = 'hq:deliveries:reconcile
        {--older-than= : minutes an attempt must have sat unsettled; default hq.messaging.reconcile_after_minutes}
        {--limit=200 : attempts to look at in one pass}';

    protected $description = 'Ask the provider what became of sending/uncertain delivery attempts and settle the ledger; never resends.';

    public function handle(PlatformRegistry $registry, CredentialVault $vault, DeliveryLedger $ledger, Auditor $audit): int
    {
        $minutes = (int) ($this->option('older-than') ?? config('hq.messaging.reconcile_after_minutes', 30));
        $cutoff = CarbonImmutable::now('UTC')->subMinutes(max(1, $minutes));

        $attempts = MessageDelivery::query()
            ->whereIn('status', ['sending', DeliveryReceipt::UNCERTAIN])
            ->where('updated_at', '<=', $cutoff)
            ->with('message.conversation.platformAccount')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $counts = [
            'considered' => $attempts->count(), 'settled_accepted' => 0, 'settled_failed' => 0, 'unchanged' => 0,
            'no_provider_ref' => 0, 'no_account' => 0, 'no_provider' => 0, 'unreadable' => 0, 'errors' => 0,
        ];

        if ($registry->platforms() === []) {
            // Nothing can be asked: say so once, with the numbers, and change nothing.
            $counts['no_provider'] = $counts['considered'];
            $audit->recordSystem('deliveries.reconcile_skipped', null, null, $counts,
                'no messaging provider is registered, so no unsettled attempt could be asked about');
            $this->line("no provider registered: {$counts['considered']} unsettled attempt(s) left for a person");

            return self::SUCCESS;
        }

        foreach ($attempts as $attempt) {
            if ($attempt->provider_ref === null || $attempt->provider_ref === '') {
                $counts['no_provider_ref']++;

                continue;
            }
            $account = $attempt->message?->conversation?->platformAccount;
            if ($account === null || ! $account->is_active) {
                $counts['no_account']++;

                continue;
            }
            $provider = $registry->get($account->platform);
            if (! $provider instanceof OutboundChannelAdapter) {
                $counts['no_provider']++;

                continue;
            }

            try {
                $answer = $vault->openForAccounts(
                    $provider->readCredentials($account->account_ref),
                    fn () => $provider->readDelivery((string) $attempt->provider_ref, $account->account_ref),
                );
            } catch (Throwable $e) {
                $counts['errors']++;
                $this->warn("attempt {$attempt->id}: ".$e->getMessage());

                continue;
            }

            if ($answer === null) {
                $counts['unreadable']++;

                continue;
            }

            $before = $attempt->status;
            $settled = $ledger->settle($attempt, $answer);
            if ($settled->status === $before) {
                $counts['unchanged']++;
            } elseif ($settled->status === DeliveryStatus::FAILED) {
                $counts['settled_failed']++;
            } else {
                $counts['settled_accepted']++;
            }
        }

        $audit->recordSystem('deliveries.reconciled', null, null, $counts, "attempts unsettled for more than {$minutes} minute(s), asked of their providers");
        $this->line(collect($counts)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
