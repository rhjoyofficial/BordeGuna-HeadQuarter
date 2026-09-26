<?php

namespace App\Ads\Meta;

use App\Ads\AdsReader;
use App\Ads\Dto\AdAccountRead;
use App\Ads\Dto\AdInsightRow;
use App\Ads\Dto\AdMoney;
use App\Ads\Dto\AdObjectRead;
use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Support\ConnectorCallLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Reads one Meta ad account through the Marketing API (Phase 5 read side, `docs/84` C.4, C.5).
 *
 * ⛔ **GET, and nothing else.** Every request in this class goes through `get()`, which can only
 * issue a GET; a test reads this file and fails if any other HTTP verb appears. Preparing, pausing,
 * budget changes and switching on are separate approval-gated tools (ADR-050) that do not exist.
 *
 * ⚠ **The token never leaves this object.** It is taken from the CredentialVault's open scope by
 * `MetaAdsPlatform`, sent as an OAuth bearer header (so it is never part of a URL, and never in a
 * `connector_calls` row, a log line or an error), and held only for the life of this reader.
 *
 * **Facts read from Meta's own pages on 22 September 2026** (and 21 September for `docs/84`):
 *  - versions: Marketing API v26.0 (29 July 2026, sunset TBD), v25.0, v24.0 (sunset 6 October
 *    2026); the version is configuration (`hq.ads.meta.graph_version`), never a literal here;
 *  - Ad Account: `account_status` codes, `spend_cap` and `amount_spent` "in basic unit of the
 *    currency, for example 'cents' for USD"; Currencies: BDT and USD offsets 100;
 *  - Campaign: `daily_budget`, `lifetime_budget` and `spend_cap` in the currency's subunit;
 *  - Insights: `level` one of ad, adset, campaign, account; `time_range` {since, until};
 *    `time_increment` 1 for daily rows; fields spend, account_currency, impressions, clicks, reach,
 *    actions, action_values, date_start, date_stop, campaign_id, adset_id, ad_id;
 *  - pagination: follow `paging.next`; its absence is the last page;
 *  - errors: `{error: {message, type, code, error_subcode, fbtrace_id}}`; 190 access token expired;
 *    10 and 200-299 permission; 1, 2 temporary; 4, 17, 341 throttling; rate limiting adds 613 and
 *    80000 to 80014; headers `X-Ad-Account-Usage` (acc_id_util_pct, reset_time_duration),
 *    `X-Business-Use-Case` (call_count, total_cputime, total_time, estimated_time_to_regain_access),
 *    `X-FB-Ads-Insights-Throttle` (app_id_util_pct, acc_id_util_pct).
 * **Not verified on those pages:** that insights `spend` is in the major unit (it is in Meta's
 * examples and in practice, and `AdMoney::major` refuses more decimals than the currency has, so a
 * change would fail loudly); that the Graph API accepts the token as an `Authorization: Bearer`
 * header (the OAuth 2.0 form; if it does not, the first P5-G1 call fails with 190 and says so).
 */
final class MetaAdsReader implements AdsReader
{
    public const CONNECTOR_PREFIX = 'meta_ads:';

    /** Throttling and temporary codes: `unavailable`, retried later, never `failed` (`docs/84` C.5). */
    public const THROTTLE_CODES = [1, 2, 4, 17, 32, 341, 613];

    private const OBJECT_FIELDS = 'id,name,status,effective_status,created_time,updated_time';

    /** The last rate usage Meta reported, as percentages by header, for provenance and back-off. */
    private array $usage = [];

    /** The account read once per reader: every budget below is stated in its currency. */
    private ?AdAccountRead $account = null;

    public function __construct(
        public readonly string $businessSlug,
        /** `act_<digits>`, as `platform_accounts.account_ref` holds it or without the prefix. */
        private readonly string $accountRef,
        private readonly string $token,
        private readonly ConnectorCallLogger $logger,
        private readonly string $baseUrl = 'https://graph.facebook.com',
        private readonly string $version = 'v26.0',
        private readonly int $timeoutSeconds = 20,
        /** Stop before Meta stops us: at this usage percentage the next call is refused as unavailable. */
        private readonly int $backoffAtPercent = 80,
    ) {
        if (! preg_match('/^(act_)?\d{5,32}$/', $accountRef)) {
            throw new InvalidArgumentException("Not an ad account reference: [{$accountRef}]");
        }
    }

    public function apiVersion(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function usage(): array
    {
        return $this->usage;
    }

    public function account(): AdAccountRead
    {
        return $this->account ??= $this->readAccount();
    }

    private function readAccount(): AdAccountRead
    {
        $a = $this->get($this->act(), 'account', [
            'fields' => 'id,name,currency,timezone_name,account_status,disable_reason,spend_cap,amount_spent',
        ]);
        $currency = strtoupper((string) ($a['currency'] ?? ''));
        if ($currency === '') {
            throw new Refused('Meta account: no currency in the answer; nothing can be read without it.');
        }
        $cap = self::minor($a['spend_cap'] ?? null);
        // An account with no spending limit answers "0" in practice (not stated on the reference page
        // read on 22 September). Read as "no limit set", which the budget guard treats as UNKNOWN and
        // fails closed on, so the reading can only make HQ more cautious, never less.
        $cap = $cap === '0' ? null : $cap;
        $spent = self::minor($a['amount_spent'] ?? null);

        return new AdAccountRead(
            accountRef: $this->act(),
            name: isset($a['name']) ? (string) $a['name'] : null,
            currency: $currency,
            timezone: isset($a['timezone_name']) ? (string) $a['timezone_name'] : null,
            status: AdAccountRead::statusLabel($a['account_status'] ?? null),
            spendCap: AdMoney::fromMinor($cap, $currency),
            amountSpent: AdMoney::fromMinor($spent, $currency),
            spendCapMinor: $cap,
            amountSpentMinor: $spent,
            disableReason: isset($a['disable_reason']) ? (int) $a['disable_reason'] : null,
        );
    }

    public function campaigns(): array
    {
        $currency = $this->account()->currency;

        return array_map(fn (array $c) => new AdObjectRead(
            level: 'campaign',
            ref: (string) $c['id'],
            name: $c['name'] ?? null,
            status: $c['status'] ?? null,
            effectiveStatus: $c['effective_status'] ?? null,
            objective: $c['objective'] ?? null,
            dailyBudget: AdMoney::fromMinor(self::minor($c['daily_budget'] ?? null), $currency),
            lifetimeBudget: AdMoney::fromMinor(self::minor($c['lifetime_budget'] ?? null), $currency),
            spendCap: AdMoney::fromMinor(self::minor($c['spend_cap'] ?? null), $currency),
            specialAdCategories: array_values(array_map('strval', (array) ($c['special_ad_categories'] ?? []))),
            createdAt: $c['created_time'] ?? null,
            updatedAt: $c['updated_time'] ?? null,
        ), $this->pages($this->act().'/campaigns', 'campaigns', [
            'fields' => self::OBJECT_FIELDS.',objective,daily_budget,lifetime_budget,spend_cap,special_ad_categories',
        ]));
    }

    public function adSets(): array
    {
        $currency = $this->account()->currency;

        return array_map(fn (array $s) => new AdObjectRead(
            level: 'adset',
            ref: (string) $s['id'],
            name: $s['name'] ?? null,
            status: $s['status'] ?? null,
            effectiveStatus: $s['effective_status'] ?? null,
            campaignRef: isset($s['campaign_id']) ? (string) $s['campaign_id'] : null,
            dailyBudget: AdMoney::fromMinor(self::minor($s['daily_budget'] ?? null), $currency),
            lifetimeBudget: AdMoney::fromMinor(self::minor($s['lifetime_budget'] ?? null), $currency),
            createdAt: $s['created_time'] ?? null,
            updatedAt: $s['updated_time'] ?? null,
        ), $this->pages($this->act().'/adsets', 'adSets', [
            'fields' => self::OBJECT_FIELDS.',campaign_id,daily_budget,lifetime_budget',
        ]));
    }

    public function ads(): array
    {
        return array_map(fn (array $ad) => new AdObjectRead(
            level: 'ad',
            ref: (string) $ad['id'],
            name: $ad['name'] ?? null,
            status: $ad['status'] ?? null,
            effectiveStatus: $ad['effective_status'] ?? null,
            campaignRef: isset($ad['campaign_id']) ? (string) $ad['campaign_id'] : null,
            adsetRef: isset($ad['adset_id']) ? (string) $ad['adset_id'] : null,
            createdAt: $ad['created_time'] ?? null,
            updatedAt: $ad['updated_time'] ?? null,
        ), $this->pages($this->act().'/ads', 'ads', [
            'fields' => self::OBJECT_FIELDS.',campaign_id,adset_id',
        ]));
    }

    public function insights(string $level, CarbonImmutable $since, CarbonImmutable $until): array
    {
        if (! in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('Insights level must be one of '.implode(', ', self::LEVELS).", not [{$level}]");
        }
        if ($until->lt($since)) {
            throw new InvalidArgumentException('Insights range ends before it starts.');
        }

        $rows = $this->pages($this->act().'/insights', 'insights', [
            'level' => $level,
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $since->toDateString(), 'until' => $until->toDateString()]),
            'fields' => 'account_currency,spend,impressions,clicks,reach,actions,action_values,date_start,date_stop,'
                .'campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name',
        ]);

        return array_map(function (array $r) use ($level) {
            $currency = strtoupper((string) ($r['account_currency'] ?? ''));
            if ($currency === '') {
                throw new Refused('Meta insights: a row without account_currency; a spend without its currency is not read.');
            }
            $ref = (string) ($r[$level.'_id'] ?? '');
            if ($ref === '') {
                throw new Refused("Meta insights: a {$level} row without {$level}_id.");
            }

            return new AdInsightRow(
                level: $level,
                objectRef: $ref,
                objectName: $r[$level.'_name'] ?? null,
                campaignRef: isset($r['campaign_id']) ? (string) $r['campaign_id'] : null,
                adsetRef: isset($r['adset_id']) ? (string) $r['adset_id'] : null,
                adRef: isset($r['ad_id']) ? (string) $r['ad_id'] : null,
                date: (string) ($r['date_start'] ?? ''),
                currency: $currency,
                spend: AdMoney::major($r['spend'] ?? '0', $currency),
                impressions: (int) ($r['impressions'] ?? 0),
                clicks: (int) ($r['clicks'] ?? 0),
                reach: (int) ($r['reach'] ?? 0),
                purchases: self::actionCount($r['actions'] ?? null),
                purchaseValue: ($v = self::actionValue($r['action_values'] ?? null)) === null ? null : AdMoney::major($v, $currency),
            );
        }, $rows);
    }

    /** `act_<id>` whatever form the account row holds. */
    private function act(): string
    {
        return str_starts_with($this->accountRef, 'act_') ? $this->accountRef : 'act_'.$this->accountRef;
    }

    /**
     * Every page of a list, following `paging.next` until it is absent (Graph API "Paginated
     * Results": "Stop paging when the next link no longer appears"). Capped, so a looping cursor is
     * an error, never an endless read.
     *
     * @return list<array<string, mixed>>
     */
    private function pages(string $path, string $operation, array $query, int $maxPages = 50): array
    {
        $out = [];
        $body = $this->get($path, $operation, $query + ['limit' => 200]);
        for ($page = 1; ; $page++) {
            foreach ((array) ($body['data'] ?? []) as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            $after = $body['paging']['cursors']['after'] ?? null;
            if (empty($body['paging']['next']) || ! is_string($after) || $after === '') {
                return $out;
            }
            if ($page >= $maxPages) {
                throw new Refused("Meta {$operation}: more than {$maxPages} pages; refusing to read further.");
            }
            // The cursor, not the `next` URL: the next URL carries the token in its query string.
            $body = $this->get($path, $operation, $query + ['limit' => 200, 'after' => $after]);
        }
    }

    /**
     * The ONLY way this class talks to Meta. A GET, logged, token in a header, errors classified.
     *
     * @return array<string, mixed>
     */
    private function get(string $path, string $operation, array $query = []): array
    {
        $this->refuseIfNearLimit($operation);

        return $this->logger->call(self::CONNECTOR_PREFIX.$this->businessSlug, $operation, function () use ($path, $query, $operation) {
            try {
                $response = Http::withToken($this->token)
                    ->acceptJson()
                    ->timeout($this->timeoutSeconds)
                    ->get(rtrim($this->baseUrl, '/').'/'.$this->version.'/'.ltrim($path, '/'), $query);
            } catch (ConnectionException $e) {
                throw new Unavailable('Meta unreachable: '.$e->getMessage(), 0, $e);
            }

            $this->noteUsage($response);
            $this->throwForError($response, $operation);

            $json = $response->json();
            if (! is_array($json)) {
                throw new Refused("Meta {$operation}: the answer was not JSON.");
            }

            return $json;
        });
    }

    private function throwForError(Response $response, string $operation): void
    {
        $status = $response->status();
        $error = $response->json('error');
        if ($status >= 200 && $status < 300 && ! is_array($error)) {
            return;
        }

        $code = is_array($error) ? (int) ($error['code'] ?? 0) : 0;
        $subcode = is_array($error) ? (int) ($error['error_subcode'] ?? 0) : 0;
        $trace = is_array($error) && isset($error['fbtrace_id']) ? ' (fbtrace '.$error['fbtrace_id'].')' : '';
        // Meta's message, never the request: the URL and headers are not echoed anywhere.
        $message = "Meta {$operation}: ".(is_array($error) ? (string) ($error['message'] ?? "HTTP {$status}") : "HTTP {$status}")
            ." [code {$code}".($subcode ? "/{$subcode}" : '')."]{$trace}";

        match (true) {
            // The token is expired or invalid: the credential's problem, never retried.
            $code === 190 => throw new Unsupported($message, 401),
            // The token lacks the permission on this account.
            $code === 10 || ($code >= 200 && $code <= 299) => throw new Unsupported($message, 403),
            // Throttled or temporarily down: unavailable, retried later with back-off, never `failed`.
            in_array($code, self::THROTTLE_CODES, true) || ($code >= 80000 && $code <= 80014) => throw new Unavailable($message, 429),
            $code === 100 => throw new ValidationFailed($message, ['meta' => [$message]]),
            $status >= 500 => throw new Unavailable($message, $status),
            default => throw new Refused($message, $status >= 400 ? $status : 400),
        };
    }

    /** Reads Meta's usage headers (percentages), so the next call can stop short of the limit. */
    private function noteUsage(Response $response): void
    {
        $usage = [];
        foreach (['X-Ad-Account-Usage', 'X-Business-Use-Case', 'X-Business-Use-Case-Usage', 'X-FB-Ads-Insights-Throttle'] as $header) {
            $raw = $response->header($header);
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $usage[strtolower($header)] = $decoded;
            }
        }
        if ($usage !== []) {
            $this->usage = $usage;
        }
    }

    private function refuseIfNearLimit(string $operation): void
    {
        $peak = self::peakPercent($this->usage);
        if ($peak !== null && $peak >= $this->backoffAtPercent) {
            throw new Unavailable("Meta {$operation}: not sent; the account's rate usage is at {$peak}%, at or above HQ's back-off of {$this->backoffAtPercent}%.", 429);
        }
    }

    /** The highest percentage anywhere in the usage headers (they nest per business and per type). */
    private static function peakPercent(array $usage): ?int
    {
        $peak = null;
        array_walk_recursive($usage, function ($value, $key) use (&$peak) {
            if (is_string($key) && (str_ends_with($key, '_pct') || in_array($key, ['call_count', 'total_cputime', 'total_time'], true)) && is_numeric($value)) {
                $peak = max($peak ?? 0, (int) $value);
            }
        });

        return $peak;
    }

    private static function minor(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /** Meta's attributed purchases: the `purchase` action (or the pixel's `offsite_conversion.fb_pixel_purchase`). */
    private static function actionCount(mixed $actions): ?int
    {
        $value = self::purchaseAction($actions);

        return $value === null ? null : (int) $value;
    }

    private static function actionValue(mixed $values): ?string
    {
        return self::purchaseAction($values);
    }

    private static function purchaseAction(mixed $list): ?string
    {
        if (! is_array($list)) {
            return null;
        }
        $byType = [];
        foreach ($list as $a) {
            if (is_array($a) && isset($a['action_type'], $a['value'])) {
                $byType[(string) $a['action_type']] = (string) $a['value'];
            }
        }

        // `purchase` is Meta's de-duplicated total; the pixel type is used only when it is alone.
        return $byType['purchase'] ?? $byType['offsite_conversion.fb_pixel_purchase'] ?? null;
    }
}
