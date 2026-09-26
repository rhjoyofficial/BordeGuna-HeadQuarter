<?php

namespace App\Ads;

use App\Ads\Dto\AdAccountRead;
use App\Ads\Dto\AdInsightRow;
use App\Ads\Dto\AdObjectRead;
use Carbon\CarbonImmutable;

/**
 * Reads ONE connected ad account, provider-neutral (Phase 5 read side, `docs/84` C.4).
 *
 * ⛔ **Reads only.** There is no method here that changes anything at the provider, and an
 * implementation must not add one: a write is a separate, approval-gated tool (ADR-050), never a
 * method a reader happens to have.
 *
 * Every figure comes back in the account's OWN currency and is never converted (`docs/84` C.21):
 * a BDT account reads in BDT and a USD account in USD, and whoever compares them must say so.
 * Everything a provider returns is `ThirdParty` provenance (ADR-024): the platform's own count.
 */
interface AdsReader
{
    /** Levels an insights read may be asked for, finest last. */
    public const LEVELS = ['campaign', 'adset', 'ad'];

    public function account(): AdAccountRead;

    /** @return list<AdObjectRead> */
    public function campaigns(): array;

    /** @return list<AdObjectRead> */
    public function adSets(): array;

    /** @return list<AdObjectRead> */
    public function ads(): array;

    /**
     * Daily insights rows (one per object per day) for a closed date range, in the account's time
     * zone, at one level of `LEVELS`.
     *
     * @return list<AdInsightRow>
     */
    public function insights(string $level, CarbonImmutable $since, CarbonImmutable $until): array;

    /** The provider's API version this reader speaks, for provenance. */
    public function apiVersion(): string;
}
