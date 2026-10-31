<?php

use App\Models\MediaBudgetEntry;
use Tests\Support\Media\Budgets;
use App\Ai\Pause\AiPauseSwitch;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Media\MediaAssetService;
use App\Media\Pipeline\Exceptions\MediaPipelineRefused;
use App\Media\Pipeline\Exceptions\MediaPipelineUnavailable;
use App\Media\Pipeline\FakeMediaPipeline;
use App\Media\Pipeline\MediaJobSpec;
use App\Media\Pipeline\MediaJobStatus;
use App\Media\Pipeline\MediaPipeline;
use App\Media\Pipeline\VeoMediaPipeline;
use App\Media\Upload\AttachmentGuard;
use App\Media\Upload\AttachmentRejected;
use App\Media\Upload\ChatMediaUploader;
use App\Media\Upload\MediaRetention;
use App\Models\AskConversation;
use App\Models\AskMessage;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ChatAttachment;
use App\Models\ContentPublication;
use App\Models\IntegrationCredential;
use App\Models\MediaAsset;
use App\Models\MediaDraft;
use App\Models\UserBusinessScope;
use App\Tools\Connectors\CheckReelDraftTool;
use App\Tools\Connectors\DraftReelTool;
use App\Tools\ToolContext;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 | docs/144-media-in-chat and docs/144-media-pipeline: what HQ will store from an upload (decided from the bytes), the per-business
 | scope on every file, retention that deletes, files served only as downloads; and the reel pipeline's contract and its
 | draft-only, approve-before-anything posture.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mine = Business::orderBy('id')->firstOrFail();
    $this->theirs = Business::where('id', '!=', $this->mine->id)->orderBy('id')->firstOrFail();
    $this->owner = $this->hqUser('owner');
});

function m3Png(): string
{
    return "\x89PNG\r\n\x1A\n".str_repeat("\x00", 64);
}

function m3Mp4(): string
{
    return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
}

// ── what is stored ──────────────────────────────────────────────────────────────────────────────

it('decides the type from the bytes, never from the name or the claimed type', function () {
    $guard = app(AttachmentGuard::class);

    expect($guard->inspect(m3Png(), ['image'])['mime'])->toBe('image/png')
        ->and($guard->inspect(m3Mp4(), ['video'])['kind'])->toBe('video')
        ->and($guard->inspect("%PDF-1.4\n1 0 obj\n<< >>\nendobj\n", ['document'])['mime'])->toBe('application/pdf')
        ->and($guard->inspect("name,qty\nsalt,2\n", ['document'])['mime'])->toBe('text/csv');
});

it('refuses what could run: scripts, executables, HTML, SVG, archives and PDFs with active content', function (string $bytes) {
    expect(fn () => app(AttachmentGuard::class)->inspect($bytes, ['image', 'video', 'document', 'audio']))->toThrow(AttachmentRejected::class);
})->with([
    'html' => ['<html><body><script>alert(1)</script></body></html>'],
    'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
    'php' => ['<?php system($_GET["c"]);'],
    'shebang' => ["#!/bin/sh\nrm -rf /\n"],
    'windows exe' => ["MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 64)],
    'elf' => ["\x7FELF\x02\x01\x01".str_repeat("\x00", 64)],
    'zip' => ["PK\x03\x04".str_repeat("\x00", 64)],
    'pdf with javascript' => ["%PDF-1.4\n1 0 obj\n<< /JavaScript (app.alert(1)) >>\nendobj\n"],
    'pdf with embedded file' => ["%PDF-1.4\n1 0 obj\n<< /EmbeddedFile >>\nendobj\n"],
    'empty' => [''],
]);

it('enforces a size limit per kind and the kinds an endpoint allows', function () {
    config(['hq.media.limits.image_mb' => 0]);
    expect(fn () => app(AttachmentGuard::class)->inspect(m3Png(), ['image']))->toThrow(AttachmentRejected::class, 'limit');
    config(['hq.media.limits.image_mb' => 10]);
    expect(fn () => app(AttachmentGuard::class)->inspect(m3Png(), ['audio']))->toThrow(AttachmentRejected::class, 'not accepted here');
});

// ── per-business scope ──────────────────────────────────────────────────────────────────────────

it('stores an upload under a business with retention, and audits it', function () {
    $a = app(ChatMediaUploader::class)->store($this->owner, m3Png(), '../../etc/passwd.png', $this->mine->id);

    expect($a->business_id)->toBe($this->mine->id)->and($a->original_name)->toBe('passwd.png')->and($a->kind)->toBe('image')
        ->and($a->expires_at->isAfter(now()->addDays(89)))->toBeTrue()
        ->and($a->asset->provenance->isTrusted())->toBeFalse()
        ->and(AuditLog::where('action', 'chat_attachment.stored')->count())->toBe(1);
});

it('makes a restricted person name one of their businesses, and refuses any other', function () {
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->mine->id]);
    $uploader = app(ChatMediaUploader::class);

    expect(fn () => $uploader->store($restricted, m3Png(), 'a.png', null))->toThrow(AttachmentRejected::class, 'Choose which')
        ->and(fn () => $uploader->store($restricted, m3Png(), 'a.png', $this->theirs->id))->toThrow(AttachmentRejected::class, 'may not attach')
        ->and($uploader->store($restricted, m3Png(), 'a.png', $this->mine->id)->business_id)->toBe($this->mine->id);
    expect(MediaAsset::count())->toBe(1);
});

it('lets only the uploader and the people of that business open a file, and serves it as a download', function () {
    config(['hq.media.limits.video_mb' => 60]);
    m3SandboxConnectors(['connectors.media_upload']);
    $file = UploadedFile::fake()->createWithContent('pack.png', m3Png());

    $uuid = $this->actingAsHq($this->owner)->postJson('/ask/media', ['file' => $file, 'business_id' => $this->mine->id])->assertCreated()->json('uuid');

    $inScope = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $inScope->id, 'business_id' => $this->mine->id]);
    $outScope = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $outScope->id, 'business_id' => $this->theirs->id]);

    $this->actingAsHq($inScope)->get("/media/{$uuid}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    $this->actingAsHq($outScope)->get("/media/{$uuid}")->assertNotFound();

    // A PDF is only ever a download, never rendered.
    $pdf = $this->actingAsHq($this->owner)->postJson('/ask/media', ['file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\n"), 'business_id' => $this->mine->id])->json('uuid');
    expect($this->actingAsHq($this->owner)->get("/media/{$pdf}")->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('answers 404 to an upload while the switch is off and 422 to a refused file', function () {
    m3SandboxConnectors([]);
    $this->actingAsHq($this->owner)->postJson('/ask/media', ['file' => UploadedFile::fake()->createWithContent('a.png', m3Png())])->assertNotFound();

    m3SandboxConnectors(['connectors.media_upload']);
    $this->actingAsHq($this->owner)->postJson('/ask/media', ['file' => UploadedFile::fake()->createWithContent('a.png', '<script>alert(1)</script>')])->assertStatus(422);
    expect(ChatAttachment::count())->toBe(0);
});

it('links a question to the person\'s own uploads only', function () {
    $mine = app(ChatMediaUploader::class)->store($this->owner, m3Png(), 'mine.png', $this->mine->id);
    $other = $this->hqUser('operator');
    $theirs = app(ChatMediaUploader::class)->store($other, m3Png(), 'theirs.png', $this->mine->id);
    $conversation = AskConversation::forceCreate(['uuid' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'title' => 't', 'agent_key' => 'executive', 'last_activity_at' => now()]);
    AskMessage::forceCreate(['ask_conversation_id' => $conversation->id, 'role' => 'user', 'status' => 'done', 'content' => 'q']);

    $this->actingAsHq($this->owner)->postJson("/ask/{$conversation->uuid}/attachments", ['attachment_uuids' => [$mine->uuid, $theirs->uuid]])->assertOk()->assertJson(['linked' => 1]);
    // Another person's conversation does not exist for me.
    $this->actingAsHq($other)->postJson("/ask/{$conversation->uuid}/attachments", ['attachment_uuids' => [$theirs->uuid]])->assertNotFound();
    expect($mine->fresh()->status)->toBe('attached')->and($mine->fresh()->ask_message_id)->not->toBeNull()
        ->and($theirs->fresh()->status)->toBe('stored')->and($theirs->fresh()->ask_message_id)->toBeNull();
});

// ── retention ───────────────────────────────────────────────────────────────────────────────────

it('deletes expired files, and keeps the bytes another business still needs', function () {
    $a = app(ChatMediaUploader::class)->store($this->owner, m3Png(), 'a.png', $this->mine->id);
    $b = app(ChatMediaUploader::class)->store($this->owner, m3Png(), 'b.png', $this->theirs->id); // same bytes: one shared asset
    expect($a->media_asset_id)->toBe($b->media_asset_id);

    $a->forceFill(['expires_at' => now()->subDay()])->save();
    $result = app(MediaRetention::class)->purgeExpired();
    expect($result)->toBe(['attachments' => 1, 'assets' => 0])->and($a->fresh()->status)->toBe('purged')
        ->and(MediaAsset::count())->toBe(1)->and(app(MediaAssetService::class)->contents($b->asset))->not->toBeNull();

    $b->forceFill(['expires_at' => now()->subDay()])->save();
    expect(app(MediaRetention::class)->purgeExpired())->toBe(['attachments' => 1, 'assets' => 1])->and(MediaAsset::count())->toBe(0);
});

// ── the reel pipeline ───────────────────────────────────────────────────────────────────────────

function m3VeoCase(): array
{
    return [VeoMediaPipeline::class, true];
}

dataset('pipelines', [
    'veo' => [fn () => app(VeoMediaPipeline::class), true],
    'fake' => [fn () => new FakeMediaPipeline, false],
]);

it('starts a job, reports it running then ready with a video, and refuses an unknown handle (contract)', function (Closure $make, bool $real) {
    $pipeline = $make();
    expect($pipeline)->toBeInstanceOf(MediaPipeline::class);
    $state = ['polls' => 0];
    if ($real) {
        Http::fake([
            '*predictLongRunning' => Http::response(['name' => 'operations/abc123'], 200),
            '*operations/abc123' => function () use (&$state) {
                return ++$state['polls'] < 2 ? Http::response(['done' => false], 200)
                    : Http::response(['done' => true, 'response' => ['generateVideoResponse' => ['generatedSamples' => [['video' => ['uri' => 'https://files.test/v.mp4']]]]]], 200);
            },
            'files.test/*' => Http::response(m3Mp4(), 200),
        ]);
        IntegrationCredential::create(['provider' => VeoMediaPipeline::CREDENTIAL, 'account_ref' => 'hq', 'credential_type' => 'api_key', 'payload' => ['value' => 'GEM-KEY-001'], 'status' => 'active']);
    }
    $run = fn (Closure $fn) => $real ? app(CredentialVault::class)->openForAccounts([[VeoMediaPipeline::CREDENTIAL, 'hq']], $fn) : $fn();

    $handle = $run(fn () => $pipeline->start(new MediaJobSpec('A short reel of a hilsa pickle jar', 8, '9:16')));
    expect($handle)->toBeString()->not->toBe('');
    expect($run(fn () => $pipeline->check($handle))->state)->toBe(MediaJobStatus::RUNNING);
    $done = $run(fn () => $pipeline->check($handle));
    expect($done->state)->toBe(MediaJobStatus::READY)->and($done->mime)->toBe('video/mp4')->and(strlen((string) $done->video))->toBeGreaterThan(0);
    expect(fn () => $run(fn () => $pipeline->check($real ? '../../etc' : 'not-ours')))->toThrow(MediaPipelineRefused::class);
})->with('pipelines');

it('refuses a brief that asks for more than the cap before anything is sent', function () {
    expect(fn () => new MediaJobSpec('x', MediaJobSpec::MAX_SECONDS + 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MediaJobSpec('', 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MediaJobSpec('x', 5, '4:3'))->toThrow(InvalidArgumentException::class);
});

it('reports a provider that is down, and never puts the key in a message', function () {
    Http::fake(['*predictLongRunning' => Http::response('down', 503)]);
    IntegrationCredential::create(['provider' => VeoMediaPipeline::CREDENTIAL, 'account_ref' => 'hq', 'credential_type' => 'api_key', 'payload' => ['value' => 'GEM-KEY-001'], 'status' => 'active']);
    try {
        app(CredentialVault::class)->openForAccounts([[VeoMediaPipeline::CREDENTIAL, 'hq']], fn () => app(VeoMediaPipeline::class)->start(new MediaJobSpec('brief')));
        $this->fail('expected an outage');
    } catch (MediaPipelineUnavailable $e) {
        expect($e->getMessage())->not->toContain('GEM-KEY-001');
    }
    expect(fn () => (new FakeMediaPipeline)->start(new MediaJobSpec('FAKE-DOWN reel')))->toThrow(MediaPipelineUnavailable::class);
});

it('asks for a draft only after an approval, keeps it a draft, and publishes nothing', function () {
    m3SandboxConnectors(['connectors.media_pipeline']);
    $args = ['business_slug' => $this->mine->slug, 'brief' => 'A reel of the pickle jar', 'seconds' => 6];

    $asked = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'media.draft_reel', $args, $this->mine->id));
    expect($asked->status)->toBe(OutcomeStatus::AwaitingApproval)->and(MediaDraft::count())->toBe(0);

    // R2 (ruling 31 Oct item 12): with the default media budget of BDT 0 the approved run is refused before the provider is called.
    $draftTool = app(DraftReelTool::class);
    $zero = $draftTool->execute($args, new ToolContext('k0', 'c0', $this->mine->id, actorUserId: $this->owner->id));
    expect($zero->ok())->toBeFalse()->and((string) $zero->error)->toContain('BDT 0')->and(MediaDraft::count())->toBe(0);

    // Approved, with a budget: the tool reserves, runs, makes a draft that is generating, and the check turns it into a ready draft asset.
    Budgets::grant($this->mine->id, '500.00');
    $result = $draftTool->execute($args, new ToolContext('k1', 'c1', $this->mine->id, actorUserId: $this->owner->id));
    expect($result->ok())->toBeTrue()->and(MediaBudgetEntry::sole()->state)->toBe('committed')->and(MediaBudgetEntry::sole()->kind)->toBe('video');
    $draft = MediaDraft::sole();
    expect($draft->status)->toBe('generating')->and($draft->business_id)->toBe($this->mine->id);

    $check = app(CheckReelDraftTool::class);
    $ctx = new ToolContext('k2', 'c2', $this->mine->id, actorUserId: $this->owner->id);
    $check->execute(['business_slug' => $this->mine->slug, 'draft_uuid' => $draft->uuid], $ctx);
    expect($draft->fresh()->status)->toBe('generating');
    $check->execute(['business_slug' => $this->mine->slug, 'draft_uuid' => $draft->uuid], $ctx);
    $draft->refresh();
    expect($draft->status)->toBe('ready')->and($draft->result->provenance->value)->toBe('generated')
        // No rights recorded, so the asset cannot be published (fail closed), whoever asks.
        ->and($draft->result->permitsUse('social'))->toBeFalse();

    // Approving records a person's yes on the draft and nothing else.
    $this->actingAsHq($this->owner)->post("/media-drafts/{$draft->uuid}/decide", ['decision' => 'approved'])->assertRedirect();
    expect($draft->fresh()->status)->toBe('approved')->and($draft->fresh()->reviewed_by)->toBe($this->owner->id)
        ->and(ContentPublication::count())->toBe(0);
});

it('keeps a business\'s drafts from a person limited to another', function () {
    $draft = MediaDraft::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->theirs->id, 'brief' => 'secret brief', 'status' => 'ready']);
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->mine->id]);

    $this->actingAsHq($restricted)->get('/media-drafts')->assertOk()->assertInertia(fn ($p) => $p->has('drafts', 0));
    $this->actingAsHq($restricted)->post("/media-drafts/{$draft->uuid}/decide", ['decision' => 'approved'])->assertNotFound();
    $this->actingAsHq($restricted)->get("/media-drafts/{$draft->uuid}/video")->assertNotFound();
    expect($draft->fresh()->status)->toBe('ready');

    // Asking for a draft for the other business is refused as a missing business.
    $this->actingAsHq($restricted)->post('/media-drafts', ['business_slug' => $this->theirs->slug, 'brief' => 'x'])->assertNotFound();
});

it('refuses a reel while Pause All AI is on', function () {
    m3SandboxConnectors(['connectors.media_pipeline']);
    app(AiPauseSwitch::class)->pause($this->owner, 'test');

    $result = app(DraftReelTool::class)->execute(['business_slug' => $this->mine->slug, 'brief' => 'x'], new ToolContext('k', 'c', $this->mine->id));

    expect($result->ok())->toBeFalse()->and(MediaDraft::count())->toBe(0);
});
