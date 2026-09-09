<?php

use App\Models\Brand;
use App\Models\PlatformAccount;
use App\Platform\Capabilities;
use App\Platform\CapabilityResolver;
use App\Platform\Contracts\MessagingChannel;
use App\Platform\PlatformRegistry;
use Database\Seeders\DomainSeeder;

/*
 | Phase 2.5 A: capabilities resolve per CONNECTED ACCOUNT, not per platform (ADR-022).
 |
 | Two Facebook Pages in one group hold different scopes, sit at different stages of App Review and
 | carry different quotas. The rule these tests hold: a capability that is not usable right now is
 | ABSENT from the effective set, so an agent cannot offer it, and the reason is kept separately so
 | the agent can explain rather than merely fail.
 */

/** A stand-in provider. No real provider is implemented in Phase 2.5, which is the point. */
function fakeMessagingProvider(string $platform = 'facebook_page'): MessagingChannel
{
    return new class($platform) implements MessagingChannel
    {
        public function __construct(private string $platform) {}

        public function platform(): string
        {
            return $this->platform;
        }

        public function ceiling(): Capabilities
        {
            return Capabilities::of([
                self::SEND_SERVICE, self::SEND_MARKETING, self::SEND_TEMPLATE, self::RECEIVE, self::PRESENCE,
            ]);
        }

        public function requiresApproval(): array
        {
            return [self::SEND_MARKETING, self::SEND_TEMPLATE];
        }
    };
}

function platformAccount(array $attributes = []): PlatformAccount
{
    return PlatformAccount::create([
        'brand_id' => Brand::firstOrFail()->id,
        'platform' => $attributes['platform'] ?? 'facebook_page',
        'account_ref' => $attributes['account_ref'] ?? 'page-1',
        'name' => 'Test Page',
        'capabilities' => $attributes['capabilities'] ?? null,
        'restrictions' => $attributes['restrictions'] ?? null,
        'approval_status' => $attributes['approval_status'] ?? 'unknown',
        'quotas' => $attributes['quotas'] ?? null,
        'is_active' => $attributes['is_active'] ?? true,
    ]);
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->registry = app(PlatformRegistry::class);
});

it('resolves nothing for a platform HQ has no code for', function () {
    $resolution = app(CapabilityResolver::class)->resolve(platformAccount(['platform' => 'tiktok']));

    // "We have no provider" and "this account may do anything" must never be the same answer.
    expect($resolution->granted())->toBe([])
        ->and($resolution->reasonFor('*'))->toContain('no provider is registered');
});

it('resolves nothing for an account whose grants were never recorded', function () {
    $this->registry->register(fakeMessagingProvider());

    $resolution = app(CapabilityResolver::class)->resolve(platformAccount(['capabilities' => null]));

    expect($resolution->granted())->toBe([])
        ->and($resolution->reasonFor(MessagingChannel::RECEIVE))->toContain('no recorded capability grants');
});

it('gives an account only what the account actually holds, inside the provider ceiling', function () {
    $this->registry->register(fakeMessagingProvider());

    $resolution = app(CapabilityResolver::class)->resolve(platformAccount([
        'capabilities' => [MessagingChannel::RECEIVE, MessagingChannel::SEND_SERVICE, 'fly_to_the_moon'],
        'approval_status' => 'approved',
    ]));

    expect($resolution->supports(MessagingChannel::RECEIVE))->toBeTrue()
        ->and($resolution->supports(MessagingChannel::SEND_SERVICE))->toBeTrue()
        // Claimed by the account, outside the provider's ceiling: the ceiling wins.
        ->and($resolution->supports('fly_to_the_moon'))->toBeFalse()
        ->and($resolution->supports(MessagingChannel::SEND_MARKETING))->toBeFalse();
});

it('withholds a capability while platform approval is pending, and says why', function () {
    $this->registry->register(fakeMessagingProvider());

    $resolution = app(CapabilityResolver::class)->resolve(platformAccount([
        'capabilities' => [MessagingChannel::SEND_SERVICE, MessagingChannel::SEND_MARKETING],
        'approval_status' => 'pending',
    ]));

    // For months at a time this is the real state of a Meta account, and an agent that promised a
    // marketing message here would be promising something the platform will refuse.
    expect($resolution->supports(MessagingChannel::SEND_SERVICE))->toBeTrue()
        ->and($resolution->supports(MessagingChannel::SEND_MARKETING))->toBeFalse()
        ->and($resolution->reasonFor(MessagingChannel::SEND_MARKETING))->toContain('platform approval is [pending]');
});

it('honours an account-level restriction over a grant', function () {
    $this->registry->register(fakeMessagingProvider());

    $resolution = app(CapabilityResolver::class)->resolve(platformAccount([
        'capabilities' => [MessagingChannel::SEND_SERVICE, MessagingChannel::RECEIVE],
        'restrictions' => [MessagingChannel::SEND_SERVICE],
        'approval_status' => 'approved',
    ]));

    expect($resolution->supports(MessagingChannel::RECEIVE))->toBeTrue()
        ->and($resolution->supports(MessagingChannel::SEND_SERVICE))->toBeFalse()
        ->and($resolution->reasonFor(MessagingChannel::SEND_SERVICE))->toBe('restricted on this account');
});

it('resolves nothing for a deactivated account', function () {
    $this->registry->register(fakeMessagingProvider());

    $resolution = app(CapabilityResolver::class)->resolve(platformAccount([
        'capabilities' => [MessagingChannel::RECEIVE],
        'approval_status' => 'approved',
        'is_active' => false,
    ]));

    expect($resolution->granted())->toBe([])
        ->and($resolution->reasonFor('*'))->toContain('is not active');
});

it('lets two accounts on one platform differ, which is the whole reason for the class', function () {
    $this->registry->register(fakeMessagingProvider());
    $resolver = app(CapabilityResolver::class);

    $approved = platformAccount([
        'account_ref' => 'page-approved',
        'capabilities' => [MessagingChannel::SEND_SERVICE, MessagingChannel::SEND_MARKETING],
        'approval_status' => 'approved',
    ]);
    $pending = platformAccount([
        'account_ref' => 'page-pending',
        'capabilities' => [MessagingChannel::SEND_SERVICE, MessagingChannel::SEND_MARKETING],
        'approval_status' => 'pending',
    ]);

    expect($resolver->resolve($approved)->supports(MessagingChannel::SEND_MARKETING))->toBeTrue()
        ->and($resolver->resolve($pending)->supports(MessagingChannel::SEND_MARKETING))->toBeFalse();
});

it('ships with an empty registry, because no provider is implemented yet', function () {
    // Phase 2.5 defines interfaces and builds the resolution rule. It connects nothing.
    expect(app(PlatformRegistry::class)->platforms())->toBe([]);
});

it('carries the quotas through without treating them as capabilities', function () {
    $this->registry->register(fakeMessagingProvider());

    $resolution = app(CapabilityResolver::class)->resolve(platformAccount([
        'capabilities' => [MessagingChannel::RECEIVE],
        'approval_status' => 'approved',
        'quotas' => ['messages_per_day' => 1000],
    ]));

    expect($resolution->quotas)->toBe(['messages_per_day' => 1000])
        ->and($resolution->granted())->toBe([MessagingChannel::RECEIVE]);
});
