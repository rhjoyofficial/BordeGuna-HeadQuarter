<?php

namespace App\Support\Money;

use App\Models\FxRate;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\GroupWideAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The owner's USD to BDT rate (ruling 1 Nov 2026): data with a date, a source, an expiry and an audit trail, never a constant.
 *
 * Two readers, two rules:
 *  - a paid media budget reservation needs `reserveRate()`. Missing, expired or invalid, it throws `FxRateUnavailable` and the
 *    operation is refused (fail closed; a reservation is a safety boundary);
 *  - an AI cost display needs `displayRate()`, the reference rate, shown as an estimate with its date. Missing or stale, it is
 *    null and the screen shows USD with a note (display is not a safety boundary).
 *
 * Changing the rate is `setRate()`: a named person with `hq.fx.manage` and group-wide authority (the same three layers as Pause All
 * AI: route middleware, controller, here), a reason, an audit row with before and after. No tool, job or agent calls it.
 */
final class FxRates
{
    public const PERMISSION = 'hq.fx.manage';

    public const BASE = 'USD';

    public const QUOTE = 'BDT';

    /** A rate may be declared valid for at most this many days after its as-of date. */
    public const MAX_VALIDITY_DAYS = 62;

    public const VALID = 'valid';

    public const MISSING = 'missing';

    public const EXPIRED = 'expired';

    public const NOT_YET = 'not_yet';

    public const INVALID = 'invalid';

    private ?FxRate $latest = null;

    private bool $loaded = false;

    public function __construct(private readonly Auditor $auditor, private readonly GroupWideAuthority $authority) {}

    /** The newest rate for USD/BDT, whatever its state. */
    public function latest(): ?FxRate
    {
        if (! $this->loaded) {
            $this->latest = FxRate::query()->where('base_currency', self::BASE)->where('quote_currency', self::QUOTE)->latest('id')->first();
            $this->loaded = true;
        }

        return $this->latest;
    }

    public function state(?FxRate $rate = null, ?CarbonImmutable $now = null): string
    {
        $rate ??= $this->latest();
        $now ??= CarbonImmutable::now();
        if ($rate === null) {
            return self::MISSING;
        }
        $reference = (string) $rate->reference_rate;
        $reserve = (string) $rate->reserve_rate;
        if (! is_numeric($reference) || ! is_numeric($reserve) || bccomp($reference, '0', 6) <= 0 || bccomp($reserve, $reference, 6) < 0) {
            return self::INVALID;
        }
        if ($rate->as_of === null || $rate->valid_until === null || $rate->valid_until->lessThanOrEqualTo($rate->as_of)) {
            return self::INVALID;
        }
        if ($rate->as_of->greaterThan($now)) {
            return self::NOT_YET;
        }

        return $now->greaterThan($rate->valid_until) ? self::EXPIRED : self::VALID;
    }

    /** Why the current rate cannot be used, or null when it can. */
    public function refusal(): ?string
    {
        $rate = $this->latest();

        return match ($this->state($rate)) {
            self::VALID => null,
            self::MISSING => 'No USD to BDT rate has been set',
            self::EXPIRED => 'The USD to BDT rate expired on '.$this->dhaka($rate?->valid_until),
            self::NOT_YET => 'The USD to BDT rate only applies from '.$this->dhaka($rate?->as_of),
            default => 'The USD to BDT rate on record is not valid',
        };
    }

    /**
     * The conservative rate for a budget reservation, and the row it came from.
     *
     * @return array{rate: string, fx_rate_id: int}
     *
     * @throws FxRateUnavailable when the rate is missing, expired or invalid
     */
    public function reserveRate(): array
    {
        // A reservation always reads the table afresh: a long-lived worker must never reserve on a remembered row.
        $this->loaded = false;
        $refusal = $this->refusal();
        $rate = $this->latest();
        if ($refusal !== null || $rate === null) {
            throw new FxRateUnavailable(($refusal ?? 'No USD to BDT rate has been set').'. The owner sets a new rate on the Exchange rate screen.');
        }

        return ['rate' => bcadd((string) $rate->reserve_rate, '0', 6), 'fx_rate_id' => (int) $rate->id];
    }

    /** BDT per USD for SHOWING a cost (the reference rate, an estimate), or null when the rate is missing or stale. */
    public function displayRate(): ?float
    {
        $rate = $this->latest();

        return $this->state($rate) === self::VALID ? (float) $rate->reference_rate : null;
    }

    /**
     * What every page gets as `money`: the display rate and the facts a screen states beside it.
     *
     * @return array<string, mixed>
     */
    public function shared(): array
    {
        $rate = $this->latest();
        $state = $this->state($rate);

        return [
            'usd_bdt_rate' => $state === self::VALID ? (float) $rate->reference_rate : null,
            'rate_state' => $state,
            'rate_as_of' => $rate?->as_of?->toIso8601String(),
            'rate_valid_until' => $rate?->valid_until?->toIso8601String(),
            'rate_source' => $rate?->source,
        ];
    }

    /**
     * The one write: a new row, never an edit. Inputs are strings as typed; dates are Asia/Dhaka calendar days (as-of from the
     * start of the day, valid-until to the end of it).
     *
     * @throws AuthorizationException when the person may not change a group-wide rate
     * @throws InvalidArgumentException when an input is not acceptable
     */
    public function setRate(User $by, string $referenceRate, string $reserveRate, string $source, string $asOfDate, string $validUntilDate, string $reason): FxRate
    {
        $this->authority->assert($by, self::PERMISSION);

        $referenceRate = trim($referenceRate);
        $reserveRate = trim($reserveRate);
        $source = trim($source);
        $reason = trim($reason);
        foreach (['reference' => $referenceRate, 'reserve' => $reserveRate] as $label => $value) {
            if (preg_match('/^\d{1,4}(\.\d{1,6})?$/', $value) !== 1 || bccomp($value, '0', 6) <= 0) {
                throw new InvalidArgumentException("The {$label} rate must be a positive number of BDT per USD, up to six decimals.");
            }
        }
        if (bccomp($referenceRate, '50', 6) < 0 || bccomp($referenceRate, '500', 6) > 0) {
            throw new InvalidArgumentException('The reference rate must be between BDT 50 and BDT 500 per USD.');
        }
        if (bccomp($reserveRate, $referenceRate, 6) < 0) {
            throw new InvalidArgumentException('The reserve rate is the safe one for budgets, so it cannot be below the reference rate.');
        }
        if (bccomp($reserveRate, bcmul($referenceRate, '1.5', 6), 6) > 0) {
            throw new InvalidArgumentException('The reserve rate is more than 50% above the reference rate. Check the figure.');
        }
        if ($source === '' || mb_strlen($source) > 255) {
            throw new InvalidArgumentException('Say where the rate comes from (up to 255 characters).');
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Say why the rate changes (up to 500 characters).');
        }

        $tz = (string) config('hq.timezone', 'Asia/Dhaka');
        try {
            $asOf = CarbonImmutable::createFromFormat('!Y-m-d', $asOfDate, $tz);
            $validUntil = CarbonImmutable::createFromFormat('!Y-m-d', $validUntilDate, $tz)?->endOfDay();
        } catch (\Throwable) {
            $asOf = $validUntil = null;
        }
        if (! $asOf || ! $validUntil || $asOf->format('Y-m-d') !== $asOfDate || $validUntil->format('Y-m-d') !== $validUntilDate) {
            throw new InvalidArgumentException('Give the dates as calendar days (YYYY-MM-DD).');
        }
        $today = CarbonImmutable::now($tz)->startOfDay();
        if ($asOf->greaterThan($today)) {
            throw new InvalidArgumentException('The as-of date cannot be in the future.');
        }
        if ($validUntil->lessThan(CarbonImmutable::now($tz))) {
            throw new InvalidArgumentException('The valid-until date has already passed, so the rate would be stale at once.');
        }
        if ($asOf->diffInDays($validUntil->startOfDay()) > self::MAX_VALIDITY_DAYS) {
            throw new InvalidArgumentException('A rate may be valid for at most '.self::MAX_VALIDITY_DAYS.' days after its as-of date.');
        }

        $margin = bcmul(bcsub(bcdiv($reserveRate, $referenceRate, 10), '1', 10), '100', 4);

        $row = DB::transaction(function () use ($by, $referenceRate, $reserveRate, $margin, $source, $asOf, $validUntil, $reason): FxRate {
            $before = $this->latest();
            $row = FxRate::query()->create([
                'base_currency' => self::BASE, 'quote_currency' => self::QUOTE,
                'reference_rate' => $referenceRate, 'reserve_rate' => $reserveRate, 'safety_margin_percent' => $margin,
                'source' => $source, 'as_of' => $asOf, 'valid_until' => $validUntil,
                'set_by_user_id' => $by->getKey(), 'reason' => $reason,
            ]);
            $this->auditor->record('fx.rate.set', $row, before: $before === null ? null : self::summary($before), after: self::summary($row),
                reason: $reason, actorUserId: $by->getKey(), context: $this->authority->auditContext(self::PERMISSION, $by));

            return $row;
        });

        $this->latest = $row->fresh();
        $this->loaded = true;

        return $this->latest;
    }

    /** @return array<string, mixed> */
    public static function summary(FxRate $rate): array
    {
        return [
            'id' => $rate->id,
            'pair' => $rate->base_currency.'/'.$rate->quote_currency,
            'reference_rate' => (string) $rate->reference_rate,
            'reserve_rate' => (string) $rate->reserve_rate,
            'safety_margin_percent' => (string) $rate->safety_margin_percent,
            'source' => $rate->source,
            'as_of' => $rate->as_of?->toIso8601String(),
            'valid_until' => $rate->valid_until?->toIso8601String(),
        ];
    }

    private function dhaka(?CarbonImmutable $at): string
    {
        return $at === null ? 'an unknown date' : $at->setTimezone((string) config('hq.timezone', 'Asia/Dhaka'))->format('j M Y H:i').' Dhaka time';
    }
}
