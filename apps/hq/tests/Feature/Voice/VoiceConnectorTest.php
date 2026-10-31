<?php

use App\Ai\Pause\AiPauseSwitch;
use App\Commerce\Exceptions\Unsupported;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Media\Upload\ChatMediaUploader;
use App\Models\Business;
use App\Models\ChatAttachment;
use App\Models\IntegrationCredential;
use App\Models\MediaAsset;
use App\Models\MediaBudgetEntry;
use App\Models\UserBusinessScope;
use App\Models\VoiceTranscript;
use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\Exceptions\SpeechProviderUnavailable;
use App\Voice\Exceptions\SyntheticIdentityRefused;
use App\Voice\Fake\FakeSpeechToText;
use App\Voice\Fake\FakeTextToSpeech;
use App\Voice\Providers\OpenAiCompatibleSpeechToText;
use App\Voice\Providers\OpenAiCompatibleTextToSpeech;
use App\Voice\SpeechSynthesisRequest;
use App\Voice\SpeechToText;
use App\Voice\TextToSpeech;
use App\Voice\VoiceKind;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Media\Budgets;

/*
 | docs/144-voice-provider: speech in and out behind the registry, push to talk, and no extra authority. The provider contract (the
 | OpenAI-compatible adapters against faked HTTP, and the fakes), then the screen's two endpoints through the gateway.
 */

const M3_SPEECH_KEY = 'sk-speech-test-key-001';

function m3WebmBytes(): string
{
    return "\x1A\x45\xDF\xA3".str_repeat('a', 64);
}

function m3WithSpeechKey(Closure $fn): mixed
{
    IntegrationCredential::firstOrCreate(['provider' => OpenAiCompatibleSpeechToText::CREDENTIAL, 'account_ref' => 'hq'], ['credential_type' => 'api_key', 'payload' => ['value' => M3_SPEECH_KEY], 'status' => 'active']);

    return app(CredentialVault::class)->openForAccounts([[OpenAiCompatibleSpeechToText::CREDENTIAL, 'hq']], $fn);
}

dataset('speech to text', [
    'openai compatible' => [fn () => app(OpenAiCompatibleSpeechToText::class), true],
    'fake' => [fn () => new FakeSpeechToText, false],
]);

it('transcribes audio into an untrusted transcript, refuses empty and non-audio input, and reports a failing provider', function (Closure $make, bool $real) {
    $stt = $make();
    $answer = Http::response(['text' => 'আজকের অর্ডার কয়টি', 'language' => 'bn'], 200);
    Http::fake(['*audio/transcriptions' => function () use (&$answer) {
        return $answer;
    }]);

    $run = fn (string $audio, string $mime) => $real ? m3WithSpeechKey(fn () => $stt->transcribe($audio, $mime, 'bn')) : $stt->transcribe($audio, $mime, 'bn');

    $t = $run('audio-bytes', 'audio/webm');
    expect($t->isEmpty())->toBeFalse()->and($t->provenance->isTrusted())->toBeFalse()->and($t->provider)->toBe($stt->key());

    expect(fn () => $run('', 'audio/webm'))->toThrow(SpeechProviderRefused::class)
        ->and(fn () => $run('x', 'text/html'))->toThrow(SpeechProviderRefused::class);

    if ($real) {
        $answer = Http::response('slow down', 429);
        expect(fn () => m3WithSpeechKey(fn () => $stt->transcribe('a', 'audio/webm')))->toThrow(SpeechProviderUnavailable::class);
        IntegrationCredential::withTrashed()->forceDelete();
        $answer = Http::response(['error' => ['message' => 'bad key']], 401);
        try {
            m3WithSpeechKey(fn () => $stt->transcribe('a', 'audio/webm'));
        } catch (SpeechProviderRefused $e) {
            expect($e->getMessage())->not->toContain(M3_SPEECH_KEY);
        }
    }
})->with('speech to text');

it('refuses before any request leaves HQ when no credential scope is open', function () {
    Http::fake();

    expect(fn () => app(OpenAiCompatibleSpeechToText::class)->transcribe('a', 'audio/webm'))->toThrow(Unsupported::class)
        ->and(fn () => app(OpenAiCompatibleTextToSpeech::class)->synthesize(new SpeechSynthesisRequest('hello', 'alloy', 'en-US')))->toThrow(Unsupported::class);
    Http::assertNothingSent();
});

it('speaks a stock voice and refuses a cloned one before the adapter sees it', function () {
    Http::fake(['*audio/speech' => Http::response('MP3-BYTES', 200)]);

    $speech = m3WithSpeechKey(fn () => app(OpenAiCompatibleTextToSpeech::class)->synthesize(new SpeechSynthesisRequest('আসসালামু আলাইকুম', 'alloy', 'bn-BD')));
    expect($speech->audio)->toBe('MP3-BYTES')->and($speech->provenance->value)->toBe('generated');
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.M3_SPEECH_KEY) && $r['input'] === 'আসসালামু আলাইকুম');

    expect(new FakeTextToSpeech)->toBeInstanceOf(TextToSpeech::class)
        ->and(fn () => new SpeechSynthesisRequest('hello', 'my-voice', 'en-US', VoiceKind::Cloned))->toThrow(SyntheticIdentityRefused::class);
    expect(app(FakeSpeechToText::class))->toBeInstanceOf(SpeechToText::class);
});

// ── the endpoints, through the gateway ──────────────────────────────────────────────────────────────

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = $this->hqUser('owner');
});

function m3Recording(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('recording.webm', m3WebmBytes());
}

it('says it is not connected yet, and stores nothing, while the switch is off', function () {
    m3SandboxConnectors([]);

    $this->actingAsHq($this->owner)->postJson('/voice/transcribe', ['audio' => m3Recording()])->assertStatus(409)->assertJson(['state' => 'disabled']);

    expect(ChatAttachment::count())->toBe(0)->and(MediaAsset::count())->toBe(0);
});

it('refuses every transcription, before any audio leaves HQ, until a provider passes the Bangla bake-off (ruling 31 Oct item 8)', function () {
    m3SandboxConnectors(['connectors.voice']);
    expect(config('hq.voice.stt.benchmark_passed_provider'))->toBeNull();

    $this->actingAsHq($this->owner)->postJson('/voice/transcribe', ['audio' => m3Recording(), 'language' => 'bn'])
        ->assertStatus(409)->assertJson(['state' => 'benchmark_pending']);
    expect(ChatAttachment::where('status', '!=', 'purged')->count())->toBe(0)->and(VoiceTranscript::count())->toBe(0);

    // A passed provider other than the one the registry resolves is refused too: only the bake-off winner transcribes.
    config(['hq.voice.stt.benchmark_passed_provider' => 'google_speech']);
    $this->actingAsHq($this->owner)->postJson('/voice/transcribe', ['audio' => m3Recording()])->assertStatus(409)->assertJson(['state' => 'benchmark_pending']);
    expect(ChatAttachment::where('status', '!=', 'purged')->count())->toBe(0);
});

it('transcribes a recording, deletes it at once, and keeps no transcript unless the person agreed', function () {
    m3SandboxConnectors(['connectors.voice']);
    // R2: the sandbox fake stands in for a provider that passed the bake-off.
    config(['hq.voice.stt.benchmark_passed_provider' => 'fake_stt']);

    $this->actingAsHq($this->owner)->postJson('/voice/transcribe', ['audio' => m3Recording(), 'language' => 'bn'])
        ->assertOk()->assertJson(['text' => FakeSpeechToText::TEXT, 'provenance' => 'untrusted', 'transcript_uuid' => null]);

    expect(ChatAttachment::where('status', '!=', 'purged')->count())->toBe(0)->and(MediaAsset::count())->toBe(0)->and(VoiceTranscript::count())->toBe(0);

    $kept = $this->actingAsHq($this->owner)->postJson('/voice/transcribe', ['audio' => m3Recording(), 'keep_transcript' => true])->assertOk()->json('transcript_uuid');
    expect(VoiceTranscript::sole())->uuid->toBe($kept)
        ->and(VoiceTranscript::sole()->user_id)->toBe($this->owner->id)
        ->and(VoiceTranscript::sole()->expires_at)->not->toBeNull();
});

it('refuses a file that is not audio, and a person without the voice permission', function () {
    m3SandboxConnectors(['connectors.voice']);

    $this->actingAsHq($this->owner)->postJson('/voice/transcribe', ['audio' => UploadedFile::fake()->createWithContent('x.webm', '<html><script>alert(1)</script></html>')])->assertStatus(422);
    expect(MediaAsset::count())->toBe(0);

    $viewer = $this->hqUser('finance_viewer');
    $this->actingAsHq($viewer)->postJson('/voice/transcribe', ['audio' => m3Recording()])->assertForbidden();
    $this->actingAsHq($viewer)->postJson('/voice/speak', ['text' => 'hello', 'business_slug' => 'laladia'])->assertForbidden();
});

it('is refused while Pause All AI is on, and the recording does not outlive the refusal', function () {
    m3SandboxConnectors(['connectors.voice']);
    app(AiPauseSwitch::class)->pause($this->owner, 'test');

    $this->actingAsHq($this->owner)->postJson('/voice/transcribe', ['audio' => m3Recording()])->assertStatus(409);
    $this->actingAsHq($this->owner)->postJson('/voice/speak', ['text' => 'hello', 'business_slug' => 'laladia'])->assertStatus(409);

    expect(ChatAttachment::where('status', '!=', 'purged')->count())->toBe(0)->and(MediaAsset::count())->toBe(0);
});

it('lets a person name only their own recording', function () {
    m3SandboxConnectors(['connectors.voice']);
    $other = $this->hqUser('operator');
    $theirs = app(ChatMediaUploader::class)->store($other, m3WebmBytes(), 'rec', null, ['audio'], personal: true, retentionDays: 1);

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'voice.transcribe', ['attachment_uuid' => $theirs->uuid]));

    expect($outcome->ok())->toBeFalse()->and($theirs->fresh()->status)->toBe('stored');
});

it('refuses to read aloud while the business\'s media budget is BDT 0, the default (ruling 31 Oct item 12)', function () {
    m3SandboxConnectors(['connectors.voice']);

    $this->actingAsHq($this->owner)->postJson('/voice/speak', ['text' => 'আপনার অর্ডার তৈরি', 'language' => 'bn-BD', 'business_slug' => 'laladia'])
        ->assertStatus(409)->assertJsonPath('error', fn (string $e) => str_contains($e, 'BDT 0'));
    expect(MediaAsset::count())->toBe(0)->and(MediaBudgetEntry::count())->toBe(0);
});

it('reads an answer aloud for the person alone, as a day-long file only they can open', function () {
    m3SandboxConnectors(['connectors.voice']);
    Budgets::grant(Business::where('slug', 'laladia')->value('id'), '100.00');

    $res = $this->actingAsHq($this->owner)->postJson('/voice/speak', ['text' => 'আপনার অর্ডার তৈরি', 'language' => 'bn-BD', 'business_slug' => 'laladia'])->assertOk();
    expect(MediaBudgetEntry::sole())->state->toBe('committed')->kind->toBe('voice')->provider->toBe('fake_tts');
    $uuid = $res->json('attachment_uuid');
    $attachment = ChatAttachment::where('uuid', $uuid)->sole();
    expect($attachment->business_id)->toBeNull()->and($attachment->kind)->toBe('audio')->and($attachment->expires_at->isBefore(now()->addDays(2)))->toBeTrue();

    $this->actingAsHq($this->owner)->get("/media/{$uuid}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    // A restricted person with the ask permission cannot open another person's no-business file.
    $other = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $other->id, 'business_id' => Business::value('id')]);
    $this->actingAsHq($other)->get("/media/{$uuid}")->assertNotFound();
});
