<?php

use App\Conversation\Language\LanguagePolicies;
use App\Conversation\Language\LanguageRequirement;
use App\Models\AuditLog;
use App\Models\LanguagePolicy;
use App\Models\LanguagePolicyEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 | docs/92 A.8 (stream W1): the customer language policy is versioned, owner-controlled data. Version 1 (the
 | standing rule) is seeded active by its migration; every later version is proposed as data and activated only
 | by a named person with a reason through `hq:language-policy:activate`; an activated version is immutable at
 | the database level; at most one is active; the model never chooses it.
 */

function lpPolicies(): LanguagePolicies
{
    return app(LanguagePolicies::class);
}

/** docs/92 A.8: the future owner choice, romanised Bangla for a mixed customer, as a definition. */
function lpRomanisedForMixed(): array
{
    $rules = LanguagePolicies::V1['rules'];
    $rules['rules']['mixed'] = ['language' => 'bn', 'script' => 'latin'];

    return ['name' => 'Customer reply script, v2 (romanised Bangla for mixed messages)', 'summary' => 'test', 'rules' => $rules];
}

/** A write expected to be refused, inside its own savepoint, so the refusal does not poison the test transaction. */
function lpRefused(Closure $write): Closure
{
    return fn () => DB::transaction($write);
}

it('ships version 1 active from the migration, with the rules docs/92 A.1 states and the digest the class computes', function () {
    $active = lpPolicies()->active();

    expect($active)->not->toBeNull()
        ->and($active->version)->toBe(1)
        ->and($active->status)->toBe('active')
        ->and($active->activated_by)->toBeNull()
        ->and($active->activation_reason)->toContain('docs/92 A.1')
        ->and($active->content_digest)->toBe(LanguagePolicies::digestOf(LanguagePolicies::V1['rules']))
        ->and($active->rules['rules'])->toEqual(LanguagePolicies::V1['rules']['rules'])
        ->and($active->rules['thread_tags'])->toEqual(['bn' => 'bn', 'en' => 'en', 'banglish' => 'mixed'])
        ->and(LanguagePolicyEvent::where('policy_id', $active->id)->pluck('event')->all())->toBe(['activated'])
        ->and(LanguagePolicy::count())->toBe(1);
});

it('resolves the requirement from the thread tag the core records: Bangla and mixed to Bengali script, English to Latin, none to nothing', function () {
    $mixed = lpPolicies()->requirementFor('banglish');
    expect($mixed)->toBeInstanceOf(LanguageRequirement::class)
        ->and($mixed->language)->toBe('bn')
        ->and($mixed->script)->toBe(LanguageRequirement::SCRIPT_BENGALI)
        ->and($mixed->rule)->toBe('mixed')
        ->and($mixed->threadLanguage)->toBe('banglish')
        ->and($mixed->policyVersion)->toBe(1)
        ->and($mixed->policyDigest)->toBe(lpPolicies()->active()->content_digest)
        ->and($mixed->brandNames)->toContain('LaLaDia', 'Bionic Garden', 'Bor de Guna')
        ->and($mixed->maxOtherScriptShare)->toBe(0.2)
        ->and($mixed->describe())->toBe('Bangla in Bengali script (language policy v1, rule mixed)');

    expect(lpPolicies()->requirementFor('bn'))->toMatchObject(['language' => 'bn', 'script' => 'bengali', 'rule' => 'bn'])
        ->and(lpPolicies()->requirementFor('en'))->toMatchObject(['language' => 'en', 'script' => 'latin', 'rule' => 'en'])
        ->and(lpPolicies()->requirementFor(null))->toBeNull()
        ->and(lpPolicies()->reasonForNone(null))->toContain('no script was detected')
        ->and(lpPolicies()->requirementFor('hi'))->toBeNull()
        ->and(lpPolicies()->reasonForNone('hi'))->toContain('maps the thread\'s language tag [hi] to no requirement');

    // The requirement round-trips as data, and data that says "none" never becomes one.
    expect(LanguageRequirement::fromArray($mixed->toArray()))->toEqual($mixed)
        ->and(LanguageRequirement::fromArray(['required' => false, 'language' => 'bn', 'script' => 'bengali']))->toBeNull();
});

it('proposes a new version as data and not in force: the mixed rule still answers in Bengali script until somebody activates it', function () {
    $owner = $this->hqUser('owner');

    $v2 = lpPolicies()->propose(lpRomanisedForMixed(), $owner, 'the owner is considering romanised Bangla');

    expect($v2->version)->toBe(2)
        ->and($v2->status)->toBe('proposed')
        ->and($v2->supersedes_id)->toBe(lpPolicies()->active()->id)
        ->and(lpPolicies()->active()->version)->toBe(1)
        ->and(lpPolicies()->requirementFor('banglish')->script)->toBe(LanguageRequirement::SCRIPT_BENGALI)
        ->and(LanguagePolicyEvent::where('policy_id', $v2->id)->sole()->event)->toBe('proposed')
        ->and((int) AuditLog::where('action', 'conversation.language_policy.proposed')->sole()->actor_id)->toBe($owner->id);

    // A definition the validator could not act on is refused before it is written.
    $bad = lpRomanisedForMixed();
    $bad['rules']['rules']['hi'] = ['language' => 'hi', 'script' => 'devanagari'];
    $bad['rules']['thread_tags']['hi'] = 'nowhere';
    $bad['rules']['max_other_script_share'] = 1.5;
    $bad['rules']['allowed_tokens'][] = 'emoji';
    expect(lpPolicies()->definitionProblems($bad))->toContain(
        'rules.rules.hi is not a known language key (bn, en, mixed, banglish)',
        'rules.rules.hi: script must be one of bengali, latin',
        'rules.thread_tags.hi is not a tag the conversation core records (bn, en, banglish)',
        'rules.thread_tags.hi points at a language key the rules do not define',
        'rules.allowed_tokens: [emoji] is not a token category the validator knows',
        'rules.max_other_script_share must be a number from 0 to 1',
    )->and(fn () => lpPolicies()->propose($bad, $owner, 'x'))->toThrow(InvalidArgumentException::class, 'is not a known language key');
});

it('refuses activation by nobody, by a disabled person, by a person without the permission, and without a reason', function () {
    $owner = $this->hqUser('owner');
    $viewer = $this->hqUser('finance_viewer');
    $disabled = $this->hqUser('owner');
    $disabled->forceFill(['is_active' => false])->save();
    $v2 = lpPolicies()->propose(lpRomanisedForMixed(), $owner);

    expect(lpPolicies()->activationRefusals($v2, null, 'why'))->toContain('no person named: activation is the act of a named human (--by)')
        ->and(lpPolicies()->activationRefusals($v2, $disabled->fresh(), 'why'))->toContain("user {$disabled->email} is disabled")
        ->and(lpPolicies()->activationRefusals($v2, $viewer, 'why'))->toContain("user {$viewer->email} does not hold hq.policies.manage")
        ->and(lpPolicies()->activationRefusals($v2, $owner, '  '))->toBe(['no reason given (--reason)'])
        ->and(lpPolicies()->activationRefusals($v2, $owner, 'why'))->toBe([])
        ->and(lpPolicies()->activationRefusals(lpPolicies()->active(), $owner, 'why'))->toContain('version 1 is active, and only a proposed version can be activated');

    expect(fn () => lpPolicies()->activate($v2, $viewer, 'why'))->toThrow(RuntimeException::class, 'does not hold hq.policies.manage');
    expect($v2->fresh()->status)->toBe('proposed')->and(lpPolicies()->active()->version)->toBe(1);
});

it('activates through the audited command only: a dry run changes nothing, --apply under a named person retires version 1 and the mixed rule flips', function () {
    $owner = $this->hqUser('owner');
    $v2 = lpPolicies()->propose(lpRomanisedForMixed(), $owner, 'considering');

    $this->artisan('hq:language-policy:activate', ['version' => 2])
        ->expectsOutputToContain('no person named')
        ->expectsOutputToContain('dry run: nothing was changed')
        ->assertFailed();
    $this->artisan('hq:language-policy:activate', ['version' => 2, '--by' => $owner->email, '--reason' => 'the owner chose romanised Bangla for mixed messages'])
        ->expectsOutputToContain('it can be activated')
        ->expectsOutputToContain('mixed     -> bn in latin script')
        ->expectsOutputToContain('dry run: nothing was changed')
        ->assertSuccessful();
    expect(lpPolicies()->active()->version)->toBe(1);

    $this->artisan('hq:language-policy:activate', ['version' => 9, '--apply' => true])->expectsOutputToContain('There is no language policy version 9')->assertFailed();
    $this->artisan('hq:language-policy:activate', ['version' => 2, '--by' => 'nobody@example.com', '--reason' => 'x', '--apply' => true])->expectsOutputToContain('No HQ user has the email')->assertFailed();

    $this->artisan('hq:language-policy:activate', ['version' => 2, '--by' => $owner->email, '--reason' => 'the owner chose romanised Bangla for mixed messages', '--apply' => true])
        ->expectsOutputToContain('Version 2 is ACTIVE')
        ->assertSuccessful();

    $active = lpPolicies()->active();
    expect($active->version)->toBe(2)
        ->and($active->activated_by)->toBe($owner->id)
        ->and($active->activation_reason)->toBe('the owner chose romanised Bangla for mixed messages')
        ->and($active->content_digest)->toBe(LanguagePolicies::digestOf(lpRomanisedForMixed()['rules']))
        ->and(LanguagePolicy::where('version', 1)->sole()->status)->toBe('retired')
        ->and(LanguagePolicy::where('version', 1)->sole()->retired_by)->toBe($owner->id)
        ->and(LanguagePolicy::where('version', 1)->sole()->retirement_reason)->toContain('superseded by version 2')
        ->and(LanguagePolicyEvent::where('policy_id', $active->id)->pluck('event')->all())->toBe(['proposed', 'activated'])
        ->and(LanguagePolicyEvent::where('policy_id', LanguagePolicy::where('version', 1)->value('id'))->pluck('event')->all())->toBe(['activated', 'retired'])
        ->and((int) AuditLog::where('action', 'conversation.language_policy.activated')->sole()->actor_id)->toBe($owner->id);

    // The business rule changed by the activation and by nothing else: mixed -> Bangla in Latin script now.
    $mixed = lpPolicies()->requirementFor('banglish');
    expect($mixed->script)->toBe(LanguageRequirement::SCRIPT_LATIN)->and($mixed->language)->toBe('bn')->and($mixed->policyVersion)->toBe(2)
        ->and(lpPolicies()->requirementFor('bn')->script)->toBe(LanguageRequirement::SCRIPT_BENGALI);

    // Activating the retired version again is refused; the active one can be retired, and then nothing is required.
    expect(lpPolicies()->activationRefusals(LanguagePolicy::where('version', 1)->sole(), $owner, 'again'))->toContain('version 1 is retired, and only a proposed version can be activated');
    lpPolicies()->retire($active, $owner, 'withdrawn');
    expect(lpPolicies()->active())->toBeNull()
        ->and(lpPolicies()->requirementFor('banglish'))->toBeNull()
        ->and(lpPolicies()->reasonForNone('banglish'))->toContain('no language policy is active');
});

it('refuses, at the database, to edit or delete an activated version, to hold two active versions, and to touch an event row', function () {
    $owner = $this->hqUser('owner');
    $v1 = lpPolicies()->active();
    $v2 = lpPolicies()->propose(lpRomanisedForMixed(), $owner);

    // The active row's rules cannot be rewritten in place, and the row cannot be deleted.
    expect(lpRefused(fn () => DB::table('language_policies')->where('id', $v1->id)->update(['rules' => json_encode(['rules' => []])])))
        ->toThrow(QueryException::class, 'is active and immutable');
    expect(lpRefused(fn () => DB::table('language_policies')->where('id', $v1->id)->delete()))->toThrow(QueryException::class, 'kept forever');

    // A second active version, around the class: the partial unique index refuses it; and a proposed row
    // cannot be flipped to active without a person, a time, a reason and the digest.
    expect(lpRefused(fn () => DB::table('language_policies')->where('id', $v2->id)->update(['status' => 'active'])))
        ->toThrow(QueryException::class, 'activation needs a named person');
    expect(lpRefused(fn () => DB::table('language_policies')->where('id', $v2->id)->update([
        'status' => 'active', 'activated_by' => $owner->id, 'activated_at' => now(), 'activation_reason' => 'around the class', 'content_digest' => str_repeat('0', 64),
    ])))->toThrow(QueryException::class, 'language_policies_one_active');

    // The events are append-only.
    $event = LanguagePolicyEvent::where('policy_id', $v1->id)->sole();
    expect(lpRefused(fn () => DB::table('language_policy_events')->where('id', $event->id)->update(['reason' => 'x'])))->toThrow(QueryException::class, 'append-only');
    expect(lpRefused(fn () => DB::table('language_policy_events')->where('id', $event->id)->delete()))->toThrow(QueryException::class, 'append-only');

    expect(LanguagePolicy::where('status', 'active')->count())->toBe(1);
});
