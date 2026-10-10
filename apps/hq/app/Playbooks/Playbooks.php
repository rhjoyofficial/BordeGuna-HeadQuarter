<?php

namespace App\Playbooks;

use App\Models\Brand;
use App\Models\Business;
use App\Models\PlaybookVersion;
use App\Models\PlaybookVersionEvent;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The one writer of playbook versions (docs/112 §5).
 *
 * draft -> submit -> in_review -> approve -> reviewed -> activate -> active -> retire -> retired
 *                              -> send back -> draft
 * draft | in_review | reviewed -> withdraw -> withdrawn
 *
 * Every act is the act of a named, enabled person holding the permission for it, with a status check,
 * an append-only event row (snapshot and digest) and an audit row, in one transaction. `activate()`
 * retires the previously active version of the same key in the same transaction, so there is never a
 * moment with two or with none unless a person chose none. The database refuses the same things again
 * underneath (`playbook_versions` triggers on pgsql).
 *
 * ⛔ Nothing here is read at run time. The runtime reader is `PlaybookLoader`, and it reads only rows
 * that are `active`. A draft, a reviewed version or a retired one reaches no model.
 *
 * ⛔ No model and no job calls this class. Every method takes the `User` whose decision it is, and a
 * null user is a refusal, not a default (ruling docs/103 §11: no self-modifying playbooks).
 */
final class Playbooks
{
    public const VIEW = 'hq.playbooks.view';

    public const DRAFT = 'hq.playbooks.draft';

    public const REVIEW = 'hq.playbooks.review';

    public const ACTIVATE = 'hq.playbooks.activate';

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * A new draft, as data and not in force. Version number = one past the newest version of the key.
     *
     * @param  array{title: string, summary?: ?string, body: string}  $content
     */
    public function draft(User $by, Business $business, ?Brand $brand, PlaybookKind $kind, array $content, ?string $reason = null): PlaybookVersion
    {
        $this->refuseUnless($this->draftRefusals($by, $business, $brand, $content), 'Not drafted');

        return DB::transaction(function () use ($by, $business, $brand, $kind, $content, $reason) {
            $title = PlaybookContent::normalise((string) $content['title']);
            $body = PlaybookContent::normalise((string) $content['body']);
            $summary = isset($content['summary']) && PlaybookContent::normalise((string) $content['summary']) !== '' ? PlaybookContent::normalise((string) $content['summary']) : null;

            $latest = PlaybookVersion::query()->where('business_id', $business->id)->where('kind', $kind->value)
                ->where(fn ($q) => $brand === null ? $q->whereNull('brand_id') : $q->where('brand_id', $brand->id))
                // The newest row is locked (an aggregate cannot be); the unique key index catches any race that slips past.
                ->orderByDesc('version')->lockForUpdate()->value('version');

            $version = PlaybookVersion::create([
                'uuid' => (string) Str::uuid(),
                'business_id' => $business->id,
                'brand_id' => $brand?->id,
                'kind' => $kind->value,
                'version' => ((int) $latest) + 1,
                'status' => PlaybookVersion::DRAFT,
                'title' => $title,
                'summary' => $summary,
                'body' => $body,
                'content_digest' => PlaybookContent::digest($kind->value, $title, $body),
                'drafted_by' => $by->getKey(),
                'drafted_at' => now(),
            ]);

            $this->event($version, 'drafted', $by, $reason);
            $this->auditor->record('playbook.drafted', $version, after: $this->snapshot($version), reason: $reason, actorUserId: $by->getKey());

            return $version;
        });
    }

    /**
     * The content of a draft changed. Only a draft; the digest is rewritten; an `edited` event records it.
     *
     * @param  array{title: string, summary?: ?string, body: string}  $content
     */
    public function edit(User $by, PlaybookVersion $version, array $content, ?string $reason = null): PlaybookVersion
    {
        return DB::transaction(function () use ($by, $version, $content, $reason) {
            $fresh = $this->locked($version);
            $refusals = $this->personRefusals($by, self::DRAFT);
            if ($fresh->status !== PlaybookVersion::DRAFT) {
                $refusals[] = "version {$fresh->version} is {$fresh->status}; only a draft can be edited. Write a new draft";
            }
            $refusals = [...$refusals, ...array_map(static fn (string $p) => 'content: '.$p, PlaybookContent::problems((string) ($content['title'] ?? ''), (string) ($content['body'] ?? '')))];
            $this->refuseUnless($refusals, 'Not edited');

            $before = $this->snapshot($fresh);
            $title = PlaybookContent::normalise((string) $content['title']);
            $body = PlaybookContent::normalise((string) $content['body']);
            $summary = isset($content['summary']) && PlaybookContent::normalise((string) $content['summary']) !== '' ? PlaybookContent::normalise((string) $content['summary']) : null;

            $fresh->forceFill([
                'title' => $title,
                'summary' => $summary,
                'body' => $body,
                'content_digest' => PlaybookContent::digest($fresh->kind->value, $title, $body),
            ])->save();
            $fresh->refresh();

            $this->event($fresh, 'edited', $by, $reason);
            $this->auditor->record('playbook.edited', $fresh, before: $before, after: $this->snapshot($fresh), reason: $reason, actorUserId: $by->getKey());

            return $fresh;
        });
    }

    /** draft -> in_review, by the drafter or anyone else holding the draft permission. */
    public function submit(User $by, PlaybookVersion $version, ?string $reason = null): PlaybookVersion
    {
        return $this->transition($by, $version, self::DRAFT, [PlaybookVersion::DRAFT], PlaybookVersion::IN_REVIEW, 'submitted',
            ['submitted_by' => $by->getKey(), 'submitted_at' => now()], $reason, requireReason: false);
    }

    /**
     * in_review -> reviewed (approved for activation) or -> draft (sent back), with a note. The reviewer
     * is recorded on the row either way.
     */
    public function review(User $by, PlaybookVersion $version, bool $approved, string $note): PlaybookVersion
    {
        return $this->transition($by, $version, self::REVIEW, [PlaybookVersion::IN_REVIEW],
            $approved ? PlaybookVersion::REVIEWED : PlaybookVersion::DRAFT, $approved ? 'approved' : 'sent_back',
            ['reviewed_by' => $by->getKey(), 'reviewed_at' => now(), 'review_note' => $note], $note);
    }

    /**
     * Every reason this version cannot become active, for this person, now. Empty means it can.
     *
     * @return list<string>
     */
    public function activationRefusals(PlaybookVersion $version, ?User $by, ?string $reason): array
    {
        $refusals = $by === null ? ['no person named: activation is the act of a named human'] : $this->personRefusals($by, self::ACTIVATE);
        if ($version->status !== PlaybookVersion::REVIEWED) {
            $refusals[] = "version {$version->version} is {$version->status}, and only a reviewed version can be activated";
        }
        if (trim((string) $reason) === '') {
            $refusals[] = 'no reason given';
        }
        if ($version->content_digest !== PlaybookContent::digest($version->kind->value, $version->title, $version->body)) {
            $refusals[] = 'the stored digest does not match the content; the row was changed outside this class';
        }

        return $refusals;
    }

    /** reviewed -> active; the previously active version of the same key is retired in the same transaction. */
    public function activate(User $by, PlaybookVersion $version, string $reason): PlaybookVersion
    {
        return DB::transaction(function () use ($by, $version, $reason) {
            $fresh = $this->locked($version);
            $this->refuseUnless($this->activationRefusals($fresh, $by, $reason), 'Not activated');

            $previous = $this->activeFor($fresh->business_id, $fresh->brand_id, $fresh->kind, lock: true);
            if ($previous !== null) {
                $this->retireRow($previous, $by, "superseded by version {$fresh->version}: {$reason}");
            }

            $fresh->forceFill([
                'status' => PlaybookVersion::ACTIVE,
                'supersedes_id' => $previous?->id,
                'activated_by' => $by->getKey(),
                'activated_at' => now(),
                'activation_reason' => $reason,
            ])->save();
            $fresh->refresh();

            $this->event($fresh, 'activated', $by, $reason);
            $this->auditor->record('playbook.activated', $fresh,
                before: ['active_version' => $previous?->version, 'active_digest' => $previous?->content_digest],
                after: $this->snapshot($fresh), reason: $reason, actorUserId: $by->getKey());

            return $fresh;
        });
    }

    /** active -> retired by a named person with a reason; nothing is active for the key afterwards. */
    public function retire(User $by, PlaybookVersion $version, string $reason): PlaybookVersion
    {
        return DB::transaction(function () use ($by, $version, $reason) {
            $fresh = $this->locked($version);
            $refusals = $this->personRefusals($by, self::ACTIVATE);
            if ($fresh->status !== PlaybookVersion::ACTIVE) {
                $refusals[] = "version {$fresh->version} is {$fresh->status}, and only the active version can be retired";
            }
            if (trim($reason) === '') {
                $refusals[] = 'no reason given';
            }
            $this->refuseUnless($refusals, 'Not retired');

            $this->retireRow($fresh, $by, $reason);
            $this->auditor->record('playbook.retired', $fresh, before: ['status' => PlaybookVersion::ACTIVE], after: ['status' => PlaybookVersion::RETIRED], reason: $reason, actorUserId: $by->getKey());

            return $fresh;
        });
    }

    /** draft | in_review | reviewed -> withdrawn. The row is kept; a withdrawn version never becomes anything else. */
    public function withdraw(User $by, PlaybookVersion $version, string $reason): PlaybookVersion
    {
        return $this->transition($by, $version, self::DRAFT, [PlaybookVersion::DRAFT, PlaybookVersion::IN_REVIEW, PlaybookVersion::REVIEWED],
            PlaybookVersion::WITHDRAWN, 'withdrawn', ['withdrawn_by' => $by->getKey(), 'withdrawn_at' => now(), 'withdrawal_reason' => $reason], $reason);
    }

    /** The active version for a key, or null. A reader for screens; the runtime reads through `PlaybookLoader`. */
    public function activeFor(int $businessId, ?int $brandId, PlaybookKind $kind, bool $lock = false): ?PlaybookVersion
    {
        $query = PlaybookVersion::query()->where('business_id', $businessId)->where('kind', $kind->value)->where('status', PlaybookVersion::ACTIVE)
            ->where(fn ($q) => $brandId === null ? $q->whereNull('brand_id') : $q->where('brand_id', $brandId));

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Why a draft cannot be written, for this person, this business and brand, this content.
     *
     * @param  array{title?: string, summary?: ?string, body?: string}  $content
     * @return list<string>
     */
    public function draftRefusals(User $by, Business $business, ?Brand $brand, array $content): array
    {
        $refusals = $this->personRefusals($by, self::DRAFT);
        if ($brand !== null && (int) $brand->business_id !== (int) $business->id) {
            $refusals[] = "brand {$brand->code} belongs to another business";
        }

        return [...$refusals, ...array_map(static fn (string $p) => 'content: '.$p, PlaybookContent::problems((string) ($content['title'] ?? ''), (string) ($content['body'] ?? '')))];
    }

    /** @return array<string, mixed> the version as data: what an event row snapshots and an audit row carries */
    public function snapshot(PlaybookVersion $version): array
    {
        return [
            'uuid' => $version->uuid,
            'business_id' => $version->business_id,
            'brand_id' => $version->brand_id,
            'kind' => $version->kind->value,
            'version' => $version->version,
            'status' => $version->status,
            'title' => $version->title,
            'summary' => $version->summary,
            'content_digest' => $version->content_digest,
            'chars' => mb_strlen($version->body),
            'warnings' => PlaybookContent::warnings($version->body),
        ];
    }

    /** @param  list<string>  $fromStatuses */
    private function transition(User $by, PlaybookVersion $version, string $permission, array $fromStatuses, string $to, string $event, array $fill, ?string $reason, bool $requireReason = true): PlaybookVersion
    {
        return DB::transaction(function () use ($by, $version, $permission, $fromStatuses, $to, $event, $fill, $reason, $requireReason) {
            $fresh = $this->locked($version);
            $refusals = $this->personRefusals($by, $permission);
            if (! in_array($fresh->status, $fromStatuses, true)) {
                $refusals[] = "version {$fresh->version} is {$fresh->status}; this act needs ".implode(' or ', $fromStatuses);
            }
            if ($requireReason && trim((string) $reason) === '') {
                $refusals[] = 'no reason given';
            }
            $this->refuseUnless($refusals, 'Not '.str_replace('_', ' ', $event));

            $before = ['status' => $fresh->status];
            $fresh->forceFill(['status' => $to] + $fill)->save();
            $fresh->refresh();

            $this->event($fresh, $event, $by, $reason);
            $this->auditor->record('playbook.'.$event, $fresh, before: $before, after: $this->snapshot($fresh), reason: $reason, actorUserId: $by->getKey());

            return $fresh;
        });
    }

    /** @return list<string> */
    private function personRefusals(User $by, string $permission): array
    {
        if (! $by->is_active) {
            return ["user {$by->email} is disabled"];
        }
        if (! $by->hasPermissionTo($permission)) {
            return ["user {$by->email} does not hold {$permission}"];
        }

        return [];
    }

    /** @param  list<string>  $refusals */
    private function refuseUnless(array $refusals, string $verb): void
    {
        if ($refusals !== []) {
            throw new InvalidArgumentException($verb.': '.implode('; ', $refusals));
        }
    }

    private function locked(PlaybookVersion $version): PlaybookVersion
    {
        $fresh = PlaybookVersion::query()->lockForUpdate()->find($version->getKey());
        if ($fresh === null) {
            throw new RuntimeException("playbook version {$version->uuid} does not exist");
        }

        return $fresh;
    }

    private function retireRow(PlaybookVersion $version, User $by, string $reason): void
    {
        $version->forceFill([
            'status' => PlaybookVersion::RETIRED,
            'retired_by' => $by->getKey(),
            'retired_at' => now(),
            'retirement_reason' => $reason,
        ])->save();
        $version->refresh();
        $this->event($version, 'retired', $by, $reason);
    }

    private function event(PlaybookVersion $version, string $event, User $by, ?string $reason): void
    {
        PlaybookVersionEvent::create([
            'playbook_version_id' => $version->id,
            'event' => $event,
            'actor_user_id' => $by->getKey(),
            'actor_label' => $by->email,
            'reason' => $reason,
            'snapshot' => $this->snapshot($version),
            'content_digest' => $version->content_digest,
            'occurred_at' => now(),
        ]);
    }
}
