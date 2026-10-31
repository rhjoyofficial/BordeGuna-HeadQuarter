<?php

namespace App\Ads\Draft;

use App\Ads\Draft\Exceptions\AdsDraftRefused;
use App\Ads\Draft\Exceptions\AdsDraftUnavailable;
use App\Ads\Dto\AdMoney;
use App\Gateway\Credentials\CredentialVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Meta Marketing API, creation only, always paused: `POST /{version}/act_{id}/campaigns`, `/adsets`, `/adcreatives`,
 * `/ads`, each with `status=PAUSED` (a creative has no status of its own). The status is a constant of this class;
 * there is no code path that sends any other, and a source test holds that no other status word appears here.
 *
 * ⛔ Reads nothing it does not need and changes nothing that exists: it creates four objects, then re-reads the
 * status of the campaign, ad set and ad and reports what Meta says, so the tool can refuse to call a draft paused
 * that is not. Version, host and timeout are configuration (`hq.ads.meta`), the same as the read side.
 * ⚠ Field names are Meta's published ones; PROVEN on the first live call (docs/144-meta-ads-drafts).
 * ⚠ The token is read only from the open vault scope. Credential kind `meta_ads_draft_token` (a system-user token with
 * `ads_management`, separate from the read-only `meta_ads_token`), account: the business slug.
 */
final class MetaAdsDraftPlatform implements AdsDraftPlatform
{
    public const PROVIDER = 'meta_ads_draft';

    public const CREDENTIAL = 'meta_ads_draft_token';

    /** The only status this class ever sends. */
    private const PAUSED = 'PAUSED';

    public function __construct(private readonly CredentialVault $vault) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function createPausedDraft(string $accountRef, string $adAccountId, AdsDraftSpec $spec): AdsDraftResult
    {
        if (preg_match('/^\d{5,20}$/', $adAccountId) !== 1) {
            throw new AdsDraftRefused('meta_ads_draft: the ad account id must be digits.');
        }
        $token = $this->vault->secret(self::CREDENTIAL, $accountRef);
        $base = rtrim((string) config('hq.ads.meta.base_url'), '/').'/'.config('hq.ads.meta.graph_version');
        $act = "{$base}/act_{$adAccountId}";
        $created = [];

        $campaign = $this->post($token, "{$act}/campaigns", [
            'name' => $spec->name, 'objective' => $spec->objective, 'status' => self::PAUSED, 'special_ad_categories' => [],
            'is_adset_budget_sharing_enabled' => false,
        ], $created);
        $created['campaign'] = $campaign;

        $adset = $this->post($token, "{$act}/adsets", [
            'name' => $spec->name.' - ad set', 'campaign_id' => $campaign, 'status' => self::PAUSED,
            'daily_budget' => AdMoney::toMinor($spec->dailyBudget, $spec->currency),
            'billing_event' => 'IMPRESSIONS', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'optimization_goal' => $spec->objective === 'OUTCOME_AWARENESS' ? 'REACH' : 'LINK_CLICKS',
            'targeting' => ['geo_locations' => ['countries' => $spec->countries], 'age_min' => (int) config('hq.ads.draft.age_min', 22)],
        ], $created);
        $created['adset'] = $adset;

        $creative = $this->post($token, "{$act}/adcreatives", [
            'name' => $spec->name.' - creative',
            'object_story_spec' => ['page_id' => $spec->pageId, 'link_data' => array_filter([
                'message' => $spec->primaryText, 'link' => $spec->linkUrl, 'name' => $spec->headline,
                'description' => $spec->description, 'image_hash' => $spec->imageHash,
                'call_to_action' => ['type' => 'LEARN_MORE', 'value' => ['link' => $spec->linkUrl]],
            ], static fn ($v) => $v !== null)],
        ], $created);
        $created['creative'] = $creative;

        $ad = $this->post($token, "{$act}/ads", [
            'name' => $spec->name.' - ad', 'adset_id' => $adset, 'creative' => ['creative_id' => $creative], 'status' => self::PAUSED,
        ], $created);
        $created['ad'] = $ad;

        $statuses = [];
        foreach (['campaign' => $campaign, 'adset' => $adset, 'ad' => $ad] as $kind => $id) {
            $statuses[$kind] = $this->status($token, $base, $id, $created);
        }

        return new AdsDraftResult(['campaign' => $campaign, 'adset' => $adset, 'creative' => $creative, 'ad' => $ad], $statuses);
    }

    /** @param  array<string, string>  $created */
    private function post(string $token, string $url, array $body, array $created): string
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout((int) config('hq.ads.meta.timeout_seconds', 20))->post($url, $body);
        } catch (ConnectionException) {
            throw new AdsDraftUnavailable('meta_ads_draft: Meta could not be reached.');
        } catch (Throwable $e) {
            throw new AdsDraftUnavailable('meta_ads_draft: '.$e::class);
        }
        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new AdsDraftUnavailable("meta_ads_draft: Meta answered {$status}.");
        }
        if ($response->failed()) {
            throw new AdsDraftRefused('meta_ads_draft: Meta refused ('.$status.', '.substr((string) ($response->json('error.message') ?? 'error'), 0, 140).').', $created);
        }
        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new AdsDraftRefused('meta_ads_draft: the answer has no id.', $created);
        }

        return $id;
    }

    /** @param  array<string, string>  $created */
    private function status(string $token, string $base, string $id, array $created): string
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(20)->get("{$base}/{$id}", ['fields' => 'status']);
        } catch (Throwable) {
            throw new AdsDraftUnavailable('meta_ads_draft: could not confirm the status of '.$id.'.');
        }
        if ($response->failed() || ! is_string($response->json('status'))) {
            throw new AdsDraftRefused('meta_ads_draft: could not confirm the status of '.$id.'.', $created);
        }

        return $response->json('status');
    }
}
