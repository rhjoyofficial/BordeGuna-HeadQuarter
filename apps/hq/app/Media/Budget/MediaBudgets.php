<?php

namespace App\Media\Budget;

use App\Models\Business;
use App\Models\MediaBudget;
use App\Models\MediaBudgetEntry;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The per-business monthly media spending cap (ruling 31 Oct 2026 item 12, docs/147-r2).
 *
 *  - Business-specific limit and ledger. A business with no row has BDT 0, and BDT 0 refuses every paid media operation.
 *  - The month is the Asia/Dhaka business month (`hq.timezone`).
 *  - A paid provider call RESERVES its estimate first. The reservation locks the business's budget row (`SELECT ... FOR UPDATE`),
 *    sums the month's ledger and refuses if the estimate would take it over the limit, all in one transaction, so concurrent
 *    reservations are serialised and can never add up to more than the limit.
 *  - The estimate is converted to BDT conservatively (configured rate plus a safety margin, rounded UP to the paisa). An unknown
 *    price, an unknown currency or an empty rate is a refusal, never a guess.
 *  - After the call: COMMIT (spent, or uncertain), RELEASE (the provider definitely did not run it), and later RECONCILE against the
 *    provider's reported cost. Estimate and actual stay side by side; a reconciled line counts at its actual.
 *  - No borrowing: a reservation is counted only against its own business. No AI path changes a limit: `setLimit()` takes a person
 *    who must hold the owner role, the permission and the business in scope; no tool, job or agent calls it (a test holds that).
 *
 * The cap is necessary, never sufficient: every gateway, approval, consent and flag check still runs before a tool gets here.
 */
final class MediaBudgets
{
    public const KINDS = ['video', 'image', 'voice', 'avatar', 'other'];

    public const PERMISSION_MANAGE = 'hq.media.budget.manage';

    private const SCALE = 6;

    public function __construct(private readonly Auditor $auditor, private readonly BusinessScope $scope) {}

    /** The Asia/Dhaka business month, YYYY-MM. */
    public function period(?CarbonInterface $at = null): string
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone((string) config('hq.timezone', 'Asia/Dhaka'))->format('Y-m');
    }

    public function limitBdt(int $businessId): string
    {
        $limit = MediaBudget::query()->where('business_id', $businessId)->value('monthly_limit_bdt');

        return self::money($limit === null ? '0' : (string) $limit);
    }

    /** What this business's month has used: reserved and committed lines at their estimate, reconciled lines at their actual. */
    public function usedBdt(int $businessId, ?string $period = null): string
    {
        $period ??= $this->period();
        $sum = '0';
        foreach (MediaBudgetEntry::query()->where('business_id', $businessId)->where('period', $period)
            ->whereIn('state', [MediaBudgetEntry::RESERVED, MediaBudgetEntry::COMMITTED, MediaBudgetEntry::RECONCILED])
            ->get(['state', 'estimate_bdt', 'actual_bdt']) as $row) {
            $line = $row->state === MediaBudgetEntry::RECONCILED ? (string) $row->actual_bdt : (string) $row->estimate_bdt;
            $sum = bcadd($sum, $line, 2);
        }

        return self::money($sum);
    }

    /**
     * The estimated cost of a paid media operation from the configured price list, or null when the price is not known
     * (null means the operation is refused).
     */
    public function estimate(string $provider, float|int|string $units): ?MediaCost
    {
        $price = config("hq.media.budget.prices.{$provider}");
        if (! is_array($price) || ($price['price'] ?? null) === null || ($price['price'] ?? '') === '' || empty($price['currency'])) {
            return null;
        }
        $perUnit = (string) $price['price'];
        $divisor = (string) ($price['per'] ?? '1');
        if (! is_numeric($perUnit) || ! is_numeric($divisor) || bccomp($divisor, '0', self::SCALE) <= 0 || bccomp($perUnit, '0', self::SCALE) < 0) {
            return null;
        }
        $amount = bcdiv(bcmul($perUnit, (string) $units, self::SCALE), $divisor, self::SCALE);

        return new MediaCost(self::round4($amount), strtoupper((string) $price['currency']), "{$units} {$price['unit']} at {$perUnit} {$price['currency']} per {$divisor}");
    }

    /**
     * Converts a provider cost to BDT conservatively: rate plus the safety margin, rounded UP to the paisa.
     *
     * @return array{rate: string, bdt: string}
     */
    public function toBdt(MediaCost $cost): array
    {
        $currency = strtoupper($cost->currency);
        if (! is_numeric($cost->amount) || bccomp($cost->amount, '0', self::SCALE) < 0) {
            throw new MediaBudgetRefused('The cost of this media operation cannot be established, so it is refused.');
        }
        if ($currency === 'BDT') {
            return ['rate' => '1.000000', 'bdt' => self::ceilMoney($cost->amount)];
        }
        $rate = config("hq.media.budget.fx_to_bdt.{$currency}");
        if ($rate === null || $rate === '' || ! is_numeric((string) $rate) || bccomp((string) $rate, '0', self::SCALE) <= 0) {
            throw new MediaBudgetRefused("No safe {$currency} to BDT rate is configured, so the cost cannot be converted and the operation is refused.");
        }
        $margin = (string) config('hq.media.budget.fx_safety_margin_percent', '5');
        if (! is_numeric($margin) || bccomp($margin, '0', self::SCALE) < 0) {
            throw new MediaBudgetRefused('The currency safety margin is not valid, so the cost cannot be converted safely.');
        }
        $conservative = bcmul((string) $rate, bcadd('1', bcdiv($margin, '100', self::SCALE), self::SCALE), self::SCALE);

        return ['rate' => $conservative, 'bdt' => self::ceilMoney(bcmul($cost->amount, $conservative, self::SCALE))];
    }

    /**
     * Reserve the estimate BEFORE the provider is called. Refused (MediaBudgetRefused) when the limit is BDT 0, when the cost or the
     * conversion is unknown, or when the month's use plus this estimate would exceed the limit. Idempotent on `$reference`: the
     * same reference returns the line it already reserved, so a replayed call never reserves twice.
     */
    public function reserve(int $businessId, string $kind, string $provider, string $toolKey, string $reference, ?MediaCost $estimate, ?int $userId = null): MediaBudgetEntry
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new MediaBudgetRefused("[{$kind}] is not a media budget kind.");
        }
        if ($estimate === null) {
            throw new MediaBudgetRefused("The cost of [{$provider}] is not known (no price is configured), so this paid media operation is refused.");
        }
        ['rate' => $rate, 'bdt' => $bdt] = $this->toBdt($estimate);
        $period = $this->period();

        return DB::transaction(function () use ($businessId, $kind, $provider, $toolKey, $reference, $estimate, $userId, $rate, $bdt, $period): MediaBudgetEntry {
            // The serialisation point: every reservation of this business takes this row lock first.
            $budget = MediaBudget::query()->where('business_id', $businessId)->lockForUpdate()->first();

            $existing = MediaBudgetEntry::query()->where('business_id', $businessId)->where('reference', $reference)->lockForUpdate()->first();
            if ($existing !== null && $existing->state !== MediaBudgetEntry::RELEASED) {
                return $existing;
            }

            $limit = self::money($budget === null ? '0' : (string) $budget->monthly_limit_bdt);
            if (bccomp($limit, '0', 2) <= 0) {
                throw new MediaBudgetRefused('This business\'s media budget is BDT 0, so no paid media may run. The owner sets it on the Connectors screen.');
            }
            $used = $this->usedBdt($businessId, $period);
            if (bccomp(bcadd($used, $bdt, 2), $limit, 2) > 0) {
                throw new MediaBudgetRefused("This would take the month's media spending to BDT ".bcadd($used, $bdt, 2)." of a BDT {$limit} limit, so it is refused.");
            }

            $values = [
                'period' => $period, 'kind' => $kind, 'provider' => mb_substr($provider, 0, 64), 'tool_key' => mb_substr($toolKey, 0, 64),
                'estimate_amount' => $estimate->amount, 'estimate_currency' => strtoupper($estimate->currency), 'fx_rate_to_bdt' => $rate,
                'estimate_bdt' => $bdt, 'state' => MediaBudgetEntry::RESERVED, 'basis' => mb_substr($estimate->basis, 0, 255),
                'requested_by_user_id' => $userId, 'actual_amount' => null, 'actual_currency' => null, 'actual_bdt' => null,
            ];
            if ($existing !== null) {
                $existing->forceFill($values)->save();

                return $existing;
            }

            return MediaBudgetEntry::query()->create($values + ['uuid' => (string) Str::uuid(), 'business_id' => $businessId, 'reference' => mb_substr($reference, 0, 191)]);
        });
    }

    /** The provider took the job (or its answer is uncertain): the estimate stays spent until reconciled. */
    public function commit(MediaBudgetEntry $entry): void
    {
        MediaBudgetEntry::query()->whereKey($entry->getKey())->where('state', MediaBudgetEntry::RESERVED)->update(['state' => MediaBudgetEntry::COMMITTED, 'updated_at' => now()]);
    }

    /** The provider definitely did not run it: the reservation is given back. */
    public function release(MediaBudgetEntry $entry): void
    {
        MediaBudgetEntry::query()->whereKey($entry->getKey())->where('state', MediaBudgetEntry::RESERVED)->update(['state' => MediaBudgetEntry::RELEASED, 'updated_at' => now()]);
    }

    /**
     * Record what the provider reported for a committed line. The estimate is kept; the line now counts at the actual. Refused when
     * the currency cannot be converted safely (the line keeps counting at its estimate).
     */
    public function reconcile(MediaBudgetEntry $entry, MediaCost $actual, ?User $by): MediaBudgetEntry
    {
        // A reconciled line counts at its actual, which can free headroom, so recording it is the owner's act, like the limit.
        if ($by !== null && (! $by->hasRole('owner') || ! $by->can(self::PERMISSION_MANAGE))) {
            throw new MediaBudgetRefused('Only the owner may record a provider\'s billed cost.');
        }
        if ($by !== null && ! $this->scope->allows($by, (int) $entry->business_id)) {
            throw new MediaBudgetRefused('That business is outside what you may use.');
        }
        ['bdt' => $bdt] = $this->toBdt($actual);

        return DB::transaction(function () use ($entry, $actual, $by, $bdt): MediaBudgetEntry {
            $row = MediaBudgetEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($row->state, [MediaBudgetEntry::COMMITTED, MediaBudgetEntry::RECONCILED], true)) {
                throw new MediaBudgetRefused('Only a committed media cost can be reconciled.');
            }
            $before = ['state' => $row->state, 'actual_bdt' => $row->actual_bdt];
            $row->forceFill(['actual_amount' => self::round4($actual->amount), 'actual_currency' => strtoupper($actual->currency), 'actual_bdt' => $bdt,
                'state' => MediaBudgetEntry::RECONCILED, 'reconciled_by_user_id' => $by?->getKey(), 'reconciled_at' => now()])->save();
            $this->auditor->record('media.budget.reconciled', $row, before: $before,
                after: ['estimate_bdt' => (string) $row->estimate_bdt, 'actual_bdt' => $bdt, 'actual' => $actual->amount.' '.$actual->currency], actorUserId: $by?->getKey());

            return $row;
        });
    }

    /**
     * The owner sets a business's monthly limit. A person with the owner role, the permission and the business in scope; never a
     * tool, a job or an agent (none can reach this: `MediaBudgetStructureTest`).
     */
    public function setLimit(User $by, Business $business, string $limitBdt, string $reason): MediaBudget
    {
        if (! $by->hasRole('owner') || ! $by->can(self::PERMISSION_MANAGE)) {
            throw new MediaBudgetRefused('Only the owner may set a media budget.');
        }
        if (! $this->scope->allows($by, (int) $business->id)) {
            throw new MediaBudgetRefused('That business is outside what you may use.');
        }
        if (! is_numeric($limitBdt) || bccomp($limitBdt, '0', 2) < 0 || bccomp($limitBdt, '100000000', 2) > 0) {
            throw new MediaBudgetRefused('A media budget is between BDT 0 and BDT 100,000,000.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new MediaBudgetRefused('Say why the budget changes.');
        }

        return DB::transaction(function () use ($by, $business, $limitBdt, $reason): MediaBudget {
            $row = MediaBudget::query()->where('business_id', $business->id)->lockForUpdate()->first() ?? new MediaBudget(['business_id' => $business->id]);
            $before = $row->exists ? ['monthly_limit_bdt' => (string) $row->monthly_limit_bdt] : ['monthly_limit_bdt' => '0.00'];
            $row->forceFill(['monthly_limit_bdt' => self::money($limitBdt), 'set_by_user_id' => $by->getKey(), 'reason' => mb_substr($reason, 0, 500)])->save();
            $this->auditor->record('media.budget.set', $row, before: $before, after: ['monthly_limit_bdt' => self::money($limitBdt), 'business_id' => $business->id], reason: $reason, actorUserId: $by->getKey());

            return $row;
        });
    }

    /**
     * What the Connectors screen shows: every business this person may use, its limit, this month's use and the latest ledger
     * lines (estimate beside actual).
     *
     * @return array<string, mixed>
     */
    public function overviewFor(User $user): array
    {
        $period = $this->period();
        $businesses = [];
        foreach (Business::query()->orderBy('name')->get(['id', 'slug', 'name']) as $business) {
            if (! $this->scope->allows($user, (int) $business->id)) {
                continue;
            }
            $businesses[] = ['slug' => $business->slug, 'name' => $business->name, 'limit_bdt' => $this->limitBdt((int) $business->id),
                'used_bdt' => $this->usedBdt((int) $business->id, $period)];
        }
        $names = Business::query()->pluck('name', 'id');
        $entries = MediaBudgetEntry::query()->visibleTo($user)->latest('id')->limit(30)->get()->map(fn (MediaBudgetEntry $e) => [
            'uuid' => $e->uuid, 'business' => $names[$e->business_id] ?? null, 'period' => $e->period, 'kind' => $e->kind, 'provider' => $e->provider,
            'tool_key' => $e->tool_key, 'estimate' => $e->estimate_amount.' '.$e->estimate_currency, 'estimate_bdt' => (string) $e->estimate_bdt,
            'actual' => $e->actual_amount === null ? null : $e->actual_amount.' '.$e->actual_currency,
            'actual_bdt' => $e->actual_bdt === null ? null : (string) $e->actual_bdt,
            'state' => $e->state, 'created_at' => $e->created_at?->toIso8601String(),
        ])->all();

        return [
            'period' => $period,
            'timezone' => (string) config('hq.timezone', 'Asia/Dhaka'),
            'businesses' => $businesses,
            'entries' => $entries,
            'can_manage' => $user->hasRole('owner') && $user->can(self::PERMISSION_MANAGE),
        ];
    }

    public static function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }

    private static function round4(string $value): string
    {
        return bcadd($value, '0', 4);
    }

    /** Round UP to the paisa: a conservative BDT figure never understates a cost. */
    private static function ceilMoney(string $value): string
    {
        $truncated = bcadd($value, '0', 2);

        return bccomp($value, $truncated, self::SCALE) > 0 ? bcadd($truncated, '0.01', 2) : $truncated;
    }
}
