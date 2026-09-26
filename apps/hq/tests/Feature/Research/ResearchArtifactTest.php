<?php

use App\Models\FeatureFlag;
use App\Models\ResearchArtifact;
use App\Models\ResearchSource;
use App\Models\ResearchTask;
use App\Research\ArtifactFiler;
use App\Research\ResearchRefused;
use App\Research\ResearchService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Content\PhaseSix;

/*
 | Stream T (docs/88 A.5): a research artifact files claims, never truth. Every fact carries the exact
 | words of its own task's page; memory is refused; a number no source carries is refused; the
 | artifact is append-only in the database; closing the task purges the pages and keeps the spans.
 */

const RA_TEXT = "Cold-pressed mustard oil is sold in 1 L and 500 ml bottles.\nThe authority lists 12 approved mills in Rajshahi.";

beforeEach(function () {
    Http::preventStrayRequests();
    PhaseSix::publicDns();
    PhaseSix::on('research.engine');
    $this->w = PhaseSix::world();
    $this->task = app(ResearchService::class)->open([
        'business_id' => $this->w['business']->id, 'brand_id' => $this->w['brand']->id,
        'question' => 'How many approved mills are in Rajshahi?', 'approved_sources' => ['bfsa.gov.bd'],
        'sources_approved_by' => $this->w['user']->id, 'max_searches' => 0, 'max_fetches' => 2,
    ], $this->w['user']);
    Http::fake(['https://bfsa.gov.bd/mills' => Http::response('<p>'.str_replace("\n", '</p><p>', RA_TEXT).'</p>', 200, ['Content-Type' => 'text/html'])]);
    $this->source = app(ResearchService::class)->fetch($this->task, 'https://bfsa.gov.bd/mills')['source'];
});

function raSpan(string $excerpt): array
{
    $start = mb_strpos(RA_TEXT, $excerpt);

    return ['start' => $start, 'end' => $start + mb_strlen($excerpt), 'excerpt' => $excerpt];
}

function raFact(ResearchSource $source, string $id, string $statement, string $excerpt, ?string $value = null): array
{
    $s = raSpan($excerpt);

    return ['id' => $id, 'kind' => 'fact', 'statement' => $statement, 'source' => $source->uuid,
        'span' => ['start' => $s['start'], 'end' => $s['end']], 'excerpt' => $s['excerpt'], 'value' => $value];
}

it('files a typed artifact whose facts carry their source, span, time and hash', function () {
    $artifact = app(ArtifactFiler::class)->file($this->task, [
        raFact($this->source, 'mills', 'The authority lists 12 approved mills in Rajshahi.', 'The authority lists 12 approved mills in Rajshahi.', '12'),
        ['id' => 'mills.inference', 'kind' => 'inference', 'statement' => 'Rajshahi has enough approved mills (12) to source from more than one.', 'based_on' => ['mills']],
        ['id' => 'next', 'kind' => 'recommendation', 'statement' => 'Ask the owner which mill supplies LaLaDia today.'],
    ], by: $this->w['user']);

    $fact = $artifact->claim('mills');
    expect($artifact->version)->toBe(1)
        ->and($artifact->digest)->toHaveLength(64)
        ->and($fact['kind'])->toBe('fact')->and($fact['trusted'])->toBeFalse()->and($fact['provenance'])->toBe('third_party')
        ->and($fact['url'])->toBe('https://bfsa.gov.bd/mills')->and($fact['content_hash'])->toBe($this->source->content_hash)
        ->and($fact['excerpt_hash'])->toBe(hash('sha256', 'The authority lists 12 approved mills in Rajshahi.'))
        ->and($fact['value'])->toBe('12')
        ->and($artifact->claim('mills.inference')['provenance'])->toBe('generated')
        ->and($artifact->sources_used[0]['source'])->toBe($this->source->uuid);
});

it('refuses a fact whose excerpt is not its page\'s own words at that span', function () {
    $claim = raFact($this->source, 'x', 'Sold in 1 L bottles.', 'Cold-pressed mustard oil is sold in 1 L');
    $claim['excerpt'] = 'Cold-pressed mustard oil is sold in 5 L';

    expect(fn () => app(ArtifactFiler::class)->file($this->task, [$claim]))->toThrow(ResearchRefused::class, 'own words');
});

it('refuses a fact with no span, and a fact citing a page this task did not fetch', function () {
    $noSpan = ['id' => 'x', 'kind' => 'fact', 'statement' => 'Oil is sold.', 'source' => $this->source->uuid];
    $foreign = raFact($this->source, 'y', 'Oil is sold.', 'Cold-pressed mustard oil');
    $foreign['source'] = '00000000-0000-0000-0000-000000000000';

    expect(fn () => app(ArtifactFiler::class)->file($this->task, [$noSpan]))->toThrow(ResearchRefused::class, 'no span is not a fact')
        ->and(fn () => app(ArtifactFiler::class)->file($this->task, [$foreign]))->toThrow(ResearchRefused::class, 'not a page this task fetched');
});

it('refuses a number the source does not carry, in a fact and in an inference', function () {
    $fact = raFact($this->source, 'x', 'The authority lists 15 approved mills.', 'The authority lists 12 approved mills in Rajshahi.');
    expect(fn () => app(ArtifactFiler::class)->file($this->task, [$fact]))->toThrow(ResearchRefused::class, '15');

    $ok = raFact($this->source, 'mills', 'The authority lists 12 approved mills in Rajshahi.', 'The authority lists 12 approved mills in Rajshahi.');
    $sum = ['id' => 'sum', 'kind' => 'inference', 'statement' => 'Together with 12 mills elsewhere that makes 24.', 'based_on' => ['mills']];
    expect(fn () => app(ArtifactFiler::class)->file($this->task, [$ok, $sum]))->toThrow(ResearchRefused::class, 'introduces 24');
});

it('reads Bangla digits as the same figures', function () {
    $fact = raFact($this->source, 'bn', 'অনুমোদিত মিল ১২টি।', 'The authority lists 12 approved mills in Rajshahi.');

    expect(app(ArtifactFiler::class)->file($this->task, [$fact])->claim('bn')['statement'])->toBe('অনুমোদিত মিল ১২টি।');
});

it('refuses memory as a source, and a metric that did not come from a system of record', function () {
    $memory = ['id' => 'm', 'kind' => 'fact', 'statement' => 'We sold well last Eid.', 'source' => 'memory:semantic:88'];
    $webMetric = ['id' => 'w', 'kind' => 'metric', 'statement' => 'Price is 450.', 'value' => '450', 'system' => 'erp', 'ref' => 'x', 'source' => $this->source->uuid];
    $noSystem = ['id' => 'n', 'kind' => 'metric', 'statement' => 'Price is 450.', 'value' => '450', 'system' => 'website', 'ref' => 'x'];

    expect(fn () => app(ArtifactFiler::class)->file($this->task, [$memory]))->toThrow(ResearchRefused::class, 'never a source')
        ->and(fn () => app(ArtifactFiler::class)->file($this->task, [$webMetric]))->toThrow(ResearchRefused::class, 'system of record')
        ->and(fn () => app(ArtifactFiler::class)->file($this->task, [$noSystem]))->toThrow(ResearchRefused::class, 'system of record');

    $metric = app(ArtifactFiler::class)->file($this->task, [
        ['id' => 'price', 'kind' => 'metric', 'statement' => 'The ERP price is 450.', 'value' => '450', 'unit' => 'BDT', 'system' => 'erp', 'ref' => 'erp.read_price:MO-1L'],
    ])->claim('price');
    expect($metric['trusted'])->toBeTrue()->and($metric['provenance'])->toBe('business');
});

it('refuses an inference that rests on nothing, or on a claim that is not in the artifact', function () {
    expect(fn () => app(ArtifactFiler::class)->file($this->task, [['id' => 'i', 'kind' => 'inference', 'statement' => 'It is popular.']]))
        ->toThrow(ResearchRefused::class, 'names no claim')
        ->and(fn () => app(ArtifactFiler::class)->file($this->task, [['id' => 'i', 'kind' => 'inference', 'statement' => 'It is popular.', 'based_on' => ['ghost']]]))
        ->toThrow(ResearchRefused::class, 'not an earlier claim');
});

it('is append-only in the database: a refreshed question is a new version', function () {
    $claim = raFact($this->source, 'mills', 'The authority lists 12 approved mills in Rajshahi.', 'The authority lists 12 approved mills in Rajshahi.');
    $one = app(ArtifactFiler::class)->file($this->task, [$claim]);
    $two = app(ArtifactFiler::class)->file($this->task, [$claim]);

    expect($two->version)->toBe(2)->and($two->digest)->not->toBe($one->digest);
    expect(fn () => DB::transaction(fn () => DB::table('research_artifacts')->where('id', $one->id)->update(['question' => 'changed'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('research_artifacts')->where('id', $one->id)->delete()))->toThrow(QueryException::class, 'append-only');
});

it('purges the pages when the task closes, keeps the spans and hashes, and files nothing after', function () {
    $claim = raFact($this->source, 'mills', 'The authority lists 12 approved mills in Rajshahi.', 'The authority lists 12 approved mills in Rajshahi.');
    $artifact = app(ArtifactFiler::class)->file($this->task, [$claim]);

    app(ResearchService::class)->close($this->task, 'completed');

    $source = ResearchSource::sole();
    expect($source->body)->toBeNull()->and($source->body_purged_at)->not->toBeNull()->and($source->content_hash)->toHaveLength(64)
        ->and(ResearchArtifact::find($artifact->id)->claim('mills')['excerpt'])->toBe('The authority lists 12 approved mills in Rajshahi.')
        ->and(fn () => app(ArtifactFiler::class)->file($this->task->fresh(), [$claim]))->toThrow(ResearchRefused::class, 'task is open')
        ->and(fn () => app(ResearchService::class)->fetch($this->task->fresh(), 'https://bfsa.gov.bd/mills'))->toThrow(ResearchRefused::class, 'completed');
});

it('keeps the pages only when storage rights are configured', function () {
    config(['content.research.store_bodies' => true]);
    app(ResearchService::class)->close($this->task, 'completed');

    expect(ResearchSource::sole()->body)->not->toBeNull();
});

it('refuses to file while the engine is off', function () {
    FeatureFlag::query()->where('key', 'research.engine')->delete();
    $claim = raFact($this->source, 'mills', 'The authority lists 12 approved mills in Rajshahi.', 'The authority lists 12 approved mills in Rajshahi.');

    expect(fn () => app(ArtifactFiler::class)->file($this->task, [$claim]))->toThrow(ResearchRefused::class, 'off');
    expect(ResearchTask::count())->toBe(1);
});
