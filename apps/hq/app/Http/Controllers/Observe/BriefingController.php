<?php

namespace App\Http\Controllers\Observe;

use App\Briefing\EvidenceBundle;
use App\Briefing\ExecutiveBriefingContract;
use App\Http\Controllers\Controller;
use App\Models\Briefing;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stored briefings and the evidence behind them (docs/48 M.4, docs/80 C.1).
 *
 * ⛔ Read-only. Nothing here composes, narrates, re-runs or edits a briefing: composing is
 * `briefing.compose` through the gateway, and a stored briefing is never changed by anything.
 *
 * Behind `hq.tools.facts.read`, the permission that composes one. A person limited to some businesses
 * sees only briefings that were composed for one of those businesses, or under a restriction that is
 * inside their own; a group-wide briefing is for people who see the group.
 */
final class BriefingController extends Controller
{
    public function index(Request $request, BusinessScope $scope): Response
    {
        $briefings = $this->visible(Briefing::query()->with('business:id,slug,name'), $scope->all($request->user()))
            ->latest('id')->limit(60)->get();

        return Inertia::render('observe/briefings', [
            'briefings' => $briefings->map(fn (Briefing $b) => $this->summary($b))->all(),
            'contract' => app(ExecutiveBriefingContract::class)->toArray(),
        ]);
    }

    public function show(Request $request, BusinessScope $scope, string $uuid): Response
    {
        $briefing = $this->visible(Briefing::query()->with('business:id,slug,name'), $scope->all($request->user()))
            ->where('uuid', $uuid)->firstOrFail();

        return Inertia::render('observe/briefing', [
            'briefing' => $this->summary($briefing) + [
                'validation' => $briefing->validation,
                'narrative' => $briefing->narrative,
                'narrative_check' => $briefing->narrative_check,
                'narrator' => $briefing->narrator,
                'correlation_id' => $briefing->correlation_id,
                'bundle_digest' => $briefing->bundle_digest,
                // Recomputed on every view: a stored bundle that no longer matches its digest says so.
                'bundle_intact' => $briefing->bundle === null ? null : EvidenceBundle::digestOf($briefing->bundle) === $briefing->bundle_digest,
            ],
            'bundle' => $briefing->bundle,
        ]);
    }

    /** @param  list<int>|null  $permitted  the viewer's own restriction, null for group-wide */
    private function visible(Builder $query, ?array $permitted): Builder
    {
        if ($permitted === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($permitted) {
            $q->whereIn('business_id', $permitted)
                ->orWhere(fn (Builder $r) => $r->whereNotNull('scope_business_ids')
                    ->whereRaw('scope_business_ids <@ ?::jsonb', [json_encode(array_values($permitted))]));
        });
    }

    private function summary(Briefing $briefing): array
    {
        return [
            'uuid' => $briefing->uuid,
            'briefing_date' => $briefing->briefing_date->toDateString(),
            'timezone' => $briefing->timezone,
            'business' => $briefing->business?->name,
            'restricted' => $briefing->scope_business_ids !== null,
            'state' => $briefing->state,
            'degraded_reasons' => $briefing->degraded_reasons,
            'narration_state' => $briefing->narration_state,
            'items' => count((array) ($briefing->bundle['items'] ?? [])),
            'generated_at' => $briefing->generated_at->toIso8601String(),
            'capability' => $briefing->capability,
            'capability_version' => $briefing->capability_version,
            'bundle_version' => $briefing->bundle_version,
        ];
    }
}
