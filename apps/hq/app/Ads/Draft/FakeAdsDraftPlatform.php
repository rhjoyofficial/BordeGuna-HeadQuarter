<?php

namespace App\Ads\Draft;

use App\Ads\Draft\Exceptions\AdsDraftRefused;
use App\Ads\Draft\Exceptions\AdsDraftUnavailable;

/** A platform that exists only inside HQ: makes four paused objects with derived ids. `FAKE-DOWN` in the name is unavailable, `FAKE-REFUSE` refused. */
final class FakeAdsDraftPlatform implements AdsDraftPlatform
{
    public function key(): string
    {
        return 'fake_ads';
    }

    public function createPausedDraft(string $accountRef, string $adAccountId, AdsDraftSpec $spec): AdsDraftResult
    {
        if (str_contains($spec->name, 'FAKE-DOWN')) {
            throw new AdsDraftUnavailable('fake_ads: the fake platform is down.');
        }
        if (str_contains($spec->name, 'FAKE-REFUSE')) {
            throw new AdsDraftRefused('fake_ads: the fake platform refused this draft.');
        }
        $h = substr(hash('sha256', $adAccountId.'|'.$spec->name), 0, 10);

        return new AdsDraftResult(
            ['campaign' => "fake-c-{$h}", 'adset' => "fake-s-{$h}", 'creative' => "fake-cr-{$h}", 'ad' => "fake-a-{$h}"],
            ['campaign' => 'PAUSED', 'adset' => 'PAUSED', 'creative' => 'PAUSED', 'ad' => 'PAUSED'],
        );
    }
}
