<?php

use App\Ads\Dto\AdMoney;
use App\Ads\Meta\MetaAdsReader;
use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Support\ConnectorCallLogger;
use App\Gateway\OutcomeClass;
use App\Models\ConnectorCall;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 | The Meta read adapter (stream S, docs/88 A point 4), against written fixtures only.
 |
 | ⛔ Reads only: every request is a GET, the token travels in a header and never in a URL or a stored
 | row, money is exact in the account's own currency, and a throttle is `unavailable`, never `failed`.
 */

function metaAdsFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/meta-ads/{$name}.json")), true);
}

/** Routes a faked Graph request by its path (and the `after` cursor), so the test states what Meta answers. */
function metaAdsFake(array $routes): void
{
    Http::fake(function (Request $request) use ($routes) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $key = $path.(isset($query['after']) ? '?after='.$query['after'] : '');
        $hit = $routes[$key] ?? $routes[$path] ?? null;
        if ($hit === null) {
            return Http::response(['error' => ['message' => "unfaked {$key}", 'code' => 100]], 400);
        }
        [$body, $status, $headers] = array_pad((array) $hit, 3, null);

        return Http::response(is_string($body) ? metaAdsFixture($body) : $body, $status ?? 200, $headers ?? []);
    });
}

function metaReader(string $account = '28164410319885213', int $backoff = 80): MetaAdsReader
{
    return new MetaAdsReader('bor-de-guna', $account, 'SECRET-SYSTEM-USER-TOKEN', app(ConnectorCallLogger::class), version: 'v26.0', backoffAtPercent: $backoff);
}

const META_ADS_ACT = '/v26.0/act_28164410319885213';

it('reads the account with the owner\'s limit converted exactly from basic units, and never writes it', function () {
    metaAdsFake([META_ADS_ACT => ['account-bdt']]);

    $a = metaReader()->account();

    expect($a->currency)->toBe('BDT')->and($a->timezone)->toBe('Asia/Dhaka')->and($a->status)->toBe('active')
        ->and($a->spendCap)->toBe('50000.00')->and($a->amountSpent)->toBe('12345.50')
        ->and($a->spendCapMinor)->toBe('5000000')->and($a->remainingUnderCap())->toBe('37654.50');
    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && str_contains($r->url(), 'fields=id%2Cname%2Ccurrency%2Ctimezone_name%2Caccount_status%2Cdisable_reason%2Cspend_cap%2Camount_spent'));
});

it('reads "no limit set" as unknown, never as a limit of zero or as room to spend', function () {
    metaAdsFake(['/v26.0/act_37981153204862266' => ['account-usd-no-limit']]);

    $a = metaReader('act_37981153204862266')->account();

    expect($a->currency)->toBe('USD')->and($a->spendCap)->toBeNull()->and($a->spendCapMinor)->toBeNull()
        ->and($a->amountSpent)->toBe('42.10')->and($a->remainingUnderCap())->toBeNull();
});

it('follows every page by cursor, never by the next URL that carries a token, and states budgets in the account currency', function () {
    metaAdsFake([
        META_ADS_ACT => ['account-bdt'],
        META_ADS_ACT.'/campaigns' => ['campaigns-page1'],
        META_ADS_ACT.'/campaigns?after=CURSOR-PAGE-2' => ['campaigns-page2'],
    ]);

    $campaigns = metaReader()->campaigns();

    expect($campaigns)->toHaveCount(3)
        ->and($campaigns[0]->dailyBudget)->toBe('500.00')
        ->and($campaigns[1]->lifetimeBudget)->toBe('3000.00')->and($campaigns[1]->spendCap)->toBe('3000.00')
        ->and($campaigns[1]->specialAdCategories)->toBe(['NONE'])
        ->and($campaigns[2]->status)->toBe('ARCHIVED');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'NEVER-FOLLOWED'));
});

it('reads daily insights at each level with Meta\'s purchases kept apart as the platform\'s count', function () {
    metaAdsFake([META_ADS_ACT.'/insights' => ['insights-campaign']]);

    $rows = metaReader()->insights('campaign', CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21'));

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->toArray())->toMatchArray(['level' => 'campaign', 'object_ref' => '120210000000000001', 'date' => '2026-09-20',
            'currency' => 'BDT', 'spend' => '412.35', 'impressions' => 10220, 'clicks' => 311, 'purchases' => 4, 'purchase_value' => '3960.00'])
        ->and($rows[1]->spend)->toBe('500.00')->and($rows[1]->purchases)->toBeNull()
        // Only the pixel's purchase type reported: used, because it is alone.
        ->and($rows[2]->spend)->toBe('87.10')->and($rows[2]->purchases)->toBe(1);
    Http::assertSent(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q['level'] === 'campaign' && $q['time_increment'] === '1'
            && json_decode($q['time_range'], true) === ['since' => '2026-09-20', 'until' => '2026-09-21'];
    });
    expect(fn () => metaReader()->insights('account', CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21')))
        ->toThrow(InvalidArgumentException::class);
});

it('sends only GETs, the token only as a bearer header, and never stores or logs it', function () {
    metaAdsFake([META_ADS_ACT => ['account-bdt'], META_ADS_ACT.'/campaigns' => ['campaigns-page1'], META_ADS_ACT.'/campaigns?after=CURSOR-PAGE-2' => ['campaigns-page2'],
        META_ADS_ACT.'/adsets' => ['adsets'], META_ADS_ACT.'/ads' => ['ads'], META_ADS_ACT.'/insights' => ['insights-ad']]);
    $reader = metaReader();
    $reader->campaigns();
    $reader->adSets();
    $reader->ads();
    $reader->insights('ad', CarbonImmutable::parse('2026-09-21'), CarbonImmutable::parse('2026-09-21'));

    Http::assertSentCount(6);
    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'SECRET-SYSTEM-USER-TOKEN') || str_contains($r->url(), 'access_token'));
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer SECRET-SYSTEM-USER-TOKEN'));
    expect(ConnectorCall::count())->toBe(6)
        ->and(ConnectorCall::pluck('connector')->unique()->all())->toBe(['meta_ads:bor-de-guna'])
        ->and(json_encode(ConnectorCall::all()->toArray()))->not->toContain('SECRET-SYSTEM-USER-TOKEN');
});

it('has no HTTP verb but GET anywhere in the adapter\'s source', function () {
    $source = (string) file_get_contents(app_path('Ads/Meta/MetaAdsReader.php'));

    expect(preg_match_all('/->(post|put|patch|delete|send)\s*\(/i', $source))->toBe(0)
        ->and(preg_match_all('/->get\s*\(/', $source))->toBeGreaterThan(0);
});

it('classifies an expired token as the fault of the credential, with the Meta trace and never the token', function () {
    metaAdsFake([META_ADS_ACT => [metaAdsFixture('error-token-expired'), 400]]);
    try {
        metaReader()->account();
        $this->fail('expected an exception');
    } catch (Unsupported $e) {
        expect($e->getCode())->toBe(401)->and(OutcomeClass::fromThrowable($e))->toBe(OutcomeClass::ProviderUnauthorized)
            ->and($e->getMessage())->toContain('code 190/463')->toContain('AbCdEf123')->not->toContain('SECRET');
    }
});

it('classifies each Meta error: throttles and outages unavailable (never failed), permissions unsupported, bad input invalid', function (array $body, int $status, string $class) {
    metaAdsFake([META_ADS_ACT => [$body, $status]]);

    expect(fn () => metaReader()->account())->toThrow($class);
})->with([
    'throttled ad account (80004)' => [fn () => metaAdsFixture('error-throttled'), 400, Unavailable::class],
    'user request limit (17)' => [['error' => ['message' => 'User request limit reached', 'code' => 17, 'error_subcode' => 2446079]], 400, Unavailable::class],
    'too many calls from this ad-account (613)' => [['error' => ['message' => 'too many calls', 'code' => 613, 'error_subcode' => 1487742]], 400, Unavailable::class],
    'temporary outage (2)' => [['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503, Unavailable::class],
    'no permission (200)' => [fn () => metaAdsFixture('error-permission'), 403, Unsupported::class],
    'permission denied (10)' => [['error' => ['message' => 'Permission denied', 'code' => 10]], 403, Unsupported::class],
    'invalid parameter (100)' => [fn () => metaAdsFixture('error-invalid-param'), 400, ValidationFailed::class],
    'server error without a body' => [[], 500, Unavailable::class],
]);

it('stops short of Meta\'s limit: once usage reaches the back-off, the next call is not sent', function () {
    metaAdsFake([
        META_ADS_ACT => ['account-bdt', 200, ['X-Ad-Account-Usage' => json_encode(['acc_id_util_pct' => 85, 'reset_time_duration' => 120])]],
        META_ADS_ACT.'/campaigns' => ['campaigns-page2'],
    ]);
    $reader = metaReader(backoff: 80);
    $reader->account();

    expect($reader->usage()['x-ad-account-usage']['acc_id_util_pct'])->toBe(85);
    expect(fn () => $reader->campaigns())->toThrow(Unavailable::class, 'not sent');
    Http::assertSentCount(1);
});

it('refuses a spend row without its currency rather than storing a figure nobody can read', function () {
    metaAdsFake([META_ADS_ACT.'/insights' => [['data' => [['campaign_id' => '1', 'spend' => '10', 'date_start' => '2026-09-21']]]]]);

    expect(fn () => metaReader()->insights('campaign', CarbonImmutable::parse('2026-09-21'), CarbonImmutable::parse('2026-09-21')))
        ->toThrow(Refused::class, 'account_currency');
});

it('refuses a spend with more precision than the currency has, rather than rounding money silently', function () {
    metaAdsFake([META_ADS_ACT.'/insights' => [['data' => [['account_currency' => 'BDT', 'campaign_id' => '1', 'spend' => '10.123', 'date_start' => '2026-09-21']]]]]);

    expect(fn () => metaReader()->insights('campaign', CarbonImmutable::parse('2026-09-21'), CarbonImmutable::parse('2026-09-21')))
        ->toThrow(InvalidArgumentException::class, 'more decimal places');
});

it('keeps money exact: basic units, sums and an unknown currency refused', function () {
    expect(AdMoney::fromMinor('5', 'BDT'))->toBe('0.05')
        ->and(AdMoney::fromMinor('-150', 'USD'))->toBe('-1.50')
        ->and(AdMoney::fromMinor(null, 'BDT'))->toBeNull()
        ->and(AdMoney::major('87.1', 'BDT'))->toBe('87.10')
        ->and(AdMoney::sum(['0.10', '0.20', '412.35', null], 'BDT'))->toBe('412.65')
        ->and(AdMoney::toMinor('12.30', 'BDT'))->toBe(1230);
    expect(fn () => AdMoney::fromMinor('100', 'EUR'))->toThrow(InvalidArgumentException::class, 'no recorded offset');
});

it('refuses an account reference that is not one', function () {
    expect(fn () => metaReader('https://evil.example/act_1'))->toThrow(InvalidArgumentException::class);
});
