<?php

use App\Agents\AgentToolset;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Media\Upload\ChatMediaUploader;
use App\Models\Agent;
use App\Models\Business;
use App\Models\ChatAttachment;
use App\Models\UserBusinessScope;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;

/*
 | Ruling 31 Oct 2026 item 9: DEFAULT DENY. Agents cannot directly read raw chat attachments (photos, PDFs, videos, recordings, any
 | upload). Humans may inspect them. A future controlled extraction capability needs its own purpose, access, consent, retention and
 | business-scope rules; nothing here is one.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    $this->owner = $this->hqUser('owner');
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
});

/** Every registered tool whose code opens a stored upload or its bytes. */
function r2AttachmentReadingTools(): array
{
    $registry = app(ToolRegistry::class);
    $out = [];
    foreach ($registry->keys() as $key) {
        $source = (string) file_get_contents((new ReflectionClass($registry->get($key)))->getFileName());
        if (preg_match('/ChatAttachment|MediaAssetService|->contents\s*\(|MediaAsset::/', $source) === 1) {
            $out[] = $key;
        }
    }

    return $out;
}

it('gives no agent a tool that opens a raw upload: every such tool is human-only and held by no agent', function () {
    $readers = r2AttachmentReadingTools();
    expect($readers)->not->toBe([]); // the check reaches real tools (voice, reel), so it is not vacuous

    foreach ($readers as $key) {
        expect(app(ToolRegistry::class)->get($key)->descriptor()->agentExecutable)->toBeFalse("[{$key}] reads uploads and is agent-executable");
    }
    foreach (Agent::all() as $agent) {
        expect(array_intersect((array) $agent->allowed_tools, $readers))->toBe([])
            ->and(array_intersect(app(AgentToolset::class)->keysFor(Contexts::for($agent)), $readers))->toBe([]);
    }
});

it('refuses an agent that names an upload-reading tool, at the gateway, and the upload is untouched', function () {
    $upload = app(ChatMediaUploader::class)->store($this->owner, "\x1A\x45\xDF\xA3".str_repeat('a', 64), 'rec', null, ['audio'], personal: true, retentionDays: 1);
    $agent = Agent::query()->firstOrFail();

    $outcome = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: 'voice.transcribe', args: ['attachment_uuid' => $upload->uuid], caller: CallerKind::Agent,
        identity: ActingIdentity::forExecution(Contexts::for($agent)), correlationId: (string) Str::uuid(),
    ));

    expect($outcome->ok())->toBeFalse()->and($upload->fresh()->status)->toBe('stored');
});

it('keeps the agent runtime, the model layer and the context builders free of any raw upload reader', function () {
    $offenders = [];
    foreach (['Agents', 'Ai', 'Context', 'Execution', 'Skills'] as $dir) {
        $root = app_path($dir);
        if (! is_dir($root)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            if (preg_match('/ChatAttachment|MediaAssetService|MediaAsset::|->contents\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('lets a human with access inspect an upload, and nobody else', function () {
    $upload = app(ChatMediaUploader::class)->store($this->owner, "\x1A\x45\xDF\xA3".str_repeat('a', 64), 'rec', null, ['audio'], personal: true, retentionDays: 1);

    $this->actingAsHq($this->owner)->get("/media/{$upload->uuid}")->assertOk();
    // A person limited to some businesses cannot open another person's personal upload (docs/144-media-in-chat).
    $limited = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $limited->id, 'business_id' => Business::value('id')]);
    $this->actingAsHq($limited)->get("/media/{$upload->uuid}")->assertNotFound();
    expect(ChatAttachment::query()->where('uuid', $upload->uuid)->value('user_id'))->toBe($this->owner->id);
});
