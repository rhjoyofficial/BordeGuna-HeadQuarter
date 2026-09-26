<?php

namespace App\Content\Publishing;

use App\Platform\Contracts\PublishingPlatform;
use Carbon\CarbonImmutable;

/**
 * A publishing adapter (docs/85 C.13): publish one approved post, read it back, and list recent posts
 * so an uncertain publication can be reconciled before any retry (docs/85 C.20).
 *
 * ⛔ Implementations are registered NOWHERE (`PlatformRegistry` ships empty, a test holds it). Until a
 * gate registers one, `CapabilityResolver` finds no provider and publication refuses for everyone.
 */
interface PostPublisher extends PublishingPlatform
{
    /** The `integration_credentials.provider` the account's token is stored under. */
    public function credentialProvider(): string;

    public function publish(PublishRequest $request): PublishOutcome;

    public function readBack(string $postId, string $accountRef): ?PostReadBack;

    /** @return list<PostReadBack> */
    public function recent(string $accountRef, CarbonImmutable $since): array;
}
