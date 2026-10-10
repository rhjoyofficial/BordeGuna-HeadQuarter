<?php

namespace App\Customer360;

use App\Conversation\ConversationState;
use App\Conversation\HandoverReason;
use App\Memory\MemoryKind;
use App\Models\AuditLog;
use App\Models\ConsentEvent;
use App\Models\ConsentProjection;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerMerge;
use App\Models\CustomerMergeCandidate;
use App\Models\FactsOrder;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Customer 360 (docs/109, ruling docs/103 §5): everything HQ holds about ONE customer of ONE business,
 * read and labelled by kind, never blended.
 *
 * ⛔ READ-ONLY. Every line here is a SELECT. No event, no audit row, no cache write, no touch of
 * `last_seen_at`: rendering the page must change nothing (held by a snapshot test).
 *
 * ⛔ BUSINESS-SCOPED. A customer row belongs to exactly one business. Every query below is bound to
 * this customer's id AND this customer's business, so a row carrying the id but another business (which
 * should not exist) is still not shown. Nothing looks the same phone or email up in another business:
 * one phone in LaLaDia and in Bionic is two customers here (no cross-brand identity assumption).
 *
 * ⛔ NO S3 DEPENDENCY. Outcomes are a marked placeholder until the canonical outcome model is agreed.
 *
 * The caller (the controller) checks the viewer's business scope before calling; this class decides
 * only what each section shows to a viewer holding or lacking the inbox permission.
 */
final class Customer360Reader
{
    public const ORDER_LIMIT = 50;

    public const CONVERSATION_LIMIT = 50;

    public const MEMORY_LIMIT = 100;

    public const CONSENT_LEDGER_LIMIT = 100;

    public const HANDOVER_LIMIT = 50;

    /** @return array<string, mixed> */
    public function read(Customer $customer, User $viewer): array
    {
        $businessId = (int) $customer->business_id;
        $business = $customer->business()->first(['id', 'name', 'slug']);
        $inbox = $viewer->can('hq.inbox.view');

        $conversations = Conversation::query()
            ->where('customer_id', $customer->id)
            ->where('business_id', $businessId);

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'business' => $business === null ? null : ['id' => $business->id, 'name' => $business->name, 'slug' => $business->slug],
            ],
            'observed' => $this->observed($customer),
            'inferred' => $this->inferred($customer, $businessId),
            'orders' => $this->orders($customer, $businessId),
            'conversations' => $inbox ? $this->conversations(clone $conversations) : null,
            'memory' => $inbox ? $this->memory($customer, $businessId) : null,
            'consent' => $this->consent($customer, $businessId),
            // No table records a promise (docs/109 §8 question 1). Nothing is inferred from message text.
            'promises' => ['recorded' => false],
            'handovers' => $inbox ? $this->handovers(clone $conversations) : null,
            // Waits for the canonical outcome/attribution model (ruling §4, S3). Deliberately no query.
            'outcomes' => ['status' => 'awaiting_s3'],
            'can' => [
                'inbox' => $inbox,
                'manage' => $viewer->can('hq.customers.manage'),
            ],
        ];
    }

    /** What a channel, a shop or a person put on the record. */
    private function observed(Customer $customer): array
    {
        $identities = CustomerIdentity::query()
            ->where('customer_id', $customer->id)
            ->leftJoin('stores', 'stores.id', '=', 'customer_identities.source_store_id')
            ->orderBy('customer_identities.id')
            ->get(['customer_identities.id', 'customer_identities.channel', 'customer_identities.external_id', 'customer_identities.verified_at', 'customer_identities.last_seen_at', 'customer_identities.created_at', 'stores.name as store_name']);

        $merges = CustomerMerge::query()
            ->where('kept_id', $customer->id)
            ->leftJoin('customers as absorbed', 'absorbed.id', '=', 'customer_merges.absorbed_id')
            ->orderBy('customer_merges.id')
            ->get(['customer_merges.id', 'customer_merges.absorbed_id', 'customer_merges.merged_by', 'customer_merges.undone_at', 'customer_merges.created_at', 'absorbed.business_id as absorbed_business_id']);

        return [
            'name' => $customer->name,
            'phone' => $customer->primary_phone_canonical,
            'email' => $customer->email,
            'first_seen_at' => $this->iso($customer->first_seen_at),
            'last_seen_at' => $this->iso($customer->last_seen_at),
            'identities' => $identities->map(fn ($i) => [
                'id' => $i->id,
                'channel' => $i->channel,
                'external_id' => $i->external_id,
                'store' => $i->store_name,
                'verified_at' => $this->iso($i->verified_at),
                'first_seen_at' => $this->iso($i->created_at),
                'last_seen_at' => $this->iso($i->last_seen_at),
            ])->values()->all(),
            // A merge is a person's decision on the record. Only the absorbed row's id leaves the server,
            // with a flag when it belonged to another business (which the merge guard now refuses).
            'merges' => $merges->map(fn ($m) => [
                'id' => $m->id,
                'absorbed_id' => $m->absorbed_id,
                'by_person' => $m->merged_by !== null,
                'undone' => $m->undone_at !== null,
                'cross_business' => $m->absorbed_business_id !== null && (int) $m->absorbed_business_id !== (int) $customer->business_id,
                'at' => $this->iso($m->created_at),
            ])->values()->all(),
        ];
    }

    /** What HQ concluded or computed. Never a fact about the person; always shown as HQ's own reading. */
    private function inferred(Customer $customer, int $businessId): array
    {
        $links = CustomerIdentity::query()
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->get(['id', 'channel', 'confidence'])
            ->map(fn (CustomerIdentity $i) => [
                'identity_id' => $i->id,
                'channel' => $i->channel,
                'rule' => self::linkRule($i->channel),
                'confidence' => $i->confidence === null ? null : (string) $i->confidence,
            ])->values()->all();

        // Pending candidates in THIS business only. Shown, never acted on: a person decides on the list screen.
        $candidates = CustomerMergeCandidate::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhere('candidate_id', $customer->id))
            ->get(['id', 'customer_id', 'candidate_id', 'confidence', 'reason', 'created_at'])
            ->map(fn (CustomerMergeCandidate $c) => [
                'id' => $c->id,
                'other_id' => $c->customer_id === $customer->id ? $c->candidate_id : $c->customer_id,
                'confidence' => $c->confidence === null ? null : (string) $c->confidence,
                'reason' => $c->reason,
                'at' => $this->iso($c->created_at),
            ]);
        $sameBusiness = Customer::query()->whereIn('id', $candidates->pluck('other_id'))->where('business_id', $businessId)->pluck('id')->all();

        $beliefs = Memory::query()
            ->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('business_id', $businessId)->orWhereNull('business_id'))
            ->where('kind', MemoryKind::Semantic->value)
            ->orderByDesc('id')
            ->limit(self::MEMORY_LIMIT)
            ->get()
            ->map(fn (Memory $m) => [
                'id' => $m->id,
                'text' => (string) $m->content,
                'confidence' => $m->confidence,
                'provenance' => $m->provenance->value,
                'current' => $m->valid_to === null,
                'at' => $this->iso($m->valid_from ?? $m->created_at),
            ])->values()->all();

        $attributes = (array) ($customer->attributes ?? []);

        return [
            'identity_links' => $links,
            'candidates' => $candidates->filter(fn ($c) => in_array($c['other_id'], $sameBusiness, true))->values()->all(),
            'stranger' => (bool) ($attributes['stranger'] ?? false),
            'arrived_via' => isset($attributes['arrived_via']) ? (string) $attributes['arrived_via'] : null,
            'aliases' => array_values(array_filter((array) ($attributes['aliases'] ?? []), 'is_string')),
            'orders_count' => (int) $customer->orders_count,
            'lifetime_value' => (string) $customer->lifetime_value,
            'beliefs' => $beliefs,
        ];
    }

    /** HQ's copies of orders. The shop or the ERP holds the truth; each row says which, and how old the copy is. */
    private function orders(Customer $customer, int $businessId): array
    {
        $query = FactsOrder::query()->where('customer_id', $customer->id)->where('business_id', $businessId);

        $rows = (clone $query)
            ->with('store:id,name')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->limit(self::ORDER_LIMIT)
            ->get()
            ->map(function (FactsOrder $o) {
                $erp = $o->source_system === 'erp';
                $copiedAt = collect([$o->last_event_at, $o->synced_at, $o->read_back_at])->filter()->sortDesc()->first();

                return [
                    'id' => $o->id,
                    'ref' => $o->order_ref,
                    'owner_system' => $erp ? 'erp' : 'shop',
                    'store' => $o->store?->name,
                    'erp_order_number' => $erp ? $o->erp_order_number : null,
                    'source' => $o->source,
                    'provenance' => $o->provenance,
                    'status' => $o->status,
                    'payment_status' => $o->payment_status,
                    'gross' => (string) $o->gross,
                    'placed_at' => $this->iso($o->placed_at),
                    'delivered_at' => $this->iso($o->delivered_at),
                    'cancelled_at' => $this->iso($o->cancelled_at),
                    'copy_updated_at' => $this->iso($copiedAt),
                ];
            })->values()->all();

        return ['total' => (clone $query)->count(), 'rows' => $rows];
    }

    private function conversations($query): array
    {
        $total = (clone $query)->count();
        $rows = $query
            ->with('brand:id,name')
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::CONVERSATION_LIMIT)
            ->get()
            ->map(fn (Conversation $c) => [
                'uuid' => $c->uuid,
                'channel' => $c->channel->value,
                'brand' => $c->brand?->name,
                'state' => $c->state->value,
                'state_label' => $c->state->label(),
                'status' => $c->status,
                'messages' => (int) $c->messages_count,
                'opened_at' => $this->iso($c->opened_at),
                'last_message_at' => $this->iso($c->last_message_at),
                'closed_at' => $this->iso($c->closed_at),
            ])->values()->all();

        return ['total' => $total, 'rows' => $rows];
    }

    /** Structured and episodic memory, with provenance. Beliefs (semantic) are inferred and live there. */
    private function memory(Customer $customer, int $businessId): array
    {
        $rows = Memory::query()
            ->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('business_id', $businessId)->orWhereNull('business_id'))
            ->whereIn('kind', [MemoryKind::Structured->value, MemoryKind::Episodic->value])
            ->orderByRaw('valid_to is not null')
            ->orderByDesc('id')
            ->limit(self::MEMORY_LIMIT)
            ->get()
            ->map(fn (Memory $m) => [
                'id' => $m->id,
                'kind' => $m->kind->value,
                'key' => $m->key,
                'text' => $m->kind === MemoryKind::Structured ? (string) (data_get($m->value, 'text') ?? json_encode($m->value)) : (string) $m->content,
                'provenance' => $m->provenance->value,
                'source' => $m->source_ref,
                'current' => $m->valid_to === null,
                'at' => $this->iso($m->kind === MemoryKind::Episodic ? $m->occurred_at : $m->valid_from),
                'closed_at' => $this->iso($m->valid_to),
            ])->values()->all();

        return ['rows' => $rows];
    }

    /**
     * The consent projection and the append-only ledger behind it. Never sent: who recorded it by id or
     * name, their reason, and the evidence itself (only whether evidence exists).
     */
    private function consent(Customer $customer, int $businessId): array
    {
        $current = ConsentProjection::query()
            ->where('customer_id', $customer->id)
            ->where('business_id', $businessId)
            ->orderBy('purpose')
            ->orderBy('channel')
            ->get()
            ->map(fn (ConsentProjection $p) => [
                'purpose' => $p->purpose?->value,
                'channel' => $p->channel,
                'state' => $p->state->value,
                'permits' => $p->state->permits() && ($p->expires_at === null || $p->expires_at->isFuture()),
                'effective_from' => $this->iso($p->effective_from),
                'expires_at' => $this->iso($p->expires_at),
            ])->values()->all();

        $ledger = ConsentEvent::query()
            ->where('customer_id', $customer->id)
            ->where('business_id', $businessId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::CONSENT_LEDGER_LIMIT)
            ->get(['id', 'channel', 'purpose', 'state', 'basis', 'occurred_at', 'recorded_at', 'expires_at', 'actor_type', 'evidence_ref', 'evidence'])
            ->map(fn (ConsentEvent $e) => [
                'id' => $e->id,
                'purpose' => $e->purpose?->value,
                'channel' => $e->channel,
                'state' => $e->state->value,
                'basis' => $e->basis->value,
                'occurred_at' => $this->iso($e->occurred_at),
                'recorded_at' => $this->iso($e->recorded_at),
                'expires_at' => $this->iso($e->expires_at),
                'actor_type' => $e->actor_type,
                'has_evidence' => $e->evidence_ref !== null || ! empty($e->evidence),
            ])->values()->all();

        $legacy = $customer->consent;

        return [
            'current' => $current,
            'ledger' => $ledger,
            // The pre-ledger JSON blob on the customer row. Not consent (ADR-021): shown so nobody mistakes it for the ledger.
            'legacy' => is_array($legacy) && $legacy !== [] ? $legacy : null,
        ];
    }

    /** Audited moves of a thread between the agent and a person, on this customer's threads in this business. */
    private function handovers($conversationQuery): array
    {
        $threads = $conversationQuery->get(['id', 'uuid', 'channel'])->keyBy(fn (Conversation $c) => (string) $c->id);
        if ($threads->isEmpty()) {
            return ['rows' => []];
        }

        $rows = AuditLog::query()
            ->where('action', 'conversation.state_changed')
            ->where('target_type', (new Conversation)->getMorphClass())
            ->whereIn('target_id', $threads->keys()->all())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::HANDOVER_LIMIT * 4)
            ->get(['id', 'occurred_at', 'actor_type', 'actor_id', 'target_id', 'reason', 'after'])
            ->map(function (AuditLog $row) use ($threads) {
                $after = (array) $row->after;
                $from = ConversationState::tryFrom((string) ($after['from'] ?? ''));
                $to = ConversationState::tryFrom((string) ($after['to'] ?? ''));
                if ($from === null || $to === null || $to === ConversationState::Closed || $from->owner() === $to->owner()) {
                    return null;
                }
                $reason = HandoverReason::fromText($row->reason);
                $thread = $threads[(string) $row->target_id];

                return [
                    'id' => $row->id,
                    'conversation_uuid' => $thread->uuid,
                    'channel' => $thread->channel->value,
                    'direction' => $to->owner() === 'human' ? 'to_person' : 'to_agent',
                    'from' => $from->value,
                    'to' => $to->value,
                    'reason' => $reason?->value,
                    'label' => $reason?->label(),
                    'by' => $row->actor_type === 'human' ? (User::query()->whereKey($row->actor_id)->value('email') ?? 'a colleague') : null,
                    'at' => $this->iso($row->occurred_at),
                ];
            })
            ->filter()
            ->take(self::HANDOVER_LIMIT)
            ->values()
            ->all();

        return ['rows' => $rows];
    }

    /** Which deterministic resolver rule links an identity of this channel (IdentityResolver, docs/14). */
    private static function linkRule(string $channel): string
    {
        return match ($channel) {
            'phone' => 'canonical_phone',
            'email' => 'email',
            'shop_user', 'shop_order' => 'shop_record',
            default => 'channel_id',
        };
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value))->toIso8601String();
    }
}
