<?php

use App\Content\ContentDigest;
use App\Content\ContentDraftService;
use App\Content\PhaseSixTools;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\ToolGateway;
use App\Models\Agent;
use App\Models\ContentDraft;
use App\Models\PlatformAccount;
use App\Models\ResearchTask;
use App\Platform\PlatformRegistry;
use App\Scheduling\ScheduledJobService;
use App\Support\Features\Features;
use App\Tools\Content\CreateDraftTool;
use App\Tools\Publishing\PublishPostTool;
use App\Tools\Research\FetchPageTool;
use App\Tools\Research\FileArtifactTool;
use App\Tools\Research\SearchTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Support\Content\PhaseSix;

/*
 | Stream T (docs/88 A.1, A.5): the Phase 6 foundation is built and INERT. Each test names one of the
 | switches that keep it off, so turning any on is a visible, reviewed change (for the eight-column
 | status table: implemented yes, merged on review, registered no, credentialed no, live-called no,
 | agent-held no, agent-granted no, activated no).
 */

function p6Context(?int $businessId = null): ToolContext
{
    return new ToolContext(idempotencyKey: 'k-'.uniqid(), correlationId: 'c-'.uniqid(), businessId: $businessId);
}

it('registers none of the Phase 6 tools: not in config, not in the registry, not in the gateway', function () {
    $keys = array_map(fn (string $c) => app($c)->key(), PhaseSixTools::DECLARED);

    expect($keys)->toBe(['research.search', 'research.fetch_page', 'research.file_artifact', 'content.create_draft', 'publishing.publish_post']);
    foreach (PhaseSixTools::DECLARED as $class) {
        expect(in_array($class, config('hq.tools'), true))->toBeFalse("[{$class}] is registered in config('hq.tools')")
            ->and(app($class))->toBeInstanceOf(Tool::class);
    }
    foreach ($keys as $key) {
        expect(app(ToolRegistry::class)->has($key))->toBeFalse("[{$key}] is in the registry")
            ->and(array_key_exists($key, app(ToolGateway::class)->descriptors()))->toBeFalse("[{$key}] is reachable through the gateway");
    }
});

it('lets no scheduled job name a Phase 6 tool', function () {
    foreach (['research.search', 'publishing.publish_post'] as $key) {
        expect(fn () => app(ScheduledJobService::class)->create(['name' => "p6 {$key}", 'kind' => 'cron', 'cron_expression' => '0 4 * * *', 'tool_key' => $key], null))
            ->toThrow(Exception::class);
    }
});

it('gives no agent any Phase 6 tool, and the Executive\'s role forbids publication', function () {
    $this->seed(AgentSeeder::class);
    $keys = array_map(fn (string $c) => app($c)->key(), PhaseSixTools::DECLARED);

    foreach (Agent::all() as $agent) {
        expect(array_intersect((array) $agent->allowed_tools, $keys))->toBe([], "[{$agent->key}] holds a Phase 6 tool");
    }
    expect(config('hq.roles.forbidden_actions.executive'))->toContain('publication')
        ->and(config('hq.roles.forbidden_actions.analytics'))->toContain('publication');
});

it('ships every Phase 6 flag off and no Phase 6 platform in the registry', function () {
    foreach (PhaseSixTools::FLAGS as $flag) {
        expect(app(Features::class)->enabled($flag))->toBeFalse("[{$flag}] is on");
    }
    foreach (['facebook_page', 'instagram', 'brave_search', 'tavily'] as $platform) {
        expect(app(PlatformRegistry::class)->has($platform))->toBeFalse("[{$platform}] has a registered provider");
    }
});

it('declares the tiers, effects and permissions docs/85 D names, none agent-executable', function () {
    $d = fn (string $c) => app($c)->descriptor();

    expect($d(SearchTool::class)->riskTier)->toBe(RiskTier::R2)->and($d(SearchTool::class)->readWrite)->toBe(ReadWrite::Read)
        ->and($d(FetchPageTool::class)->riskTier)->toBe(RiskTier::R2)->and($d(FetchPageTool::class)->readWrite)->toBe(ReadWrite::Read)
        ->and($d(FileArtifactTool::class)->riskTier)->toBe(RiskTier::R3)->and($d(FileArtifactTool::class)->readWrite)->toBe(ReadWrite::Write)
        ->and($d(CreateDraftTool::class)->riskTier)->toBe(RiskTier::R3)
        ->and($d(PublishPostTool::class)->riskTier)->toBe(RiskTier::R5)
        ->and($d(PublishPostTool::class)->customerFacing)->toBeTrue()
        ->and($d(PublishPostTool::class)->reversibility)->toBe(Reversibility::Irreversible)
        ->and($d(PublishPostTool::class)->idempotencyKeyFrom)->toContain('content_digest')
        ->and($d(PublishPostTool::class)->permission)->toBe('hq.tools.publishing.write')
        ->and($d(SearchTool::class)->permission)->toBe('hq.tools.research.read')
        ->and($d(CreateDraftTool::class)->permission)->toBe('hq.tools.content.write');

    foreach (PhaseSixTools::DECLARED as $class) {
        expect($d($class)->agentExecutable)->toBeFalse("[{$class}] is agent-executable")
            ->and($d($class)->agentRefusalReason())->not->toBeNull();
    }
    // R4 and R5 need a person's approval under V.1, with no threshold.
    expect($d(PublishPostTool::class)->riskTier->baseline()->value)->toBe('approval_required');
});

it('refuses every Phase 6 tool with the flags off, and sends nothing anywhere', function () {
    Http::preventStrayRequests();
    $w = PhaseSix::world();
    $task = ResearchTask::create(['contract_version' => 'research.task.v1', 'business_id' => $w['business']->id, 'question' => 'q',
        'approved_sources' => ['bfsa.gov.bd'], 'max_searches' => 1, 'max_fetches' => 1, 'status' => 'open']);
    $brief = PhaseSix::brief($w['brand'], $w['user']);
    $ctx = p6Context($w['business']->id);

    $results = [
        app(SearchTool::class)->execute(['business_slug' => $w['business']->slug, 'task_uuid' => $task->uuid, 'query' => 'q'], $ctx),
        app(FetchPageTool::class)->execute(['business_slug' => $w['business']->slug, 'task_uuid' => $task->uuid, 'url' => 'https://bfsa.gov.bd/'], $ctx),
        app(FileArtifactTool::class)->execute(['business_slug' => $w['business']->slug, 'task_uuid' => $task->uuid, 'claims' => [['id' => 'a']]], $ctx),
        app(CreateDraftTool::class)->execute(['business_slug' => $w['business']->slug, 'brief_id' => $brief->id, 'text' => 'x',
            'text_provenance' => 'business', 'platform' => 'facebook_page', 'product_lines' => ['mustard_oil']], $ctx),
    ];

    foreach ($results as $result) {
        expect($result->status)->toBe('failed')->and((string) $result->error)->toContain('off');
    }
    Http::assertNothingSent();
});

it('refuses publication even with every flag on, because the live re-read is not wired', function () {
    Http::preventStrayRequests();
    PhaseSix::on('content.drafting', 'publishing.write');
    $w = PhaseSix::world();
    $brief = PhaseSix::brief($w['brand'], $w['user']);
    $account = PlatformAccount::create(['brand_id' => $w['brand']->id, 'platform' => 'facebook_page', 'account_ref' => 'PAGE1',
        'capabilities' => ['publish_post'], 'approval_status' => 'approved', 'is_active' => true]);
    $draft = app(ContentDraftService::class)->create(['brief_id' => $brief->id, 'text' => 'দাম ৪৫০ টাকা।', 'text_provenance' => 'business',
        'platform' => 'facebook_page', 'product_lines' => ['mustard_oil'], 'product_facts' => [PhaseSix::erpPrice('MO-1L', '450')]]);
    app(ContentDraftService::class)->review($draft, 'approved', $w['user']);

    $result = app(PublishPostTool::class)->execute([
        'business_slug' => $w['business']->slug, 'draft_uuid' => $draft->uuid, 'platform_account_id' => $account->id,
        'account_ref' => 'PAGE1', 'content_digest' => ContentDigest::forPublication($draft->fresh(), $account->id),
    ], p6Context($w['business']->id));

    expect($result->status)->toBe('failed')->and((string) $result->error)->toContain('P6-G3');
    Http::assertNothingSent();
});

it('keeps a research task and a draft inside their own business', function () {
    $a = PhaseSix::world('biz-a');
    $b = PhaseSix::world('biz-b');
    $task = ResearchTask::create(['contract_version' => 'research.task.v1', 'business_id' => $a['business']->id, 'question' => 'q',
        'approved_sources' => [], 'max_searches' => 1, 'max_fetches' => 1, 'status' => 'open']);
    PhaseSix::on('content.drafting');
    $brief = PhaseSix::brief($a['brand'], $a['user']);
    $draft = app(ContentDraftService::class)->create(['brief_id' => $brief->id, 'text' => 'x', 'text_provenance' => 'business',
        'platform' => 'facebook_page', 'product_lines' => ['mustard_oil']]);

    expect(app(SearchTool::class)->execute(['business_slug' => 'biz-b', 'task_uuid' => $task->uuid, 'query' => 'q'], p6Context($b['business']->id))->error)
        ->toContain('no such research task')
        ->and(app(CreateDraftTool::class)->execute(['business_slug' => 'biz-b', 'brief_id' => $brief->id, 'text' => 'x', 'text_provenance' => 'business',
            'platform' => 'facebook_page', 'product_lines' => []], p6Context($b['business']->id))->error)->toContain('no such brief')
        ->and(app(PublishPostTool::class)->execute(['business_slug' => 'biz-b', 'draft_uuid' => $draft->uuid, 'platform_account_id' => 1,
            'account_ref' => 'x', 'content_digest' => str_repeat('a', 64)], p6Context($b['business']->id))->error)->toContain('no such draft');
    expect(ContentDraft::count())->toBe(1);
});

it('shows the approver the exact words and whether the digest matches them', function () {
    PhaseSix::on('content.drafting');
    $w = PhaseSix::world();
    $brief = PhaseSix::brief($w['brand'], $w['user']);
    $account = PlatformAccount::create(['brand_id' => $w['brand']->id, 'platform' => 'facebook_page', 'account_ref' => 'PAGE1',
        'capabilities' => ['publish_post'], 'approval_status' => 'approved', 'is_active' => true]);
    $draft = app(ContentDraftService::class)->create(['brief_id' => $brief->id, 'text' => 'দাম ৪৫০ টাকা।', 'text_provenance' => 'business',
        'platform' => 'facebook_page', 'product_lines' => ['mustard_oil'], 'product_facts' => [PhaseSix::erpPrice('MO-1L', '450')]]);

    $good = app(PublishPostTool::class)->preview(['draft_uuid' => $draft->uuid, 'platform_account_id' => $account->id,
        'content_digest' => ContentDigest::forPublication($draft, $account->id)], p6Context());
    $stale = app(PublishPostTool::class)->preview(['draft_uuid' => $draft->uuid, 'platform_account_id' => $account->id,
        'content_digest' => str_repeat('0', 64)], p6Context());

    expect($good['text'])->toBe('দাম ৪৫০ টাকা।')->and($good['digest_matches_draft'])->toBeTrue()->and($stale['digest_matches_draft'])->toBeFalse();
});
