<?php

namespace App\Content;

use App\Context\Provenance;
use App\Models\Brand;
use App\Models\ContentBrief;
use App\Models\ContentDraft;
use App\Models\ContentTestimonial;
use App\Models\MediaAsset;
use App\Models\ResearchArtifact;
use App\Models\ResearchTask;
use App\Models\User;
use App\Research\ResearchRefused;
use App\Support\Authorization\EligibleReviewers;
use App\Support\Features\Features;
use Illuminate\Support\Facades\DB;

/**
 * Makes a checked draft (docs/85 C.8, C.9, C.10, C.11). The print shop, not the newsroom.
 *
 * A draft is made from four inputs and nothing else: the brief, the words (a model's, labelled
 * generated, or a person's), product facts read from a system of record at draft time, and, if any,
 * research claims it cites. It is checked (`ContentChecks`) and stored with the result: `checked` or
 * `failed_checks`. A failed draft keeps its words exactly as they were; nothing here rewrites them.
 *
 * Review (ChatGPT ruling 15, 31 Oct 2026): approving a draft authorises its WORDS only, never publication
 * (a separate governed action with its own approval of the exact post). The person who wrote a draft may
 * approve it only while they are the sole eligible reviewer for that business, with a reason; the draft
 * records `self_decided`, the reason and when. Once a second eligible reviewer exists, review is
 * independent. A draft quoting an EDITED testimonial (ruling 13) is approved only with the reviewer
 * confirming the exact final asset (its content digest).
 *
 * ⛔ **Off.** Refuses while the flag `content.drafting` has no enabled row. No tool that calls this is
 * registered, and no agent holds one. The Executive never will: publication and content are outside
 * its role (config `hq.roles`), and no publishing capability exists for it.
 */
final class ContentDraftService
{
    public const FLAG = 'content.drafting';

    /** The permission whose holders are the eligible reviewers of a content draft. */
    public const REVIEW_PERMISSION = 'hq.content.review';

    public function __construct(
        private readonly Features $features,
        private readonly ContentChecks $checks,
        private readonly EligibleReviewers $reviewers,
    ) {}

    /**
     * @param  array{brief_id: int, text: string, text_provenance: string, platform: string, variant?: string,
     *               product_lines: list<string>, product_facts?: list<array<string, mixed>>, research_artifact_id?: ?int,
     *               claim_refs?: list<string>, assets?: list<array{media_asset_id: int, role: string}>, parent_draft_id?: ?int,
     *               testimonial_id?: ?int, testimonial_edited?: bool}  $input
     */
    public function create(array $input, ?User $by = null): ContentDraft
    {
        if (! $this->features->enabled(self::FLAG)) {
            throw new ResearchRefused('Content drafting is off (flag content.drafting). No draft was made.');
        }

        $brief = ContentBrief::query()->find((int) ($input['brief_id'] ?? 0));
        if ($brief === null || $brief->approved_at === null) {
            throw new ResearchRefused('A draft is made from an approved brief, and this one is missing or not approved.');
        }
        $brand = Brand::query()->find($brief->brand_id);

        $text = (string) ($input['text'] ?? '');
        if (trim($text) === '' || mb_strlen($text) > 5000) {
            throw new ResearchRefused('A draft has between 1 and 5,000 characters of copy.');
        }

        $textProvenance = (string) ($input['text_provenance'] ?? '');
        if (! in_array($textProvenance, [Provenance::Generated->value, Provenance::Business->value], true)) {
            throw new ResearchRefused('Say whether the copy was written by a model (generated) or by a person (business).');
        }

        $platform = (string) ($input['platform'] ?? '');
        if (! in_array($platform, ['facebook_page', 'instagram'], true)) {
            throw new ResearchRefused('A draft is for a Facebook Page or an Instagram professional account.');
        }

        $facts = array_map(static fn (array $f): ProductFact => ProductFact::fromArray($f), (array) ($input['product_facts'] ?? []));

        [$artifact, $cited] = $this->citedClaims($input, (int) $brand->business_id, (int) $brand->getKey());
        $assets = $this->assets((array) ($input['assets'] ?? []), (int) $brand->business_id, $platform);

        // Ruling 13: a named testimonial must be this business's; whether it qualifies is a check, not a refusal.
        $testimonial = null;
        if (! empty($input['testimonial_id'])) {
            $testimonial = ContentTestimonial::query()->find((int) $input['testimonial_id']);
            if ($testimonial === null || (int) $testimonial->business_id !== (int) $brand->business_id) {
                throw new ResearchRefused('A draft quotes only a testimonial recorded for this business.');
            }
        }
        $quote = $testimonial === null ? null : [
            'testimonial' => $testimonial, 'text_provenance' => $textProvenance, 'platform' => $platform,
            'assets' => (array) ($input['assets'] ?? []), 'edited' => (bool) ($input['testimonial_edited'] ?? false),
        ];

        $productLines = array_values(array_filter(array_map('strval', (array) ($input['product_lines'] ?? []))));
        $result = $this->checks->run($text, $brief, $productLines, $facts, $cited, $brand->business?->slug, $brand->slug, $quote);

        $variant = (string) ($input['variant'] ?? 'default');
        $parentId = isset($input['parent_draft_id']) ? (int) $input['parent_draft_id'] : null;

        return DB::transaction(function () use ($brief, $brand, $artifact, $parentId, $platform, $variant, $text, $textProvenance, $assets, $facts, $cited, $result, $by, $testimonial): ContentDraft {
            if ($parentId !== null) {
                $parent = ContentDraft::query()->whereKey($parentId)->lockForUpdate()->first();
                if ($parent === null || $parent->brand_id !== $brand->getKey()) {
                    throw new ResearchRefused('An edit belongs to a draft of the same brand.');
                }
                $parent->forceFill(['status' => 'superseded'])->save();
            }

            return ContentDraft::query()->create([
                'business_id' => $brand->business_id,
                'brand_id' => $brand->getKey(),
                'brief_id' => $brief->getKey(),
                'research_artifact_id' => $artifact?->getKey(),
                'parent_draft_id' => $parentId,
                'platform' => $platform,
                'variant' => $variant,
                'text' => $text,
                'text_provenance' => $textProvenance,
                'assets' => $assets,
                'product_facts' => array_map(static fn (ProductFact $f): array => $f->toArray(), $facts),
                'claim_refs' => array_values(array_map(static fn (array $c): string => (string) $c['id'], $cited)),
                'checks' => $result,
                'status' => $result['passed'] ? 'checked' : 'failed_checks',
                'content_digest' => ContentDigest::ofDraftParts($text, $assets, $platform, $variant, (int) $brief->getKey(), (int) $brand->business_id, (int) $brand->getKey()),
                'created_by' => $by?->getKey(),
                'testimonial_id' => $testimonial?->getKey(),
            ]);
        });
    }

    /**
     * A person's review. Only a checked draft can be approved; the words never change here. Approval is of the
     * words only: it never publishes and never authorises a publication.
     *
     * @param  ?string  $selfReason  required when the writer approves their own draft as the sole eligible reviewer
     * @param  ?string  $confirmedDigest  required when the draft quotes an edited testimonial: the exact final asset
     */
    public function review(ContentDraft $draft, string $verdict, User $by, ?string $selfReason = null, ?string $confirmedDigest = null): ContentDraft
    {
        if (! in_array($verdict, ['approved', 'rejected'], true)) {
            throw new ResearchRefused("[{$verdict}] is not a review verdict.");
        }
        if ($verdict === 'approved' && $draft->status !== 'checked') {
            throw new ResearchRefused("A draft that is {$draft->status} cannot be approved. Edit it into a new draft, which is checked again.");
        }

        $self = $draft->created_by !== null && (int) $draft->created_by === (int) $by->getKey();
        $selfReason = trim((string) $selfReason);
        if ($verdict === 'approved' && $self) {
            // Ruling 15: a sole eligible reviewer may approve their own draft, with a reason, recorded.
            if (! $this->reviewers->actorIsSole($by, self::REVIEW_PERMISSION, (int) $draft->business_id)) {
                throw new ResearchRefused('Another eligible reviewer exists for this business, so this draft needs an independent review. You wrote it, so you cannot approve it.');
            }
            if ($selfReason === '') {
                throw new ResearchRefused('You wrote this draft and are the only eligible reviewer. Say why you approve it yourself; it is recorded as self-decided.');
            }
        }
        if ($verdict === 'approved' && ($draft->checks['claims_policy']['testimonial']['edited'] ?? false) === true
            && ($confirmedDigest === null || ! hash_equals((string) $draft->content_digest, $confirmedDigest))) {
            throw new ResearchRefused('This draft quotes an edited testimonial. Approve it by confirming the exact final post (its digest).');
        }

        $selfDecided = $verdict === 'approved' && $self;
        $draft->forceFill([
            'status' => $verdict, 'reviewed_by' => $by->getKey(), 'reviewed_at' => now(),
            'self_decided' => $selfDecided,
            'self_decided_reason' => $selfDecided ? mb_substr($selfReason, 0, 1000) : null,
            'self_decided_at' => $selfDecided ? now() : null,
        ])->save();

        return $draft;
    }

    /** @return array{0: ?ResearchArtifact, 1: list<array<string, mixed>>} */
    private function citedClaims(array $input, int $businessId, int $brandId): array
    {
        $refs = array_values(array_filter(array_map('strval', (array) ($input['claim_refs'] ?? []))));
        $artifactId = $input['research_artifact_id'] ?? null;
        if ($artifactId === null) {
            if ($refs !== []) {
                throw new ResearchRefused('A draft cites research claims only from a named artifact.');
            }

            return [null, []];
        }

        $artifact = ResearchArtifact::query()->find((int) $artifactId);
        $task = $artifact !== null ? ResearchTask::query()->find($artifact->task_id) : null;
        if ($artifact === null || $task === null || $task->business_id !== $businessId || ($task->brand_id !== null && $task->brand_id !== $brandId)) {
            throw new ResearchRefused('The research artifact is not one made for this business and brand. An artifact is shared only by a person.');
        }

        $cited = [];
        foreach ($refs as $ref) {
            $claim = $artifact->claim($ref);
            if ($claim === null) {
                throw new ResearchRefused("Claim [{$ref}] is not in the artifact.");
            }
            if (! in_array($claim['kind'] ?? '', ['fact', 'metric'], true)) {
                throw new ResearchRefused("Claim [{$ref}] is an {$claim['kind']}, and a draft states only facts and metrics as such.");
            }
            $cited[] = $claim;
        }

        return [$artifact, $cited];
    }

    /** @return list<array{media_asset_id: int, checksum_sha256: string, role: string, provenance: string}> */
    private function assets(array $assets, int $businessId, string $platform): array
    {
        $out = [];
        foreach ($assets as $a) {
            $asset = MediaAsset::query()->find((int) ($a['media_asset_id'] ?? 0));
            $role = (string) ($a['role'] ?? '');
            if ($asset === null || ($asset->business_id !== null && $asset->business_id !== $businessId)) {
                throw new ResearchRefused('A draft links only this business\'s media assets.');
            }
            // docs/17, docs/85 C.8: a product photo is the real photo, never a generated one.
            if ($role === 'product_photo' && $asset->provenance !== Provenance::Business) {
                throw new ResearchRefused('A product photo is the real photo from the asset library, never a generated or third-party image.');
            }
            if (! $asset->permitsUse('publish')) {
                throw new ResearchRefused("Asset {$asset->getKey()} has no recorded right to be published.");
            }
            // Instagram accepts JPEG only (Meta's content publishing guide, read 22 September 2026).
            if ($platform === 'instagram' && $asset->kind === 'image' && $asset->mime !== 'image/jpeg') {
                throw new ResearchRefused('Instagram publishes JPEG images only; render this card as JPEG.');
            }
            $out[] = ['media_asset_id' => (int) $asset->getKey(), 'checksum_sha256' => (string) $asset->checksum_sha256, 'role' => $role, 'provenance' => $asset->provenance->value];
        }

        return $out;
    }
}
