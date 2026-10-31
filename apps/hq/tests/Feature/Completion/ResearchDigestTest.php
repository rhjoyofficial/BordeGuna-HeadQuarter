<?php

use App\Models\Business;
use App\Models\ResearchArtifact;
use App\Models\ResearchDigest;
use App\Models\ResearchTask;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Completion\M4;

/*
 | docs/145-a (Phase 6): research digests are assembled from filed, cited claims only. A person's note may
 | state no figure that a cited entry does not carry; another business's research is refused; a digest is
 | append-only; a person limited to one business never sees another's digests or research.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

function rdArtifact(Business $business, array $claims): ResearchArtifact
{
    $task = ResearchTask::query()->create([
        'uuid' => (string) Str::uuid(), 'contract_version' => 'research.v1', 'business_id' => $business->id, 'question' => 'Competitor honey prices',
        'approved_sources' => ['example.com'], 'max_searches' => 0, 'max_fetches' => 1, 'status' => 'completed',
    ]);

    return ResearchArtifact::query()->create([
        'uuid' => (string) Str::uuid(), 'task_id' => $task->id, 'version' => 1, 'contract_version' => 'research.v1', 'question' => 'Competitor honey prices',
        'sources_used' => [], 'sources_discarded' => [], 'claims' => $claims, 'budget_spent' => [], 'as_of' => now(), 'digest' => str_repeat('a', 64),
    ]);
}

function rdFact(string $id, string $statement, string $value): array
{
    return ['id' => $id, 'kind' => 'fact', 'statement' => $statement, 'url' => 'https://example.com/honey', 'retrieved_at' => now()->toIso8601String(),
        'excerpt' => $statement, 'span' => ['start' => 0, 'end' => mb_strlen($statement)], 'value' => $value, 'unit' => 'BDT'];
}

it('copies only cited facts and metrics, with their citations, and checks every figure in the person\'s note', function () {
    $artifact = rdArtifact($this->laladia, [
        rdFact('price', 'Brand X sells 500 g honey at 650 taka.', '650'),
        ['id' => 'guess', 'kind' => 'inference', 'statement' => 'Brand X is probably losing money at 400 taka.'],
    ]);

    $this->actingAsHq($this->owner)->post('/research/digests', [
        'business_id' => $this->laladia->id, 'kind' => 'competitor', 'title' => 'Honey prices', 'artifact_ids' => [$artifact->id],
        'note' => 'Brand X asks 900 taka.',
    ])->assertSessionHasErrors('form');
    expect(ResearchDigest::query()->count())->toBe(0);

    $this->actingAsHq($this->owner)->post('/research/digests', [
        'business_id' => $this->laladia->id, 'kind' => 'competitor', 'title' => 'Honey prices', 'artifact_ids' => [$artifact->id],
        'note' => 'Brand X asks 650 taka for 500 g.',
    ])->assertSessionHasNoErrors();

    $digest = ResearchDigest::query()->sole();
    expect($digest->entries)->toHaveCount(1)
        ->and($digest->entries[0]['statement'])->toBe('Brand X sells 500 g honey at 650 taka.')
        ->and($digest->entries[0]['citation']['url'])->toBe('https://example.com/honey')
        ->and(collect($digest->entries)->pluck('kind')->all())->not->toContain('inference');

    expect(fn () => DB::transaction(fn () => ResearchDigest::query()->whereKey($digest->id)->update(['title' => 'changed'])))->toThrow(QueryException::class);
});

it('refuses research filed for another business, and hides other businesses from a person limited to one', function () {
    $theirs = rdArtifact($this->bionic, [rdFact('p', 'Brand Y sells ghee at 1200 taka.', '1200')]);
    $mine = rdArtifact($this->laladia, [rdFact('p', 'Brand Z sells honey at 700 taka.', '700')]);

    $this->actingAsHq($this->owner)->post('/research/digests', [
        'business_id' => $this->laladia->id, 'kind' => 'market', 'title' => 'Mixed', 'artifact_ids' => [$theirs->id],
    ])->assertSessionHasErrors('form');

    $limited = M4::limit($this->hqUser('operator'), 'laladia');
    $this->actingAsHq($limited)->post('/research/digests', ['business_id' => $this->bionic->id, 'kind' => 'market', 'title' => 'x', 'artifact_ids' => [$theirs->id]])->assertNotFound();
    $this->actingAsHq($this->owner)->post('/research/digests', ['business_id' => $this->bionic->id, 'kind' => 'market', 'title' => 'Ghee', 'artifact_ids' => [$theirs->id]])->assertSessionHasNoErrors();

    $this->actingAsHq($limited)->get('/research/digests')->assertOk()->assertInertia(fn (Assert $p) => $p->component('research/digests')
        ->has('digests', 0)->has('artifacts', 1)->where('artifacts.0.id', $mine->id));
});
