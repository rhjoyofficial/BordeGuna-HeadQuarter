<?php

use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\EscalationTriggers;
use App\Conversation\HandoverReason;
use App\Models\Agent;
use App\Models\Brand;
use App\Models\ConversationMessage;
use App\Models\FactsOrder;
use App\Models\PlatformAccount;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 | docs/95 §5 "Hand-over" and §12 (stream W2a): the inbox thread page's context package. The hand-over
 | reason, read from the latest state change the machine audited into `human_required`; and the known
 | customer's last five order facts, newest first. A stranger has no known orders; a thread the agent
 | holds has no hand-over. Every new string on the page has a Bangla entry in the dictionary.
 */

beforeEach(function () {
    Cache::flush();
    Http::fake();
    config(['hq.alerts.telegram_bot_token' => '', 'hq.alerts.telegram_chat_id' => '']);
    $this->seed(DomainSeeder::class);
    $this->seed(AgentSeeder::class);
    $this->owner = $this->hqUser('owner');

    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'simulated', 'is_active' => true],
    );
});

function icpIngest(string $text, string $psid = 'psid-context'): ConversationMessage
{
    $message = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => $psid, 'text' => $text, 'mid' => 'm-'.Str::uuid()]]]) as $inbound) {
        $message = app(ConversationService::class)->ingest($inbound);
    }

    return $message;
}

it('shows the hand-over reason from the audited state change, and the known customer\'s last five orders newest first', function () {
    Agent::where('key', 'support')->update(['status' => 'active', 'enabling_user_id' => User::factory()->create()->id]);

    $conversation = icpIngest('I want a refund, the jar was broken')->conversation()->sole();
    expect($conversation->state)->toBe(ConversationState::HumanRequired);

    // The customer becomes known (a phone links them), with six orders on the fact table.
    $customer = $conversation->customer;
    $customer->forceFill(['primary_phone_canonical' => '01711000000', 'name' => 'Rahim', 'attributes' => ['stranger' => false]])->save();
    $store = Store::query()->where('business_id', $conversation->business_id)->firstOrFail();
    foreach (range(1, 6) as $i) {
        FactsOrder::create([
            'business_id' => $conversation->business_id, 'store_id' => $store->id, 'order_ref' => "LLD-2026090{$i}-ORD",
            'status' => $i === 6 ? 'pending' : 'delivered', 'payment_status' => 'paid', 'gross' => 100 * $i,
            'customer_id' => $customer->id, 'placed_at' => now()->subDays(10 - $i),
        ]);
    }

    $this->actingAsHq($this->owner)->get('/inbox/'.$conversation->uuid)->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/show')
            ->where('handover.reason', HandoverReason::MoneyOrComplaint->value)
            ->where('handover.label', HandoverReason::MoneyOrComplaint->label())
            ->where('handover.detail', EscalationTriggers::REASON_MONEY_OR_COMPLAINT)
            ->where('handover.from', 'ai_active')
            ->where('handover.by', null)
            ->whereType('handover.at', 'string')
            ->has('orders', 5)
            ->where('orders.0.ref', 'LLD-20260906-ORD')
            ->where('orders.0.status', 'pending')
            ->where('orders.0.store', $store->name)
            ->where('orders.0.gross', '600.00')
            ->where('orders.4.ref', 'LLD-20260902-ORD'));
});

it('reports a thread that opened needing a person as agent_paused, with no transition to point at', function () {
    expect(Agent::where('key', 'support')->sole()->status)->toBe('paused');
    $conversation = icpIngest('Vaiya, hilsa ache?')->conversation()->sole();

    $this->actingAsHq($this->owner)->get('/inbox/'.$conversation->uuid)->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('handover.reason', HandoverReason::AgentPaused->value)
            ->where('handover.from', null)
            ->where('orders', []));
});

it('shows no hand-over on a thread the agent holds, and no known orders for a stranger', function () {
    Agent::where('key', 'support')->update(['status' => 'active', 'enabling_user_id' => User::factory()->create()->id]);
    $conversation = icpIngest('Vaiya, hilsa ache?')->conversation()->sole();
    expect($conversation->state)->toBe(ConversationState::AiActive);

    $this->actingAsHq($this->owner)->get('/inbox/'.$conversation->uuid)->assertOk()
        ->assertInertia(fn ($page) => $page->where('handover', null)->where('orders', [])->where('thread.stranger', true));
});

it('names the reason a person gave when a person moved the thread', function () {
    Agent::where('key', 'support')->update(['status' => 'active', 'enabling_user_id' => User::factory()->create()->id]);
    $conversation = icpIngest('Vaiya, hilsa ache?')->conversation()->sole();

    $this->actingAsHq($this->owner)->post('/inbox/'.$conversation->uuid.'/toggle-ai')->assertRedirect();

    $this->actingAsHq($this->owner)->get('/inbox/'.$conversation->uuid)->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('handover.reason', HandoverReason::AiDisabled->value)
            ->where('handover.by', $this->owner->email));
});

it('has a Bangla entry for every reason label and every new string on the context package', function () {
    $dictionary = json_decode(file_get_contents(resource_path('js/lib/i18n/bn.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach (HandoverReason::cases() as $reason) {
        expect($dictionary)->toHaveKey($reason->label());
    }
    foreach (['Why a person is needed', 'reason not recorded', 'opened needing a person', 'Recent orders', 'HQ order facts, newest first', 'No known orders', 'unknown store'] as $key) {
        expect($dictionary)->toHaveKey($key);
    }

    $page = file_get_contents(resource_path('js/pages/inbox/show.tsx'));
    expect($page)->toContain("t('Why a person is needed')")->toContain("t('No known orders')")->not->toContain("\u{2014}");
});
