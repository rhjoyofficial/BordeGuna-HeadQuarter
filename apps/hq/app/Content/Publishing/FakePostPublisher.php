<?php

namespace App\Content\Publishing;

use App\Platform\Capabilities;
use App\Platform\Contracts\PublishingPlatform;
use Carbon\CarbonImmutable;

/**
 * The sandbox publisher (docs/145-a): the `PostPublisher` (publisher connector) contract with no network
 * at all. It keeps what it was asked to publish in memory and reads back exactly those words, so the whole
 * approval, digest, live-fact re-check, read-back and reconcile path can be walked on a laptop or staging.
 *
 * ⛔ Registered NOWHERE. `PlatformRegistry` ships empty (PhaseSixInertTest). A test or a staging release
 * registers it on purpose; production never does, because it publishes nothing to anybody. The real
 * drivers (`FacebookPagePublisher`, `InstagramPublisher`) stay behind gate P6-G3 and Joy's Page tokens.
 */
final class FakePostPublisher implements PostPublisher
{
    /** @var array<string, PublishRequest> post id => what was asked */
    private array $posts = [];

    public function __construct(private readonly string $platformKey = 'facebook_page') {}

    public function platform(): string
    {
        return $this->platformKey;
    }

    public function credentialProvider(): string
    {
        return 'sandbox';
    }

    public function ceiling(): Capabilities
    {
        return Capabilities::of([PublishingPlatform::PUBLISH]);
    }

    public function requiresApproval(): array
    {
        return [];
    }

    public function publish(PublishRequest $request): PublishOutcome
    {
        $id = 'sandbox_'.substr(hash('sha256', $request->logicalKey), 0, 16);
        $this->posts[$id] = $request;

        return PublishOutcome::accepted($id);
    }

    public function readBack(string $postId, string $accountRef): ?PostReadBack
    {
        $request = $this->posts[$postId] ?? null;
        if ($request === null || $request->accountRef !== $accountRef) {
            return null;
        }

        return new PostReadBack($postId, $request->text, CarbonImmutable::now()->toIso8601String());
    }

    public function recent(string $accountRef, CarbonImmutable $since): array
    {
        $out = [];
        foreach ($this->posts as $id => $request) {
            if ($request->accountRef === $accountRef) {
                $out[] = new PostReadBack($id, $request->text, CarbonImmutable::now()->toIso8601String());
            }
        }

        return $out;
    }

    /** @return list<PublishRequest> */
    public function published(): array
    {
        return array_values($this->posts);
    }
}
