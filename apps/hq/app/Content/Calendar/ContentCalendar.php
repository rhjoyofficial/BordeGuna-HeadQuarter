<?php

namespace App\Content\Calendar;

use App\Ai\Pause\AiPause;
use App\Content\ClaimsPolicy;
use App\Content\ClaimsRulebook;
use App\Content\ContentDraftService;
use App\Context\Provenance;
use App\Models\Brand;
use App\Models\ContentBrief;
use App\Models\ContentCalendarEntry;
use App\Models\ContentDraft;
use App\Models\ContentTestimonial;
use App\Models\User;
use App\Playbooks\PlaybookGuidance;
use App\Playbooks\PlaybookKind;
use App\Research\ResearchRefused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The content calendar per brand (docs/89 §3.7, docs/145-a). A person plans a post for a date; a person
 * (or, once connected and switched on, a caption drafter) writes the words; the words become a checked
 * draft through `ContentDraftService`; a person reviews it. Publishing is a separate R5 gateway act with
 * its own approval of the exact post (`PublicationService`), and is not started from here.
 *
 * ⛔ Nothing here reaches a platform, a customer or a model unless every gate is open: drafting needs the
 * flag `content.drafting`; AI drafting also needs `content.ai_drafting`, AI not paused, and a bound
 * `CaptionDrafter` (none is bound). Every act is a named person's, audited, and business-scoped.
 */
final class ContentCalendar
{
    public const AI_FLAG = 'content.ai_drafting';

    public const PLATFORMS = ['facebook_page', 'instagram'];

    public const OCCASIONS = ['everyday', 'campaign', 'ramadan', 'eid_ul_fitr', 'eid_ul_adha', 'pohela_boishakh', 'hilsa_season', 'winter_pitha', 'mango_season', 'national_day'];

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
        private readonly Features $features,
        private readonly ContentDraftService $drafts,
        private readonly PlaybookGuidance $guidance,
        private readonly ClaimsRulebook $rulebook,
        private readonly AiPause $pause,
    ) {}

    /** @return list<PlaybookKind> The playbook kinds a caption is written from, in the loader's order. */
    public static function playbookKinds(): array
    {
        return [PlaybookKind::ProductClaims, PlaybookKind::ProductInformation, PlaybookKind::CommunicationStandards, PlaybookKind::CampaignInformation];
    }

    /** @param  array{planned_for: string, platform: string, product_lines: list<string>, idea: string, occasion?: ?string}  $data */
    public function plan(User $by, Brand $brand, array $data): ContentCalendarEntry
    {
        $this->mayAct($by, (int) $brand->business_id);

        $platform = (string) ($data['platform'] ?? '');
        if (! in_array($platform, self::PLATFORMS, true)) {
            throw new ResearchRefused('A planned post is for a Facebook Page or an Instagram account.');
        }
        $lines = self::productLines((array) ($data['product_lines'] ?? []));
        $idea = trim((string) ($data['idea'] ?? ''));
        if ($idea === '' || mb_strlen($idea) > 1000) {
            throw new ResearchRefused('Say what the post is about, in at most 1,000 characters.');
        }
        $occasion = isset($data['occasion']) && $data['occasion'] !== '' ? (string) $data['occasion'] : null;
        if ($occasion !== null && ! in_array($occasion, self::OCCASIONS, true)) {
            throw new ResearchRefused("[{$occasion}] is not an occasion on the calendar.");
        }
        try {
            $date = CarbonImmutable::parse((string) ($data['planned_for'] ?? ''))->toDateString();
        } catch (Throwable) {
            throw new ResearchRefused('The planned date is not a date.');
        }

        $entry = ContentCalendarEntry::query()->create([
            'business_id' => $brand->business_id,
            'brand_id' => $brand->getKey(),
            'planned_for' => $date,
            'occasion' => $occasion,
            'platform' => $platform,
            'product_lines' => $lines,
            'idea' => $idea,
            'status' => 'planned',
            'created_by' => $by->getKey(),
        ]);
        $this->auditor->record('content.calendar.planned', $entry,
            after: ['uuid' => $entry->uuid, 'business_id' => $entry->business_id, 'brand_id' => $entry->brand_id, 'planned_for' => $date, 'platform' => $platform, 'product_lines' => $lines, 'occasion' => $occasion],
            actorUserId: $by->getKey());

        return $entry;
    }

    public function skip(User $by, ContentCalendarEntry $entry, string $reason): ContentCalendarEntry
    {
        $this->mayAct($by, (int) $entry->business_id);
        if (! in_array($entry->status, ['planned', 'drafted'], true)) {
            throw new ResearchRefused("An entry that is {$entry->status} cannot be skipped.");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ResearchRefused('Say why the post is skipped.');
        }
        $before = $entry->status;
        $entry->forceFill(['status' => 'skipped', 'skip_reason' => mb_substr($reason, 0, 1000)])->save();
        $this->auditor->record('content.calendar.skipped', $entry, before: ['status' => $before], after: ['status' => 'skipped'], reason: $reason, actorUserId: $by->getKey());

        return $entry;
    }

    /**
     * A person's own words become a checked draft for the entry. A person may quote a recorded testimonial
     * (ChatGPT ruling 13): it is judged with the draft, and only a person's words can carry one.
     */
    public function draftByPerson(User $by, ContentCalendarEntry $entry, string $text, ?ContentTestimonial $testimonial = null, bool $testimonialEdited = false): ContentDraft
    {
        return $this->draft($by, $entry, $text, Provenance::Business->value, null, $testimonial, $testimonialEdited);
    }

    /** A connected caption drafter writes the words from the playbooks; they are labelled generated and checked the same way. */
    public function draftByAi(User $by, ContentCalendarEntry $entry): ContentDraft
    {
        $this->mayAct($by, (int) $entry->business_id);
        if ($this->pause->isPaused()) {
            throw new ResearchRefused('Pause All AI is on (or its state cannot be read), so no caption is drafted by AI. Write it yourself, or ask for AI to be turned back on.');
        }
        if (! $this->features->enabled(self::AI_FLAG, (int) $entry->business_id)) {
            throw new ResearchRefused('AI caption drafting is off (flag content.ai_drafting). Write the caption yourself.');
        }
        if (! app()->bound(CaptionDrafter::class)) {
            throw new ResearchRefused('No caption drafter is connected. AI drafting stays off until one is; write the caption yourself.');
        }

        $drafter = app(CaptionDrafter::class);
        $text = $drafter->draft($this->brief($entry));
        if ($text === null || trim($text) === '') {
            throw new ResearchRefused('The caption drafter wrote nothing. Write the caption yourself.');
        }

        return $this->draft($by, $entry, $text, Provenance::Generated->value, $drafter->name());
    }

    /**
     * A person's review of the entry's draft. Only a checked draft can be approved; the words never change.
     * Ruling 15 (31 Oct 2026): the writer approves their own draft only as the sole eligible reviewer, with a
     * reason, recorded as self-decided; approval never authorises publication.
     */
    public function review(User $by, ContentCalendarEntry $entry, string $verdict, ?string $note = null, ?string $selfReason = null, ?string $confirmedDigest = null): ContentCalendarEntry
    {
        $this->mayAct($by, (int) $entry->business_id);
        $draft = $entry->draft;
        if ($draft === null || $entry->status !== 'drafted') {
            throw new ResearchRefused('Only an entry with a draft waiting for review can be reviewed.');
        }

        return DB::transaction(function () use ($by, $entry, $draft, $verdict, $note, $selfReason, $confirmedDigest): ContentCalendarEntry {
            $draft = $this->drafts->review($draft, $verdict, $by, $selfReason, $confirmedDigest);
            $entry->forceFill(['status' => $verdict === 'approved' ? 'approved' : 'planned', 'draft_id' => $verdict === 'approved' ? $draft->getKey() : null])->save();
            $this->auditor->record('content.calendar.reviewed', $entry,
                after: ['verdict' => $verdict, 'draft' => $draft->uuid, 'self_reviewed' => (int) $draft->created_by === (int) $by->getKey(),
                    'self_decided' => (bool) $draft->self_decided, 'self_decided_reason' => $draft->self_decided_reason,
                    'self_decided_at' => $draft->self_decided_at?->toIso8601String(), 'publication_authorised' => false],
                reason: $note, actorUserId: $by->getKey());

            return $entry;
        });
    }

    /** What a drafter would be given for this entry, also shown to the person who writes the caption. */
    public function brief(ContentCalendarEntry $entry): CaptionBrief
    {
        $brand = $entry->brand;
        $brief = $this->latestBrief((int) $entry->brand_id);

        $playbooks = $this->guidance->forBrand((int) $entry->business_id, (int) $entry->brand_id, self::playbookKinds());

        $forbidden = [];
        if ($brief !== null) {
            $policy = $this->rulebook->policyFor($brief, (int) $entry->business_id);
            foreach (array_merge(['*'], (array) $entry->product_lines, [ClaimsPolicy::FLOOR_LINE]) as $line) {
                foreach ($policy->lines[$line]['forbidden'] ?? [] as $rule) {
                    $forbidden = [...$forbidden, ...array_map('strval', (array) ($rule['phrases'] ?? []))];
                }
            }
            $forbidden = [...$forbidden, ...$policy->bannedWords];
        }

        return new CaptionBrief(
            brand: (string) $brand?->name,
            platform: (string) $entry->platform,
            idea: (string) $entry->idea,
            occasion: $entry->occasion,
            productLines: array_values((array) $entry->product_lines),
            persona: $brief?->persona,
            playbooks: $playbooks,
            forbiddenPhrases: array_values(array_unique($forbidden)),
        );
    }

    public function latestBrief(int $brandId): ?ContentBrief
    {
        return ContentBrief::query()->where('brand_id', $brandId)->whereNotNull('approved_at')->orderByDesc('version')->first();
    }

    private function draft(User $by, ContentCalendarEntry $entry, string $text, string $provenance, ?string $drafter, ?ContentTestimonial $testimonial = null, bool $testimonialEdited = false): ContentDraft
    {
        $this->mayAct($by, (int) $entry->business_id);
        if (! in_array($entry->status, ['planned', 'drafted'], true)) {
            throw new ResearchRefused("An entry that is {$entry->status} takes no new draft.");
        }
        $brief = $this->latestBrief((int) $entry->brand_id);
        if ($brief === null) {
            throw new ResearchRefused('This brand has no approved content brief, so no draft can be checked. A brief is the owner\'s to write.');
        }

        return DB::transaction(function () use ($by, $entry, $text, $provenance, $drafter, $brief, $testimonial, $testimonialEdited): ContentDraft {
            $parent = $entry->status === 'drafted' && $entry->draft_id !== null ? (int) $entry->draft_id : null;
            $draft = $this->drafts->create([
                'brief_id' => $brief->getKey(),
                'text' => $text,
                'text_provenance' => $provenance,
                'platform' => (string) $entry->platform,
                'product_lines' => array_values((array) $entry->product_lines),
                'product_facts' => [],
                'parent_draft_id' => $parent,
                'testimonial_id' => $testimonial?->getKey(),
                'testimonial_edited' => $testimonialEdited,
            ], $by);
            $entry->forceFill(['status' => 'drafted', 'draft_id' => $draft->getKey()])->save();
            $this->auditor->record('content.calendar.drafted', $entry,
                after: ['draft' => $draft->uuid, 'text_provenance' => $provenance, 'drafter' => $drafter, 'checks_passed' => $draft->status === 'checked', 'testimonial' => $testimonial?->uuid],
                actorUserId: $by->getKey());

            return $draft;
        });
    }

    private function mayAct(User $by, int $businessId): void
    {
        if (! $this->scope->allows($by, $businessId)) {
            throw new ResearchRefused('That business is outside what you may act on.');
        }
    }

    /** @return list<string> */
    public static function productLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if (preg_match('/^[a-z0-9_]{1,64}$/', $line) !== 1) {
                throw new ResearchRefused("[{$line}] is not a product line key (lower-case letters, digits and _).");
            }
            $out[] = $line;
        }
        $out = array_values(array_unique($out));
        if ($out === []) {
            throw new ResearchRefused('Name at least one product line, so the owner\'s claims rules can be applied.');
        }

        return $out;
    }
}
