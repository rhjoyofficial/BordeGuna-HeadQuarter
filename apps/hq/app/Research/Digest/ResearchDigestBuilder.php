<?php

namespace App\Research\Digest;

use App\Models\Brand;
use App\Models\Business;
use App\Models\ResearchArtifact;
use App\Models\ResearchDigest;
use App\Models\ResearchTask;
use App\Models\User;
use App\Research\Figures;
use App\Research\ResearchRefused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;

/**
 * A research digest: competitor or market notes for a business (docs/89 §3.8, docs/145-a).
 *
 * A digest is ASSEMBLED, never written: every entry is a fact or metric claim copied verbatim from a filed
 * research artifact (ADR-051: a fact carries its source page, span and retrieval time; a metric carries
 * its system of record), with its citation. Inferences and recommendations are not copied in as entries;
 * they stay in their artifact, labelled as such. The only free text is a person's note, labelled as the
 * person's, and every figure in it must appear in the cited entries (`Figures::unsupported`): a number
 * nobody can trace back is refused, never rounded or "estimated".
 *
 * No model and no network: the artifacts were filed by the research engine (flag `research.engine`,
 * off) or by a person. Append-only (a trigger holds it): a newer view is a new digest.
 */
final class ResearchDigestBuilder
{
    public const KINDS = ['competitor', 'market'];

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
    ) {}

    /** @param  list<int>  $artifactIds */
    public function build(User $by, Business $business, ?Brand $brand, string $kind, string $title, array $artifactIds, ?string $note = null): ResearchDigest
    {
        if (! $this->scope->allows($by, (int) $business->getKey())) {
            throw new ResearchRefused('That business is outside what you may act on.');
        }
        if ($brand !== null && (int) $brand->business_id !== (int) $business->getKey()) {
            throw new ResearchRefused('The brand belongs to another business.');
        }
        if (! in_array($kind, self::KINDS, true)) {
            throw new ResearchRefused('A digest is about competitors or the market.');
        }
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 160) {
            throw new ResearchRefused('A digest has a title of at most 160 characters.');
        }
        $artifactIds = array_values(array_unique(array_map('intval', $artifactIds)));
        if ($artifactIds === [] || count($artifactIds) > 20) {
            throw new ResearchRefused('A digest is built from 1 to 20 filed research artifacts.');
        }

        $entries = [];
        foreach ($artifactIds as $id) {
            $artifact = ResearchArtifact::query()->find($id);
            $task = $artifact !== null ? ResearchTask::query()->find($artifact->task_id) : null;
            if ($artifact === null || $task === null || (int) $task->business_id !== (int) $business->getKey()
                || ($brand !== null && $task->brand_id !== null && (int) $task->brand_id !== (int) $brand->getKey())) {
                throw new ResearchRefused("Research artifact {$id} was not filed for this business. A digest cites only this business's research.");
            }
            foreach ((array) $artifact->claims as $claim) {
                $claimKind = (string) ($claim['kind'] ?? '');
                if (! in_array($claimKind, ['fact', 'metric'], true)) {
                    continue;
                }
                $entries[] = [
                    'claim_id' => (string) $claim['id'],
                    'artifact_id' => (int) $artifact->getKey(),
                    'artifact_version' => (int) $artifact->version,
                    'kind' => $claimKind,
                    'statement' => (string) $claim['statement'],
                    'value' => $claim['value'] ?? null,
                    'unit' => $claim['unit'] ?? null,
                    'citation' => $claimKind === 'fact'
                        ? ['url' => $claim['url'] ?? null, 'retrieved_at' => $claim['retrieved_at'] ?? null, 'excerpt' => $claim['excerpt'] ?? null, 'span' => $claim['span'] ?? null]
                        : ['system' => $claim['system'] ?? null, 'ref' => $claim['ref'] ?? null, 'as_of' => $claim['as_of'] ?? null],
                ];
            }
        }
        if ($entries === []) {
            throw new ResearchRefused('None of these artifacts holds a cited fact or metric, so there is nothing to put in a digest.');
        }

        $note = $note !== null ? trim($note) : null;
        $unsupported = [];
        if ($note !== null && $note !== '') {
            if (mb_strlen($note) > 3000) {
                throw new ResearchRefused('A note is at most 3,000 characters.');
            }
            $support = [];
            foreach ($entries as $e) {
                $support[] = $e['statement'];
                $support[] = (string) ($e['value'] ?? '');
                $support[] = (string) ($e['citation']['excerpt'] ?? '');
            }
            $unsupported = Figures::unsupported($note, array_values(array_filter($support, static fn (string $s): bool => $s !== '')));
            if ($unsupported !== []) {
                throw new ResearchRefused('The note states '.implode(', ', $unsupported).', which no cited entry carries. A digest never adds a number of its own.');
            }
        }

        $checks = ['figures_in_note' => ['passed' => true, 'unsupported' => []], 'entries' => count($entries), 'only_cited_claims' => true];
        $digest = hash('sha256', (string) json_encode([$business->getKey(), $brand?->getKey(), $kind, $title, $entries, $note], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $row = ResearchDigest::query()->create([
            'business_id' => $business->getKey(),
            'brand_id' => $brand?->getKey(),
            'kind' => $kind,
            'title' => $title,
            'artifact_ids' => $artifactIds,
            'entries' => $entries,
            'note' => $note === '' ? null : $note,
            'checks' => $checks,
            'digest' => $digest,
            'created_by' => $by->getKey(),
        ]);
        $this->auditor->record('research.digest.built', $row,
            after: ['uuid' => $row->uuid, 'business_id' => $row->business_id, 'kind' => $kind, 'artifacts' => $artifactIds, 'entries' => count($entries), 'digest' => $digest],
            actorUserId: $by->getKey());

        return $row;
    }
}
