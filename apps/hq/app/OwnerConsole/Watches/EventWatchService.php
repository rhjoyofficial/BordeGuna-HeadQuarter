<?php

namespace App\OwnerConsole\Watches;

use App\Gateway\CallerKind;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Gateway\Validation\ArgumentValidator;
use App\Models\DomainEvent;
use App\Models\EventWatch;
use App\Models\User;
use App\Models\WatchFiring;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Tools\ToolRegistry;
use Illuminate\Support\Str;
use Throwable;

/**
 * Event watches created from the chat (docs/143 F, docs/138 ruling 12: PROPOSALS ONLY).
 *
 * A watch is a condition on one event type of ONE business, plus an optional proposed action. It never
 * acts: when an enabled watch matches an event it records a firing and, if it carries an action, asks the
 * gateway to PARK that action on an ordinary approval. Hence:
 *  - the action must be a tool of tier R4 or above, and the gateway's read-only `authorize()` must say
 *    policy AWAITS APPROVAL, or no proposal is made (`proposal_refused`); a watch can never run an effect;
 *  - the action's arguments are fixed when the watch is proposed; nothing from the event is copied into
 *    them, so an event payload (untrusted) cannot steer the action;
 *  - a watch is born `proposed` and only a named person with `hq.watches.enable`, who may act for its
 *    business, makes it `enabled`; enabling grants no authority for the action (the approval does that);
 *  - a watch of business A sees only events of business A; an event of no business matches no watch;
 *  - once per (watch, event) by a unique index, and per watch at most `max_per_hour` and `max_per_day`
 *    proposals, beyond which a firing is recorded as `rate_limited` and nothing is asked of the gateway.
 *
 * Inert: flag `console.event_watches` OFF. With it off nothing can be proposed, enabled, or fired.
 */
final class EventWatchService
{
    public const PERMISSION_PROPOSE = 'hq.watches.propose';

    public const PERMISSION_ENABLE = 'hq.watches.enable';

    public const OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains'];

    public const MAX_CONDITIONS = 5;

    public const MAX_PER_HOUR = 20;

    public const MAX_PER_DAY = 100;

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly ToolRegistry $tools,
        private readonly ArgumentValidator $validator,
        private readonly ToolGateway $gateway,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{path: string, op: string, value: scalar}>  $conditions
     * @param  array{tool_key: string, args?: array<string, mixed>}|null  $action
     */
    public function propose(User $by, int $businessId, string $name, string $eventType, array $conditions, ?array $action = null, int $perHour = 5, int $perDay = 20, ?string $agentKey = null): EventWatch
    {
        $this->assertOn();
        if (! $by->is_active || ! $by->can(self::PERMISSION_PROPOSE)) {
            throw new Refused('You do not hold '.self::PERMISSION_PROPOSE.'.');
        }
        if (! $this->mayActFor($by, $businessId)) {
            throw new Refused('You may not watch that business.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new Refused('A watch needs a name of 1 to 160 characters.');
        }
        if (preg_match('/^[a-z0-9_.:-]{1,96}$/', $eventType) !== 1) {
            throw new Refused('The event type must be lowercase letters, digits and . _ : - only.');
        }
        if (count($conditions) > self::MAX_CONDITIONS) {
            throw new Refused('A watch has at most '.self::MAX_CONDITIONS.' conditions.');
        }
        $clean = [];
        foreach ($conditions as $c) {
            $path = (string) ($c['path'] ?? '');
            $op = (string) ($c['op'] ?? '');
            if (preg_match('/^[A-Za-z0-9_.]{1,64}$/', $path) !== 1 || ! in_array($op, self::OPS, true) || ! is_scalar($c['value'] ?? null)) {
                throw new Refused('Each condition needs a path, one of '.implode(', ', self::OPS).' and a plain value.');
            }
            $clean[] = ['path' => $path, 'op' => $op, 'value' => $c['value']];
        }
        if ($perHour < 1 || $perHour > self::MAX_PER_HOUR || $perDay < 1 || $perDay > self::MAX_PER_DAY || $perHour > $perDay) {
            throw new Refused('Limits: 1 to '.self::MAX_PER_HOUR.' per hour and 1 to '.self::MAX_PER_DAY.' per day, the hourly never above the daily.');
        }

        $stored = null;
        if ($action !== null) {
            $key = (string) ($action['tool_key'] ?? '');
            if (! $this->tools->has($key)) {
                throw new Refused("HQ has no tool [{$key}].");
            }
            $tool = $this->tools->get($key);
            if (! $tool->descriptor()->riskTier->atLeast(RiskTier::R4)) {
                throw new Refused("[{$key}] does not need a person's approval, so a watch may not propose it.");
            }
            $validation = $this->validator->validate((array) ($action['args'] ?? []), $tool->inputSchema());
            if (! $validation->valid) {
                throw new Refused($validation->message());
            }
            $stored = ['tool_key' => $key, 'args' => $validation->normalised];
        }

        $watch = EventWatch::query()->create([
            'uuid' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => $name, 'event_type' => $eventType,
            'conditions' => $clean, 'action' => $stored, 'max_per_hour' => $perHour, 'max_per_day' => $perDay, 'state' => 'proposed',
            'proposer_kind' => $agentKey === null ? 'human' : 'agent', 'agent_key' => $agentKey, 'proposed_by_user_id' => $by->getKey(),
        ]);
        $this->audit('event_watch.proposed', $watch, $by, null, ['state' => 'proposed', 'event_type' => $eventType, 'action' => $stored['tool_key'] ?? null]);

        return $watch;
    }

    public function enable(EventWatch $watch, User $by): EventWatch
    {
        return $this->move($watch, $by, ['proposed', 'disabled'], 'enabled', 'event_watch.enabled');
    }

    public function disable(EventWatch $watch, User $by): EventWatch
    {
        return $this->move($watch, $by, ['enabled'], 'disabled', 'event_watch.disabled');
    }

    public function reject(EventWatch $watch, User $by, ?string $reason = null): EventWatch
    {
        return $this->move($watch, $by, ['proposed'], 'rejected', 'event_watch.rejected', $reason);
    }

    /**
     * The event drain calls this once per delivered event. It never throws into the drain.
     *
     * @return int how many firings were recorded for this event
     */
    public function deliver(DomainEvent $event): int
    {
        if (! Flag::on(Flag::EVENT_WATCHES) || $event->business_id === null) {
            return 0; // off, or an event of no business: it matches no watch (watches are per business)
        }

        $fired = 0;
        EventWatch::query()->where('state', 'enabled')->where('business_id', $event->business_id)->where('event_type', $event->type)->orderBy('id')
            ->each(function (EventWatch $watch) use ($event, &$fired) {
                try {
                    if (! self::matches((array) $watch->conditions, (array) $event->payload)) {
                        return;
                    }
                    $fired += $this->fire($watch, $event) ? 1 : 0;
                } catch (Throwable) {
                    // A broken watch must not cost the drain its event.
                }
            });

        return $fired;
    }

    /** @param  list<array{path: string, op: string, value: scalar}>  $conditions */
    public static function matches(array $conditions, array $payload): bool
    {
        foreach ($conditions as $c) {
            $actual = data_get($payload, $c['path']);
            $expected = $c['value'];
            $ok = match ($c['op']) {
                'eq' => $actual == $expected && is_scalar($actual),
                'neq' => $actual != $expected,
                'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
                'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
                'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
                'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
                'contains' => is_string($actual) && $expected !== '' && str_contains(mb_strtolower($actual), mb_strtolower((string) $expected)),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ internals

    private function fire(EventWatch $watch, DomainEvent $event): bool
    {
        // Once per (watch, event): the unique index decides, so a replayed event records nothing twice.
        if (WatchFiring::query()->insertOrIgnore(['event_watch_id' => $watch->id, 'event_id' => (string) $event->event_id, 'outcome' => 'pending', 'created_at' => now(), 'updated_at' => now()]) === 0) {
            return false;
        }
        $firing = WatchFiring::query()->where('event_watch_id', $watch->id)->where('event_id', (string) $event->event_id)->firstOrFail();

        $recent = fn (int $minutes) => WatchFiring::query()->where('event_watch_id', $watch->id)->whereIn('outcome', ['proposed', 'notified', 'proposal_refused'])
            ->where('created_at', '>=', now()->subMinutes($minutes))->count();
        if ($recent(60) >= $watch->max_per_hour || $recent(1440) >= $watch->max_per_day) {
            $firing->forceFill(['outcome' => 'rate_limited', 'detail' => 'The watch reached its limit; nothing was proposed.'])->save();
            $this->audit('event_watch.rate_limited', $watch, null, null, ['event_id' => $event->event_id]);

            return true;
        }

        $watch->forceFill(['last_fired_at' => now(), 'fire_count' => $watch->fire_count + 1])->save();
        $action = (array) $watch->action;
        if ($action === []) {
            $firing->forceFill(['outcome' => 'notified'])->save();
            $this->audit('event_watch.fired', $watch, null, null, ['event_id' => $event->event_id, 'outcome' => 'notified']);

            return true;
        }

        $enabler = User::query()->find($watch->enabled_by_user_id);
        if ($enabler === null || ! $enabler->is_active || ! $this->mayActFor($enabler, $watch->business_id)) {
            $firing->forceFill(['outcome' => 'proposal_refused', 'detail' => 'The person who enabled the watch may no longer act for this business.'])->save();

            return true;
        }

        $request = new ToolRequest(
            toolKey: $action['tool_key'], args: (array) ($action['args'] ?? []), caller: CallerKind::Event, identity: ActingIdentity::human($enabler),
            correlationId: (string) Str::uuid(), businessId: $watch->business_id, idempotencyKey: "watch:{$watch->uuid}:{$event->event_id}",
        );
        $auth = $this->gateway->authorize($request);
        if (! $auth->awaitsApproval) {
            // Policy would run it, or the gateway refuses it: a watch proposes, it never acts.
            $firing->forceFill(['outcome' => 'proposal_refused', 'detail' => $auth->authorized ? 'Policy would not ask a person, so the watch does not propose it.' : (string) $auth->reason])->save();
            $this->audit('event_watch.proposal_refused', $watch, null, null, ['event_id' => $event->event_id]);

            return true;
        }

        $outcome = $this->gateway->execute($request);
        if ($outcome->status->pending() && $outcome->approvalId !== null) {
            $firing->forceFill(['outcome' => 'proposed', 'approval_id' => $outcome->approvalId])->save();
            $this->audit('event_watch.fired', $watch, null, null, ['event_id' => $event->event_id, 'outcome' => 'proposed', 'approval_id' => $outcome->approvalId]);
        } else {
            $firing->forceFill(['outcome' => 'proposal_refused', 'detail' => $outcome->ok() ? 'The action ran without an approval, which a watch must never allow.' : (string) ($outcome->error ?? $outcome->policyReason)])->save();
        }

        return true;
    }

    /** @param  list<string>  $from */
    private function move(EventWatch $watch, User $by, array $from, string $to, string $action, ?string $note = null): EventWatch
    {
        $this->assertOn();
        if (! $by->is_active || ! $by->can(self::PERMISSION_ENABLE)) {
            throw new Refused('You do not hold '.self::PERMISSION_ENABLE.'.');
        }
        if (! $this->mayActFor($by, $watch->business_id)) {
            throw new Refused('You may not act for that business.');
        }
        $before = $watch->state;
        $moved = EventWatch::query()->whereKey($watch->getKey())->whereIn('state', $from)->update(array_filter([
            'state' => $to, 'updated_at' => now(), 'note' => $note,
            'enabled_by_user_id' => $to === 'enabled' ? $by->getKey() : null, 'enabled_at' => $to === 'enabled' ? now() : null,
        ], fn ($v, $k) => $v !== null || $k === 'state', ARRAY_FILTER_USE_BOTH));
        if ($moved === 0) {
            throw new Refused("This watch is {$before}; it cannot go to {$to}.");
        }
        $watch->refresh();
        $this->audit($action, $watch, $by, ['state' => $before], ['state' => $to], $note);

        return $watch;
    }

    private function mayActFor(User $user, int $businessId): bool
    {
        $all = $this->scope->all($user);

        return $all === null || in_array($businessId, $all, true);
    }

    private function assertOn(): void
    {
        if (! Flag::on(Flag::EVENT_WATCHES)) {
            throw new Refused('Event watches are switched off.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(string $action, EventWatch $watch, ?User $by, ?array $before, ?array $after, ?string $reason = null): void
    {
        $this->auditor->record($action, $watch, before: $before, after: ($after ?? []) + ['watch' => $watch->uuid, 'business_id' => $watch->business_id],
            reason: $reason, actorUserId: $by?->getKey(), actorLabel: $by === null ? 'event-watch' : null);
    }
}
