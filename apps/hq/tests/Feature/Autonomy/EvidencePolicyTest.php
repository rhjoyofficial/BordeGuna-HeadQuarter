<?php

use App\Autonomy\Evidence\EvidencePolicies;
use App\Models\AuditLog;
use App\Models\AutonomyEvidenceHardCheck;
use App\Models\AutonomyEvidencePolicy;
use App\Models\AutonomyEvidencePolicyEvent;
use App\Models\AutonomyEvidenceRequirement;
use App\Models\AutonomyGrant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Autonomy\EvidenceFixtures as F;

/*
 | ADR-048, docs/81 C.4: the autonomy evidence policy is versioned, governed configuration. A number
 | is never invented: NULL is "not decided", a version holding one cannot become active, and an
 | activated version is immutable at the database level. Every number below is a TEST VALUE.
 */

function aepPolicies(): EvidencePolicies
{
    return app(EvidencePolicies::class);
}

/**
 * A write expected to be refused, inside its own savepoint: Postgres aborts the whole surrounding
 * test transaction on an error, and the savepoint keeps the refusal from poisoning the next check.
 */
function aepRefused(Closure $write): Closure
{
    return fn () => DB::transaction($write);
}

/** @return list<array<string, mixed>> */
function aepUndecided(): array
{
    return [F::requirement('a1', 'tool_selection', null, null, null), F::requirement('a1', 'enforcement', null, null, null)];
}

it('proposes a version with every number undecided, as data and not in force, under the proposer', function () {
    $owner = $this->hqUser('owner');

    $policy = F::propose(aepUndecided(), [F::hardCheck('a1', 'must_not_call', null, null)], $owner);

    expect($policy->status)->toBe('proposed')
        ->and($policy->version)->toBe(1)
        ->and($policy->activated_at)->toBeNull()
        ->and($policy->content_digest)->toBeNull()
        ->and(AutonomyEvidenceRequirement::where('policy_id', $policy->id)->whereNull('min_agreement_rate')->count())->toBe(2)
        ->and(aepPolicies()->active())->toBeNull();

    $event = AutonomyEvidencePolicyEvent::where('policy_id', $policy->id)->sole();
    expect($event->event)->toBe('proposed')
        ->and($event->actor_user_id)->toBe($owner->id)
        ->and($event->snapshot['requirements'][0])->toMatchArray(['level' => 'a1', 'dimension' => 'enforcement', 'min_agreement_rate' => null]);

    $audit = AuditLog::where('action', 'autonomy.evidence_policy.proposed')->sole();
    expect((int) $audit->actor_id)->toBe($owner->id);

    // The undecided is listed, field by field, and it is what stops activation.
    expect(aepPolicies()->structuralRefusals($policy))->toContain(
        'a1 tool_selection: min_agreement_rate is not decided (NULL)',
        'a1 enforcement: max_evidence_age_days is not decided (NULL)',
        'a1 hard check must_not_call: min_sample_count is not decided (NULL)',
    );
});

it('refuses to activate a version with an undecided field, with no requirement or with a gap in the ladder, and so does the database', function () {
    $owner = $this->hqUser('owner');

    $undecided = F::propose(aepUndecided(), by: $owner);
    expect(fn () => aepPolicies()->activate($undecided, $owner, 'go'))
        ->toThrow(RuntimeException::class, 'a1 tool_selection: min_agreement_rate is not decided (NULL)');

    $empty = F::propose([], by: $owner);
    expect(fn () => aepPolicies()->activate($empty, $owner, 'go'))->toThrow(RuntimeException::class, 'has no requirement row');

    $gap = F::propose([F::requirement('a2', 'tool_selection')], by: $owner);
    expect(fn () => aepPolicies()->activate($gap, $owner, 'go'))->toThrow(RuntimeException::class, 'a2 has requirements but a1 has none');

    // Around the domain class, straight at the table: the trigger refuses the same things.
    $write = fn (AutonomyEvidencePolicy $p) => DB::table('autonomy_evidence_policies')->where('id', $p->id)->update([
        'status' => 'active', 'activated_by' => $owner->id, 'activated_at' => now(), 'activation_reason' => 'around the class', 'content_digest' => str_repeat('0', 64),
    ]);
    expect(aepRefused(fn () => $write($undecided)))->toThrow(QueryException::class, 'holds an undecided (NULL) field');
    expect(aepRefused(fn () => $write($empty)))->toThrow(QueryException::class, 'has no requirement');
    expect(aepRefused(fn () => DB::table('autonomy_evidence_policies')->where('id', $gap->id)->update(['status' => 'active'])))
        ->toThrow(QueryException::class, 'activation needs a named person');

    expect(AutonomyEvidencePolicy::where('status', 'active')->count())->toBe(0);
});

it('refuses activation by nobody, by a disabled person, by a person without the permission, and without a reason', function () {
    $owner = $this->hqUser('owner');
    $viewer = $this->hqUser('finance_viewer');
    $disabled = $this->hqUser('owner');
    $disabled->forceFill(['is_active' => false])->save();
    $policy = F::propose([F::requirement('a1', 'tool_selection')], by: $owner);

    expect(aepPolicies()->activationRefusals($policy, null, 'why'))->toContain('no person named: activation is the act of a named human (--by)')
        ->and(aepPolicies()->activationRefusals($policy, $disabled->fresh(), 'why'))->toContain("user {$disabled->email} is disabled")
        ->and(aepPolicies()->activationRefusals($policy, $viewer, 'why'))->toContain("user {$viewer->email} does not hold hq.policies.manage")
        ->and(aepPolicies()->activationRefusals($policy, $owner, '  '))->toBe(['no reason given (--reason)'])
        ->and(aepPolicies()->activationRefusals($policy, $owner, 'why'))->toBe([]);

    expect(fn () => aepPolicies()->activate($policy, $viewer, 'why'))->toThrow(RuntimeException::class, 'does not hold hq.policies.manage');
    expect($policy->fresh()->status)->toBe('proposed');
});

it('activates a complete version under a named person and retires the previous one in the same act, so at most one is ever active', function () {
    $owner = $this->hqUser('owner');

    $first = F::activate($owner, [F::requirement('a1', 'tool_selection')]);
    expect($first->status)->toBe('active')
        ->and($first->activated_by)->toBe($owner->id)
        ->and($first->activation_reason)->toBe('test activation')
        ->and($first->content_digest)->toBe(aepPolicies()->digest($first));

    $second = F::activate($owner, [F::requirement('a1', 'tool_selection', 0.95)]);

    expect($first->fresh()->status)->toBe('retired')
        ->and($first->fresh()->retired_by)->toBe($owner->id)
        ->and($first->fresh()->retirement_reason)->toStartWith("superseded by version {$second->version}")
        ->and($second->supersedes_id)->toBe($first->id)
        ->and(AutonomyEvidencePolicy::where('status', 'active')->pluck('id')->all())->toBe([$second->id])
        ->and(AutonomyEvidencePolicyEvent::where('policy_id', $first->id)->pluck('event')->all())->toBe(['proposed', 'activated', 'retired'])
        ->and(AuditLog::where('action', 'autonomy.evidence_policy.activated')->count())->toBe(2);

    // A second active row is refused by the partial unique index even around the class.
    $third = F::propose([F::requirement('a1', 'tool_selection')], by: $owner);
    expect(aepRefused(fn () => DB::table('autonomy_evidence_policies')->where('id', $third->id)->update([
        'status' => 'active', 'activated_by' => $owner->id, 'activated_at' => now(), 'activation_reason' => 'x', 'content_digest' => str_repeat('1', 64),
    ])))->toThrow(QueryException::class, 'autonomy_evidence_policies_one_active');

    // Retiring leaves none active: evidence can then widen nothing.
    aepPolicies()->retire($second, $owner, 'withdrawn for the test');
    expect(aepPolicies()->active())->toBeNull()
        ->and($second->fresh()->status)->toBe('retired');
});

it('keeps an active or retired version immutable at the database level, and the history append-only', function () {
    $owner = $this->hqUser('owner');
    $active = F::activate($owner, [F::requirement('a1', 'tool_selection')], [F::hardCheck('a1', 'must_not_call')]);
    $requirement = AutonomyEvidenceRequirement::where('policy_id', $active->id)->sole();
    $check = AutonomyEvidenceHardCheck::where('policy_id', $active->id)->sole();

    expect(aepRefused(fn () => $requirement->update(['min_agreement_rate' => 0.1])))->toThrow(QueryException::class, 'its rows are immutable');
    expect(aepRefused(fn () => $requirement->delete()))->toThrow(QueryException::class, 'its rows are immutable');
    expect(aepRefused(fn () => $check->update(['min_sample_count' => 1000])))->toThrow(QueryException::class, 'its rows are immutable');
    expect(aepRefused(fn () => AutonomyEvidenceRequirement::create(['policy_id' => $active->id, 'autonomy_level' => 'a2', 'dimension' => 'reasoning', 'min_agreement_rate' => 0.1, 'min_sample_count' => 1, 'max_evidence_age_days' => 1])))
        ->toThrow(QueryException::class, 'its rows are immutable');
    expect(aepRefused(fn () => AutonomyEvidencePolicy::find($active->id)->forceFill(['name' => 'renamed'])->save()))->toThrow(QueryException::class, 'is active and immutable');
    expect(aepRefused(fn () => $active->delete()))->toThrow(QueryException::class, 'kept forever');

    aepPolicies()->retire($active, $owner, 'for the test');
    $retired = $active->fresh();
    expect(aepRefused(fn () => $retired->forceFill(['retirement_reason' => 'rewritten'])->save()))->toThrow(QueryException::class, 'is retired and immutable');
    expect(aepRefused(fn () => $retired->delete()))->toThrow(QueryException::class, 'kept forever');

    $event = AutonomyEvidencePolicyEvent::where('policy_id', $active->id)->first();
    expect(aepRefused(fn () => $event->update(['reason' => 'rewritten'])))->toThrow(QueryException::class, 'append-only');
    expect(aepRefused(fn () => $event->delete()))->toThrow(QueryException::class, 'append-only');

    // A proposed version is still a draft: its rows may change, and it may be deleted with them.
    $draft = F::propose([F::requirement('a1', 'tool_selection')], by: $owner);
    AutonomyEvidenceRequirement::where('policy_id', $draft->id)->update(['min_agreement_rate' => 0.5]);
    expect((float) AutonomyEvidenceRequirement::where('policy_id', $draft->id)->value('min_agreement_rate'))->toBe(0.5);
});

/*
 | docs/81 C.9: retire() acts on the version as the database has it. Until then it judged the caller's
 | in-memory status and saved the caller's instance, so an unsaved edit the caller held was written
 | with the retirement (and refused by the trigger), and a stale copy was judged "active".
 */
it('retires the version as it stands in the database, leaving an unsaved edit the caller holds alone', function () {
    $owner = $this->hqUser('owner');
    $active = F::activate($owner, [F::requirement('a1', 'tool_selection')]);
    $name = $active->name;
    $active->name = 'an unsaved edit the caller is holding';

    $retired = aepPolicies()->retire($active, $owner, 'withdrawn with an unsaved edit in hand');

    expect($retired->status)->toBe('retired')
        ->and($retired->retirement_reason)->toBe('withdrawn with an unsaved edit in hand')
        ->and($active->fresh()->status)->toBe('retired')
        ->and($active->fresh()->name)->toBe($name)
        ->and($active->name)->toBe('an unsaved edit the caller is holding')
        ->and(aepPolicies()->active())->toBeNull()
        ->and(AutonomyEvidencePolicyEvent::where('policy_id', $active->id)->where('event', 'retired')->count())->toBe(1)
        ->and(AuditLog::where('action', 'autonomy.evidence_policy.retired')->count())->toBe(1);
});

it('judges the status the database holds, so a stale copy of a retired version is refused in words and retires nothing twice', function () {
    $owner = $this->hqUser('owner');
    $active = F::activate($owner, [F::requirement('a1', 'tool_selection')]);
    $stale = AutonomyEvidencePolicy::find($active->id);

    aepPolicies()->retire($active->fresh(), $owner, 'the first retirement');

    expect(aepRefused(fn () => aepPolicies()->retire($stale, $owner, 'again, from a copy loaded before the first')))
        ->toThrow(RuntimeException::class, 'only the active version can be retired');
    expect(AutonomyEvidencePolicyEvent::where('policy_id', $active->id)->where('event', 'retired')->count())->toBe(1)
        ->and($active->fresh()->retirement_reason)->toBe('the first retirement');
});

it('digests the rules alone: the same rules give the same digest, one changed number another', function () {
    $owner = $this->hqUser('owner');
    $a = F::propose([F::requirement('a1', 'tool_selection'), F::requirement('a1', 'reasoning')], by: $owner);
    $b = F::propose([F::requirement('a1', 'reasoning'), F::requirement('a1', 'tool_selection')], by: $owner);
    $c = F::propose([F::requirement('a1', 'reasoning'), F::requirement('a1', 'tool_selection', 0.901)], by: $owner);

    expect(aepPolicies()->digest($a))->toBe(aepPolicies()->digest($b))
        ->and(aepPolicies()->digest($a))->not->toBe(aepPolicies()->digest($c));
});

it('validates the structure of a definition and never an overall row, an A0 or A5 requirement, an unknown check or a laxer measured state', function () {
    $problems = aepPolicies()->definitionProblems(['name' => '', 'requirements' => [
        ['level' => 'a0', 'dimension' => 'tool_selection'],
        ['level' => 'a5', 'dimension' => 'tool_selection'],
        ['level' => 'a1', 'dimension' => 'overall'],
        ['level' => 'a1', 'dimension' => 'tool_selection', 'min_agreement_rate' => 1.5],
        ['level' => 'a1', 'dimension' => 'tool_selection', 'min_sample_count' => 0],
        ['level' => 'a2', 'dimension' => 'reasoning', 'required_measured_state' => 'mostly_measured'],
    ], 'hard_checks' => [['level' => 'a1', 'check' => 'vibes']]]);

    expect($problems)->toContain('name is required')
        ->and(implode(' | ', $problems))->toContain('requirements[0]: level must be one of a1, a2, a3, a4')
        ->and(implode(' | ', $problems))->toContain('requirements[1]: level must be one of')
        ->and(implode(' | ', $problems))->toContain('requirements[2]: dimension must be one of')
        ->and(implode(' | ', $problems))->toContain('there is no overall row, ADR-030')
        ->and(implode(' | ', $problems))->toContain('requirements[3]: min_agreement_rate must be null (undecided) or a number above 0 and at most 1')
        ->and(implode(' | ', $problems))->toContain('requirements[4]: min_sample_count must be null (undecided) or a whole number of at least 1')
        ->and(implode(' | ', $problems))->toContain('requirements[5]: required_measured_state can only be fully_measured')
        ->and(implode(' | ', $problems))->toContain('hard_checks[0]: check must be a grader check key');

    expect(fn () => aepPolicies()->propose(['name' => 'x', 'requirements' => [['level' => 'a1', 'dimension' => 'overall']]]))
        ->toThrow(InvalidArgumentException::class);
    expect(AutonomyEvidencePolicy::count())->toBe(0);
});

it('ships no policy and no grant: no seeder writes either, and no seeder or migration names a number', function () {
    $this->seed(DatabaseSeeder::class);

    expect(AutonomyEvidencePolicy::count())->toBe(0)
        ->and(AutonomyEvidenceRequirement::count())->toBe(0)
        ->and(AutonomyGrant::count())->toBe(0);

    foreach (glob(database_path('seeders/*.php')) as $file) {
        expect(file_get_contents($file))->not->toContain('AutonomyEvidence')->not->toContain('EvidencePolicies');
    }

    // The migration defines columns and triggers and inserts no row.
    $migration = file_get_contents(database_path('migrations/2026_09_21_100000_create_autonomy_evidence_policy_tables.php'));
    expect($migration)->not->toContain('insert(')->not->toContain('INSERT INTO')->not->toContain('->default(0');
});

it('offers the governance acts on the console only, each a dry run until --apply, and --apply needs --by and --reason', function () {
    $owner = $this->hqUser('owner');
    $file = tempnam(sys_get_temp_dir(), 'aep');
    file_put_contents($file, (string) json_encode(['name' => 'console proposal', 'requirements' => aepUndecided()]));

    $this->artisan('hq:autonomy-policy:show')->expectsOutputToContain('No autonomy evidence policy is active, so evidence cannot widen autonomy.')->assertSuccessful();

    $this->artisan('hq:autonomy-policy:propose', ['file' => $file])->expectsOutputToContain('dry run: nothing was written')->assertSuccessful();
    expect(AutonomyEvidencePolicy::count())->toBe(0);

    $this->artisan('hq:autonomy-policy:propose', ['file' => $file, '--apply' => true, '--by' => $owner->email, '--reason' => 'writing the undecided down'])
        ->expectsOutputToContain('Version 1 proposed')->assertSuccessful();
    expect(AutonomyEvidencePolicy::sole()->status)->toBe('proposed');

    $this->artisan('hq:autonomy-policy:activate', ['version' => 1])
        ->expectsOutputToContain('a1 tool_selection: min_agreement_rate is not decided (NULL)')->assertFailed();
    $this->artisan('hq:autonomy-policy:activate', ['version' => 1, '--apply' => true])
        ->expectsOutputToContain('no person named')->assertFailed();
    $this->artisan('hq:autonomy-policy:activate', ['version' => 1, '--apply' => true, '--by' => $owner->email, '--reason' => 'try'])
        ->expectsOutputToContain('Not activated')->assertFailed();

    $this->artisan('hq:autonomy-policy:show', ['version' => 1])->expectsOutputToContain('proposed, not in force')->assertSuccessful();
    expect(AutonomyEvidencePolicy::where('status', 'active')->count())->toBe(0);
    unlink($file);
});
