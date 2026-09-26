<?php

use App\Http\Controllers\Webhooks\MetaForwardController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Agent;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Platform\PlatformRegistry;
use App\Tools\Conversation\SendWhatsAppReplyTool;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;

/*
 | docs/88 A points 1 and 2 (stream Q): what makes the channel adapters inert, each held by a test that
 | fails the moment someone wires one up without a gate. Built is not registered, not credentialed, not
 | live-called, not agent-held, not agent-granted and not activated.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
});

it('ships PlatformRegistry empty: no Messenger, Instagram or WhatsApp provider is registered', function () {
    $registry = app(PlatformRegistry::class);

    expect($registry->platforms())->toBe([])
        ->and($registry->has('facebook_page'))->toBeFalse()
        ->and($registry->has('instagram_account'))->toBeFalse()
        ->and($registry->has('whatsapp_number'))->toBeFalse();
});

it('constructs no send adapter and no sender anywhere in the application: only their tests do', function () {
    $offenders = [];
    $needles = ['MetaMessengerChannel', 'MetaInstagramChannel', 'WhatsAppCloudChannel', 'ChannelSender', 'MetaGraphClient'];
    $roots = [app_path(), base_path('bootstrap'), config_path(), base_path('routes'), database_path()];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            // The adapters' own folder and the sender's own file declare them; nothing else may mention them.
            if (! str_ends_with($path, '.php') || str_contains($path, '/Conversation/Channels/Meta/') || str_contains($path, '/Conversation/Deliveries/')) {
                continue;
            }
            // Code only: a docblock may name a class to say it is not wired; a statement may not.
            $source = implode('', array_map(
                static fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                token_get_all((string) file_get_contents($path)),
            ));
            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = "{$path} mentions {$needle}";
                }
            }
            if (preg_match('/PlatformRegistry[^;]*->register\(|->register\(\s*(new|app\()/', $source) === 1 && str_contains($source, 'PlatformRegistry')) {
                $offenders[] = "{$path} registers a platform provider";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('gives the WhatsApp reply tool to no agent, and does not let an agent run it', function () {
    $this->seed(AgentSeeder::class);

    $holders = Agent::query()->get(['key', 'allowed_tools'])
        ->filter(fn (Agent $a) => in_array('messaging.send_whatsapp_reply', (array) $a->allowed_tools, true))
        ->pluck('key')->all();

    expect($holders)->toBe([])
        ->and(app(SendWhatsAppReplyTool::class)->descriptor()->agentExecutable)->toBeFalse();
});

it('ships both Meta inbound flags OFF: the webhook and the n8n copy-forward', function () {
    expect(FeatureFlag::where('key', MetaWebhookController::FLAG)->where('environment', '*')->sole()->enabled)->toBeFalse()
        ->and(FeatureFlag::where('key', MetaForwardController::FLAG)->where('environment', '*')->sole()->enabled)->toBeFalse();

    $this->postJson('/api/webhooks/meta/forwarded', ['object' => 'page', 'entry' => []])->assertStatus(404);
    $this->postJson('/api/webhooks/meta', ['object' => 'whatsapp_business_account', 'entry' => []])->assertStatus(404);
});

it('lists no WhatsApp template, so nothing may be sent outside the window, and asks no production credential to build', function () {
    expect(config('hq.messaging.whatsapp.templates'))->toBe([]);

    // Building needs no credential: the three token providers exist only as names, and none is stored.
    expect(IntegrationCredential::query()->whereIn('provider', ['meta_page_token', 'meta_instagram_token', 'whatsapp_access_token', 'meta_forward_secret'])->count())->toBe(0);
});

it('keeps the staging stack a definition that no release script starts', function () {
    $repo = dirname(base_path(), 2);
    expect(file_exists("{$repo}/deploy/staging/docker-compose.yml"))->toBeTrue();

    $compose = (string) file_get_contents("{$repo}/deploy/staging/docker-compose.yml");
    expect($compose)->toContain('name: bordeguna-hq-staging')
        ->and($compose)->not->toContain('Host(`hq.bordeguna.com`)');

    foreach (['deploy.sh', 'rollback.sh', 'verify-only.sh'] as $script) {
        expect((string) file_get_contents("{$repo}/deploy/{$script}"))->not->toContain('staging');
    }
});
