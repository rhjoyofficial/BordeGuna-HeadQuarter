<?php

namespace Tests\Support\Content;

use App\Content\Publishing\PostPublisher;
use App\Content\Publishing\PostReadBack;
use App\Content\Publishing\PublishOutcome;
use App\Content\Publishing\PublishRequest;
use App\Platform\Capabilities;
use App\Platform\Contracts\PublishingPlatform;
use Carbon\CarbonImmutable;

/** A scripted publisher for the publication workflow tests. Registered only inside a test's own registry. */
final class FakePublisher implements PostPublisher
{
    /** @var list<PublishRequest> */
    public array $published = [];

    /** @param  list<PostReadBack>  $recent */
    public function __construct(
        public PublishOutcome $outcome,
        public ?PostReadBack $readBack = null,
        public array $recent = [],
    ) {}

    public function platform(): string
    {
        return 'facebook_page';
    }

    public function credentialProvider(): string
    {
        return 'meta_page_token';
    }

    public function ceiling(): Capabilities
    {
        return Capabilities::of([PublishingPlatform::PUBLISH]);
    }

    public function requiresApproval(): array
    {
        return [PublishingPlatform::PUBLISH];
    }

    public function publish(PublishRequest $request): PublishOutcome
    {
        $this->published[] = $request;

        return $this->outcome;
    }

    /** When set, the read-back fails as a lost connection would. */
    public ?\Throwable $readBackFails = null;

    /** @var list<string> the post ids read back, in order */
    public array $readBackCalls = [];

    public function readBack(string $postId, string $accountRef): ?PostReadBack
    {
        $this->readBackCalls[] = $postId;
        if ($this->readBackFails !== null) {
            throw $this->readBackFails;
        }

        return $this->readBack;
    }

    public function recent(string $accountRef, CarbonImmutable $since): array
    {
        return $this->recent;
    }
}
