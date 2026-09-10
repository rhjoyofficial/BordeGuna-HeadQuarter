<?php

use App\Models\Brand;
use App\Models\Business;
use App\Models\PlatformAccount;
use App\Platform\Capabilities;
use App\Platform\CapabilityRequest;
use App\Platform\CapabilityRouter;
use App\Platform\CapabilityRouting;
use App\Platform\Contracts\MessagingChannel;
use App\Platform\Contracts\PlatformProvider;
use App\Platform\Contracts\PublishingPlatform;
use App\Platform\PlatformRegistry;
use Database\Seeders\DomainSeeder;

/*
 | Capability -> implementation routing (ADR-029).
 |
 | The question these cases ask is never "can this account do X". It is "who can do X for this
 | brand, right now, and if nobody, why not" - because the moment something upstream picks the
 | account, that thing has made the vendor decision whether it meant to or not.
 */

beforeEach(fn () => $this->seed(DomainSeeder::class));

/** A provider whose ceiling is whatever the test says, registered under a made-up platform key. */
function provider(string $platform, array $ceiling, array $needsApproval = []): void
{
    app(PlatformRegistry::class)->register(new class($platform, $ceiling, $needsApproval) implements PlatformProvider
    {
        public function __construct(private string $p, private array $c, private array $a) {}

        public function platform(): string
        {
            return $this->p;
        }

        public function ceiling(): Capabilities
        {
            return Capabilities::of($this->c);
        }

        public function requiresApproval(): array
        {
            return $this->a;
        }
    });
}

function account(string $platform, string $ref, array $overrides = []): PlatformAccount
{
    $brand = Brand::orderBy('id')->firstOrFail();

    return PlatformAccount::create(array_merge([
        'brand_id' => $brand->id,
        'platform' => $platform,
        'account_ref' => $ref,
        'name' => $ref,
        'capabilities' => [PublishingPlatform::PUBLISH],
        'approval_status' => 'approved',
        'is_active' => true,
    ], $overrides));
}

/** The reason a NAMED account was rejected. Never by position: the seeder ships accounts too. */
function reasonFor(CapabilityRouting $routing, string $ref): string
{
    foreach ($routing->rejected as $entry) {
        if ($entry['account'] === $ref) {
            return $entry['reason'];
        }
    }

    return "[{$ref}] was not rejected at all";
}

function routeCap(string $capability, array $overrides = []): CapabilityRouting
{
    return app(CapabilityRouter::class)->route(new CapabilityRequest(
        capability: $capability,
        brandId: $overrides['brand_id'] ?? Brand::orderBy('id')->firstOrFail()->id,
        preferAccountRef: $overrides['prefer'] ?? null,
    ));
}

it('routes a capability to the account that can serve it, naming no vendor to get there', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH]);
    account('somewhere', 'page-1');

    $routing = routeCap(PublishingPlatform::PUBLISH);

    // The caller asked for `publish_post`. It did not ask for a platform, and it could not have.
    expect($routing->available)->toBeTrue();
    expect($routing->account->account_ref)->toBe('page-1');
    expect($routing->platform)->toBe('somewhere');
});

it('refuses when HQ has no provider implementation, rather than assuming the account works', function () {
    account('somewhere', 'page-1');   // a row exists; no provider is registered

    $routing = routeCap(PublishingPlatform::PUBLISH);

    expect($routing->available)->toBeFalse();
    // ⚠ By account, not by position: the domain seeder ships its own platform_accounts rows, so
    // rejected[0] is whichever of those sorted first and asserting on it would prove nothing.
    expect(reasonFor($routing, 'page-1'))->toContain('no provider implementation');
});

it('refuses when nobody has recorded what the account holds', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH]);
    // capabilities NULL: nobody has written down what this account was granted. That is not the
    // same statement as "it was granted nothing", and it must resolve the same way.
    account('somewhere', 'page-1', ['capabilities' => null]);

    expect(routeCap(PublishingPlatform::PUBLISH)->available)->toBeFalse();
});

it('explains a pending approval instead of merely failing', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH], [PublishingPlatform::PUBLISH]);
    account('somewhere', 'page-1', ['approval_status' => 'pending']);

    $routing = routeCap(PublishingPlatform::PUBLISH);

    // "Nobody can publish" is nearly useless. "The Page is connected but its publish permission is
    // still in review" is something a person can act on this afternoon.
    expect($routing->available)->toBeFalse();
    expect(strtolower(reasonFor($routing, 'page-1')))->toContain('approv');
});

it('skips a switched-off account and says so', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH]);
    account('somewhere', 'page-1', ['is_active' => false]);

    expect(routeCap(PublishingPlatform::PUBLISH)->available)->toBeFalse();
});

it('REFUSES to guess between two equally valid accounts', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH]);
    account('somewhere', 'page-1');
    account('somewhere', 'page-2');

    $routing = routeCap(PublishingPlatform::PUBLISH);

    // ⛔ Mirrors AmbiguousCredential. There is no rule - newest wins, lowest id wins - that is right
    // often enough to be safe, because the operator's intent is not in the rows. Posting as the
    // wrong Page reaches real people under the wrong identity.
    expect($routing->available)->toBeFalse();
    expect($routing->ambiguous)->toBeTrue();
    expect($routing->reason)->toContain('page-1')->toContain('page-2');
});

it('lets a caller break the tie by naming the account', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH]);
    account('somewhere', 'page-1');
    account('somewhere', 'page-2');

    $routing = routeCap(PublishingPlatform::PUBLISH, ['prefer' => 'page-2']);

    expect($routing->available)->toBeTrue();
    expect($routing->account->account_ref)->toBe('page-2');
});

it('will not let a preference reach an account that failed the checks', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH]);
    account('somewhere', 'good');
    account('somewhere', 'switched-off', ['is_active' => false]);

    // Preference breaks a tie between candidates. It is not a way into the pool.
    $routing = routeCap(PublishingPlatform::PUBLISH, ['prefer' => 'switched-off']);

    expect($routing->account->account_ref)->toBe('good');
});

it('does not route a capability the provider cannot do at all', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH]);
    account('somewhere', 'page-1', ['capabilities' => [MessagingChannel::SEND_SERVICE]]);

    // The account claims a messaging grant, but this provider's code cannot message. The ceiling is
    // the most the CODE can do, and an account cannot be granted past it.
    expect(routeCap(MessagingChannel::SEND_SERVICE)->available)->toBeFalse();
});

it('keeps routing separate from authorization', function () {
    // ⚠ Routing says which implementation COULD serve a capability. It never says who may invoke it:
    // that is the Tool Gateway, and there is exactly one of those decisions in this system.
    $source = file_get_contents(app_path('Platform/CapabilityRouter.php'));

    foreach (['ToolGateway', 'PolicyEngine', 'ActingIdentity', 'ConsentGate', 'AutonomyResolver', 'CredentialVault'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('is reproducible: the same rows always produce the same answer', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH]);
    account('somewhere', 'b-second');
    account('somewhere', 'a-first');

    // An ambiguity refused once must not resolve differently on the next call, which is what an
    // unordered query would allow.
    $first = routeCap(PublishingPlatform::PUBLISH);
    $second = routeCap(PublishingPlatform::PUBLISH);

    expect($first->reason)->toBe($second->reason);
});

it('narrows to a business when no brand is named, and never widens', function () {
    provider('somewhere', [PublishingPlatform::PUBLISH]);

    $ours = Brand::orderBy('id')->firstOrFail();
    account('somewhere', 'ours');

    $otherBusiness = Business::where('id', '!=', $ours->business_id)->orderBy('id')->firstOrFail();
    $theirBrand = Brand::where('business_id', $otherBusiness->id)->first()
        ?? Brand::create(['business_id' => $otherBusiness->id, 'code' => 'OTHER', 'name' => 'Other']);
    PlatformAccount::create([
        'brand_id' => $theirBrand->id, 'platform' => 'somewhere', 'account_ref' => 'theirs',
        'capabilities' => [PublishingPlatform::PUBLISH], 'approval_status' => 'approved', 'is_active' => true,
    ]);

    $routing = app(CapabilityRouter::class)->route(new CapabilityRequest(
        capability: PublishingPlatform::PUBLISH,
        businessId: $ours->business_id,
    ));

    expect($routing->available)->toBeTrue();
    expect($routing->account->account_ref)->toBe('ours');
});
