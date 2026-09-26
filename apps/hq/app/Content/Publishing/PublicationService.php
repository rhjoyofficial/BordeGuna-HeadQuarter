<?php

namespace App\Content\Publishing;

use App\Content\ContentChecks;
use App\Content\ContentDigest;
use App\Content\ProductFact;
use App\Models\ContentBrief;
use App\Models\ContentDraft;
use App\Models\ContentPublication;
use App\Models\PlatformAccount;
use App\Models\ResearchArtifact;
use App\Platform\CapabilityResolver;
use App\Platform\Contracts\PublishingPlatform;
use App\Platform\PlatformRegistry;
use App\Research\ResearchRefused;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Publishes one approved draft to one account, exactly as approved (docs/85 C.12, C.14, C.19, C.20;
 * ADR-051 accepted: publication R5, one approval per exact artifact, read back and reconciled).
 *
 * In order, and every step refuses rather than repairs:
 *
 * 1. the flag `publishing.write` (off: no row);
 * 2. the draft was approved by a person, for this brand, for this platform;
 * 3. **the approval's digest equals the digest of the draft as stored, for this account**: a changed
 *    word, asset or account is different content, and an approval is never reused for it;
 * 4. **the checks run again against LIVE product facts**: a price that changed since approval stops the
 *    publication and goes back to the owner;
 * 5. a provider for the account's platform is resolved through `CapabilityResolver`. `PlatformRegistry`
 *    ships empty, so today this refuses for everyone;
 * 6. one publication per (draft, account, variant): a published key returns its record, a pending or uncertain
 *    one is reconciled before any retry;
 * 7. the post is read back, and only a read-back that shows the approved words is `published`.
 */
final class PublicationService
{
    public const FLAG = 'publishing.write';

    public const NO_PROVIDER = 'No publishing provider is registered for this platform; publication is not enabled (gate P6-G3).';

    public function __construct(
        private readonly Features $features,
        private readonly CapabilityResolver $capabilities,
        private readonly PlatformRegistry $registry,
        private readonly ContentChecks $checks,
    ) {}

    /** @param  list<ProductFact>  $liveFacts  read from the ERP or the shop at this moment */
    public function publish(ContentDraft $draft, PlatformAccount $account, string $approvedDigest, ?int $approvalId, array $liveFacts, ?string $imageUrl = null): ContentPublication
    {
        if (! $this->features->enabled(self::FLAG)) {
            throw new ResearchRefused('Publishing is off (flag publishing.write). Nothing was sent.');
        }
        if ($draft->status !== 'approved') {
            throw new ResearchRefused("The draft is {$draft->status}; only a draft a person approved is published.");
        }
        if ($account->brand_id !== $draft->brand_id || $account->platform !== $draft->platform || ! $account->is_active) {
            throw new ResearchRefused('The account is not an active account of this draft\'s brand on this draft\'s platform.');
        }
        if (! hash_equals(ContentDigest::ofDraft($draft), (string) $draft->content_digest)) {
            throw new ResearchRefused('The stored draft no longer matches its own digest; it is not published.');
        }
        if (! hash_equals(ContentDigest::forPublication($draft, (int) $account->getKey()), $approvedDigest)) {
            throw new ResearchRefused('The approval covers different content (words, assets or account). A new approval is needed for this exact post.');
        }

        $this->recheck($draft, $liveFacts);

        $resolution = $this->capabilities->resolve($account);
        $publisher = $this->registry->get((string) $account->platform);
        if (! $resolution->supports(PublishingPlatform::PUBLISH) || ! $publisher instanceof PostPublisher) {
            throw new ResearchRefused(self::NO_PROVIDER.' ('.($resolution->reasonFor(PublishingPlatform::PUBLISH) ?? 'no reason recorded').')');
        }

        $key = "content:{$draft->uuid}|account:{$account->getKey()}|variant:{$draft->variant}";
        $publication = ContentPublication::query()->where('logical_key', $key)->first();

        if ($publication !== null) {
            if ($publication->status === 'published') {
                return $publication;
            }
            // `pending` is a row written before the send whose outcome was never recorded (a crash or
            // an exception between the two): the post may exist, exactly as for `uncertain`.
            if (in_array($publication->status, ['uncertain', 'pending'], true)) {
                $publication = $this->reconcile($publication, $publisher, $account, $draft);
                if ($publication->status !== 'absent') {
                    return $publication;
                }
            }
            if (in_array($publication->status, ['mismatch', 'incident'], true)) {
                throw new ResearchRefused("The publication is in {$publication->status}; a person resolves it before anything is sent again.");
            }
        }

        $publication ??= new ContentPublication(['logical_key' => $key]);
        $publication->fill([
            'draft_id' => $draft->getKey(),
            'platform_account_id' => $account->getKey(),
            'variant' => $draft->variant,
            'content_digest' => $approvedDigest,
            'approval_id' => $approvalId,
            'status' => 'pending',
            'last_error' => null,
        ])->save();

        $outcome = $publisher->publish(new PublishRequest((string) $account->account_ref, (string) $draft->text, $key, $imageUrl));

        if ($outcome->status === 'failed') {
            $publication->forceFill(['status' => 'failed', 'last_error' => $outcome->error])->save();

            return $publication;
        }
        if ($outcome->status === 'uncertain') {
            $publication->forceFill(['status' => 'uncertain', 'last_error' => $outcome->error])->save();

            return $publication;
        }

        // The id is written BEFORE the read-back: a post the platform accepted exists whatever happens
        // next, and a read-back that fails must leave a row that names it, never one that looks unsent.
        $publication->forceFill(['platform_post_id' => $outcome->postId, 'status' => 'uncertain', 'last_error' => 'The platform accepted the post; it has not been read back yet.'])->save();

        try {
            $readBack = $publisher->readBack((string) $outcome->postId, (string) $account->account_ref);
        } catch (Throwable $e) {
            $readBack = null;
        }
        $publication->forceFill([
            'read_back' => $readBack?->toArray(),
            'read_back_at' => now(),
            'status' => match (true) {
                $readBack === null => 'uncertain',
                $readBack->matches((string) $draft->text) => 'published',
                default => 'mismatch',
            },
            'published_at' => $readBack !== null && $readBack->matches((string) $draft->text) ? now() : null,
            'last_error' => match (true) {
                $readBack === null => 'The platform accepted the post, but it could not be read back yet.',
                ! $readBack->matches((string) $draft->text) => 'The post on the platform does not show the approved words.',
                default => null,
            },
        ])->save();

        return $publication;
    }

    /**
     * Looks the post up before anything is sent again (docs/85 C.20): one match is adopted, none means
     * the post does not exist (`absent`, a retry may publish), two or more is an incident for a person.
     */
    public function reconcile(ContentPublication $publication, PostPublisher $publisher, PlatformAccount $account, ContentDraft $draft): ContentPublication
    {
        // A row that already names its post is read back by that id first: the platform accepted it,
        // so the only question left is whether it shows the approved words.
        if ($publication->platform_post_id !== null) {
            try {
                $known = $publisher->readBack((string) $publication->platform_post_id, (string) $account->account_ref);
            } catch (Throwable) {
                $known = null;
            }
            if ($known !== null) {
                $matches = $known->matches((string) $draft->text);
                $publication->forceFill([
                    'status' => $matches ? 'published' : 'mismatch', 'read_back' => $known->toArray(), 'read_back_at' => now(),
                    'published_at' => $matches ? now() : null,
                    'last_error' => $matches ? null : 'The post on the platform does not show the approved words.',
                ])->save();

                return $publication;
            }
        }

        $since = CarbonImmutable::parse($publication->created_at)->subMinutes(5);
        $matches = array_values(array_filter(
            $publisher->recent((string) $account->account_ref, $since),
            static fn (PostReadBack $post): bool => $post->matches((string) $draft->text),
        ));

        if (count($matches) === 1) {
            $publication->forceFill([
                'status' => 'published', 'platform_post_id' => $matches[0]->postId, 'read_back' => $matches[0]->toArray(),
                'read_back_at' => now(), 'published_at' => now(), 'last_error' => null,
            ])->save();
        } elseif ($matches === []) {
            $publication->forceFill(['status' => 'absent', 'last_error' => 'Reconciled: no post with the approved words exists.'])->save();
        } else {
            $publication->forceFill(['status' => 'incident', 'last_error' => count($matches).' posts carry the approved words; a person decides.'])->save();
        }

        return $publication;
    }

    /** @param  list<ProductFact>  $liveFacts */
    private function recheck(ContentDraft $draft, array $liveFacts): void
    {
        $brief = ContentBrief::query()->findOrFail($draft->brief_id);
        $cited = [];
        if ($draft->research_artifact_id !== null) {
            $artifact = ResearchArtifact::query()->findOrFail($draft->research_artifact_id);
            foreach ((array) $draft->claim_refs as $ref) {
                if (($claim = $artifact->claim((string) $ref)) !== null) {
                    $cited[] = $claim;
                }
            }
        }

        $lines = (array) ($draft->checks['claims_policy']['product_lines'] ?? []);
        $result = $this->checks->run((string) $draft->text, $brief, $lines, $liveFacts, $cited);
        if (! $result['passed']) {
            throw new ResearchRefused('The checks fail against live product facts (a price or a fact changed since approval, or the policy changed). The owner decides again.');
        }
    }
}
