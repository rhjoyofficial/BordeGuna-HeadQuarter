<?php

namespace App\Avatar;

use App\Platform\Capabilities;
use App\Platform\Contracts\MediaGenerator;

/**
 * The sandbox avatar provider (docs/145-b): no network, no likeness, no file. It records what it was asked
 * and answers with a job reference, so consent checks, revocation races and the approval path can be walked
 * end to end. Bound only by a test or a staging release; production binds nothing.
 */
final class FakeAvatarProvider implements AvatarProvider
{
    /** @var list<AvatarRequest> */
    public array $requests = [];

    /** @var (callable(AvatarRequest): void)|null runs inside generate(), e.g. a revocation landing mid-call */
    public $during = null;

    public function __construct(private readonly ?string $failWith = null) {}

    public function name(): string
    {
        return 'sandbox';
    }

    public function credentialProvider(): string
    {
        return 'sandbox';
    }

    public function platform(): string
    {
        return 'avatar_sandbox';
    }

    public function ceiling(): Capabilities
    {
        return Capabilities::of([MediaGenerator::AVATAR, MediaGenerator::VOICE, MediaGenerator::SYNTHETIC_IDENTITY]);
    }

    public function requiresApproval(): array
    {
        return [];
    }

    public function generate(AvatarRequest $request): AvatarResult
    {
        $this->requests[] = $request;
        if ($this->during !== null) {
            ($this->during)($request);
        }

        return $this->failWith !== null ? AvatarResult::failed($this->failWith) : AvatarResult::accepted('sandbox-job-'.substr($request->generationUuid, 0, 8));
    }
}
