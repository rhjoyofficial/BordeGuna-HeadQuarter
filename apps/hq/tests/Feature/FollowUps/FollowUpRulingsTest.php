<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentRefusal;
use App\Consent\ConsentState;
use App\FollowUps\FollowUpKind;
use App\FollowUps\FollowUpOrigin;
use App\FollowUps\FollowUpProposal;
use App\FollowUps\FollowUpProposalService;
use App\FollowUps\FollowUpState;
use App\FollowUps\FollowUpVocabulary;
use App\FollowUps\IllegalTransition;
use App\FollowUps\OpportunityKind;
use App\FollowUps\OpportunityProposal;
use App\FollowUps\Proposer;
use App\Gateway\Descriptor\Channel;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\FollowUp;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | docs/120 items 9, 10, 11, 12 and 15, docs/123 sections 5-7 and 9:
 |  - 9  consent fail-closed: missing consent refuses; a refused row is final; S4 never records consent;
 |  - 10 no transactional kind;
 |  - 11 propose never confers decide; brand_manager and support_agent cannot approve even their own
 |       proposal; a decider deciding on their own proposal is allowed by role and recorded visibly;
 |  - 12 the vocabulary is explicit, versioned and enforced by the code AND the database;
 |  - 15 every foreign key on and to the S4 tables is RESTRICT.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->owner = $this->hqUser('owner');
    $this->customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Rulings Customer', 'primary_phone_canonical' => '01711000666']);
    $this->service = app(FollowUpProposalService::class);
});

function rulGrant(Customer $c): void
{
    app(ConsentLedger::class)->record($c->id, $c->business_id, ConsentPurpose::Service, ConsentState::Granted, ConsentBasis::Operator, 'whatsapp',
        now()->subMinute(), ['evidence_ref' => 'test', 'actor_type' => 'human', 'actor_label' => 'test']);
}

function rulProposal(Customer $c, array $over = []): FollowUpProposal
{
    return new FollowUpProposal(...array_replace([
        'customer' => $c, 'kind' => FollowUpKind::Service, 'channel' => Channel::Whatsapp,
        'dueAt' => now()->addDay(), 'intent' => 'Confirm the delivery address', 'origin' => FollowUpOrigin::Operator,
    ], $over));
}

// ── item 9 ──────────────────────────────────────────────────────────────────────────────────────────

it('refuses on missing consent, keeps the refused row final after consent is recorded, and needs a fresh proposal (item 9)', function () {
    $refused = $this->service->proposeFollowUp(Proposer::person($this->owner), rulProposal($this->customer));
    expect($refused->state)->toBe(FollowUpState::Refused)->and($refused->consent_refusal)->toBe(ConsentRefusal::NotRecorded);

    // A person records the consent through the governed operation (the ledger), not through S4.
    rulGrant($this->customer);
    expect(fn () => $this->service->approveFollowUp($refused->fresh(), $this->owner))->toThrow(IllegalTransition::class);
    expect($refused->fresh()->state)->toBe(FollowUpState::Refused);

    $fresh = $this->service->proposeFollowUp(Proposer::person($this->owner), rulProposal($this->customer));
    expect($fresh->state)->toBe(FollowUpState::Proposed)->and($fresh->id)->not->toBe($refused->id);

    // S4 asks the ledger and never writes it: the only ledger call under App\FollowUps is permits().
    $calls = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('FollowUps'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            preg_match_all('/\$this->ledger->(\w+)\s*\(/', (string) file_get_contents($file->getPathname()), $m);
            array_push($calls, ...$m[1]);
        }
    }
    expect(array_values(array_unique($calls)))->toBe(['permits'])
        ->and(file_get_contents(app_path('FollowUps/FollowUpProposalService.php')))->not->toContain('ConsentEvent::')->not->toContain("table('consent_");
});

// ── items 10 and 12 ─────────────────────────────────────────────────────────────────────────────────

it('pins the versioned vocabulary, with no transactional kind (items 10, 12)', function () {
    expect(FollowUpVocabulary::VERSION)->toBe('followups.vocabulary.v1')
        ->and(FollowUpVocabulary::all())->toBe([
            'follow_ups.kind' => ['service', 'marketing'],
            'follow_ups.origin' => ['promise', 'opportunity', 'operator'],
            'follow_ups.state' => ['proposed', 'refused', 'approved', 'scheduled', 'declined', 'done', 'cancelled'],
            'opportunities.kind' => ['reorder', 'quotation', 'wholesale', 'product_interest', 'other'],
            'opportunities.state' => ['proposed', 'open', 'declined', 'closed'],
            'opportunities.close_reason' => ['converted', 'lost', 'no_response', 'duplicate', 'other'],
        ]);

    // A proposal (a person's or an agent run's) is built from enums only: no free-text kind, origin or channel.
    foreach (['kind' => FollowUpKind::class, 'origin' => FollowUpOrigin::class, 'channel' => Channel::class] as $param => $type) {
        $p = collect((new ReflectionClass(FollowUpProposal::class))->getConstructor()->getParameters())->firstWhere('name', $param);
        expect($p->getType()->getName())->toBe($type);
    }
    $p = collect((new ReflectionClass(OpportunityProposal::class))->getConstructor()->getParameters())->firstWhere('name', 'kind');
    expect($p->getType()->getName())->toBe(OpportunityKind::class);
});

it('has the database refuse any value outside the vocabulary, and a closed opportunity without its reason (item 12)', function () {
    $row = fn (array $over) => array_replace(['uuid' => (string) Str::uuid(), 'business_id' => $this->lld->business_id, 'customer_id' => $this->customer->id,
        'origin' => 'operator', 'kind' => 'service', 'channel' => 'whatsapp', 'due_at' => now(), 'intent' => 'x', 'state' => 'proposed',
        'proposer_type' => 'person', 'proposed_by_user_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()], $over);
    $opp = fn (array $over) => array_replace(['uuid' => (string) Str::uuid(), 'business_id' => $this->lld->business_id, 'customer_id' => $this->customer->id,
        'kind' => 'reorder', 'subject' => 'x', 'state' => 'proposed', 'proposer_type' => 'person', 'proposed_by_user_id' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now()], $over);
    $refused = fn (string $table, array $values) => expect(fn () => DB::transaction(fn () => DB::table($table)->insert($values)))->toThrow(QueryException::class);

    $refused('follow_ups', $row(['kind' => 'transactional']));
    $refused('follow_ups', $row(['origin' => 'model_guess']));
    $refused('follow_ups', $row(['state' => 'sent']));
    $refused('opportunities', $opp(['kind' => 'upsell']));
    $refused('opportunities', $opp(['state' => 'won']));
    $refused('opportunities', $opp(['state' => 'closed', 'close_reason' => 'won']));
    $refused('opportunities', $opp(['state' => 'closed', 'close_reason' => null]));

    expect(DB::table('follow_ups')->count())->toBe(0)->and(DB::table('opportunities')->count())->toBe(0);
    DB::table('follow_ups')->insert($row([]));
    DB::table('opportunities')->insert($opp(['state' => 'closed', 'close_reason' => 'no_response']));
    expect(DB::table('follow_ups')->count())->toBe(1)->and(DB::table('opportunities')->count())->toBe(1);
});

// ── item 11 ─────────────────────────────────────────────────────────────────────────────────────────

it('never lets proposing confer deciding: brand_manager and support_agent cannot approve even their own proposal (item 11)', function () {
    foreach (['brand_manager', 'support_agent'] as $role) {
        $held = RolesAndPermissionsSeeder::ROLES[$role];
        expect($held)->toContain('hq.followups.view', 'hq.followups.propose')->not->toContain('hq.followups.decide');
    }

    rulGrant($this->customer);
    foreach (['brand_manager', 'support_agent'] as $role) {
        $proposer = $this->hqUser($role);
        $own = $this->service->proposeFollowUp(Proposer::person($proposer), rulProposal($this->customer));
        $opportunity = $this->service->proposeOpportunity(Proposer::person($proposer), new OpportunityProposal($this->customer, OpportunityKind::Other, 'Asked about gift boxes'));

        expect(fn () => $this->service->approveFollowUp($own, $proposer))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->declineFollowUp($own, $proposer, 'no'))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->openOpportunity($opportunity, $proposer))->toThrow(AuthorizationException::class);
        $this->actingAsHq($proposer)->post("/follow-ups/{$own->uuid}/approve")->assertForbidden();
        expect($own->fresh()->state)->toBe(FollowUpState::Proposed)->and($opportunity->fresh()->state->value)->toBe('proposed');
    }

    // Every deciding method checks the decider first, before anything else happens.
    $source = file_get_contents(app_path('FollowUps/FollowUpProposalService.php'));
    foreach (['openOpportunity', 'declineOpportunity', 'closeOpportunity', 'approveFollowUp', 'declineFollowUp', 'completeFollowUp', 'cancelFollowUp'] as $method) {
        expect(preg_match('/function '.$method.'\([^)]*\): \w+\s*\{\s*\$this->decider\(\$by, \$\w+\);/', $source))->toBe(1, "{$method} does not check the decider first");
    }
});

it('records a decider deciding on their own proposal visibly, and not otherwise (item 11)', function () {
    rulGrant($this->customer);
    // docs/131 item 4: the owner decides their own proposal only as the sole eligible decider (the bootstrap rule).
    $own = $this->service->approveFollowUp($this->service->proposeFollowUp(Proposer::person($this->owner), rulProposal($this->customer)), $this->owner);
    $operator = $this->hqUser('operator');
    $theirs = $this->service->approveFollowUp($this->service->proposeFollowUp(Proposer::person($this->owner), rulProposal($this->customer)), $operator);

    $approved = fn (FollowUp $f) => AuditLog::where('action', 'follow_up.approved')->where('target_id', $f->id)->sole();
    expect($approved($own)->after['self_decided'] ?? null)->toBeTrue()
        ->and($approved($theirs)->after)->not->toHaveKey('self_decided');

    $this->actingAsHq($this->owner)->get('/follow-ups?state=all')->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', fn ($rows) => collect($rows)->firstWhere('uuid', $own->uuid)['self_decided'] === true
            && collect($rows)->firstWhere('uuid', $theirs->uuid)['self_decided'] === false));
});

// ── item 15 ─────────────────────────────────────────────────────────────────────────────────────────

it('keeps every foreign key on and to the S4 tables RESTRICT (item 15)', function () {
    $fks = DB::select("select conrelid::regclass::text as tbl, confrelid::regclass::text as target, conname, confdeltype, confupdtype
        from pg_constraint where contype = 'f'
        and (conrelid in ('follow_ups'::regclass, 'opportunities'::regclass) or confrelid in ('follow_ups'::regclass, 'opportunities'::regclass))");

    expect(count($fks))->toBeGreaterThanOrEqual(15);
    foreach ($fks as $fk) {
        expect($fk->confdeltype)->toBe('r', "{$fk->conname} on {$fk->tbl} -> {$fk->target} is not RESTRICT on delete");
    }
    expect(collect($fks)->contains(fn ($fk) => $fk->tbl === 'outcome_attributions' && $fk->target === 'follow_ups'))->toBeTrue()
        ->and(collect($fks)->contains(fn ($fk) => $fk->tbl === 'follow_ups' && $fk->target === 'conversations'))->toBeTrue()
        ->and(collect($fks)->contains(fn ($fk) => $fk->tbl === 'follow_ups' && $fk->target === 'opportunities'))->toBeTrue();
});
