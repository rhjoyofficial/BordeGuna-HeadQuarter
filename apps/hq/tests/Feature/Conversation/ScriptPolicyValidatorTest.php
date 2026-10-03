<?php

use App\Conversation\Language\LanguagePolicies;
use App\Conversation\Language\LanguageRequirement;
use App\Conversation\Language\ScriptPolicyResult;
use App\Conversation\Language\ScriptPolicyValidator;

/*
 | docs/92 A.3, A.4 (stream W1): the deterministic script validator on its own. No model, no provider, no
 | thread: a text, a requirement, a verdict, the same every time. It judges the human PROSE after the policy's
 | exempt tokens are taken out, so an order number, a URL, a phone number, an e-mail address, a SKU, a brand
 | name or a permitted product name in Latin letters never makes a Bangla reply "romanised".
 */

function spvRequirement(string $script = LanguageRequirement::SCRIPT_BENGALI, array $overrides = []): LanguageRequirement
{
    $rules = LanguagePolicies::V1['rules'];

    return new LanguageRequirement(
        language: $overrides['language'] ?? ($script === LanguageRequirement::SCRIPT_BENGALI ? 'bn' : 'en'),
        script: $script,
        policyVersion: 1,
        policyDigest: null,
        threadLanguage: $overrides['thread_language'] ?? 'banglish',
        rule: $overrides['rule'] ?? 'mixed',
        allowedTokens: $overrides['allowed_tokens'] ?? $rules['allowed_tokens'],
        brandNames: $overrides['brand_names'] ?? $rules['brand_names'],
        permittedTerms: $overrides['permitted_terms'] ?? $rules['permitted_terms'],
        maxOtherScriptShare: $overrides['max_other_script_share'] ?? $rules['max_other_script_share'],
    );
}

function spv(string $text, ?LanguageRequirement $requirement = null): ScriptPolicyResult
{
    return (new ScriptPolicyValidator)->validate($text, $requirement ?? spvRequirement());
}

// ── A.10 C and G: the wrong script is rejected ───────────────────────────────────────────────────────

it('rejects the romanised-Bangla reply P3-G2 attempts 3 and 4 produced, where Bengali script is required', function () {
    $result = spv('Dukkhoito, order-er obostha ekhane theke nischit korte parchi na. Amader ekjon colleague apnar bishoyti dekhe apnake janaben.');

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe(ScriptPolicyResult::OTHER_SCRIPT)
        ->and($result->detectedScript)->toBe(LanguageRequirement::SCRIPT_LATIN)
        ->and($result->requiredScript)->toBe(LanguageRequirement::SCRIPT_BENGALI)
        ->and($result->requiredLanguage)->toBe('bn')
        ->and($result->policyVersion)->toBe(1)
        ->and($result->diagnostics['required_script_words'])->toBe(0)
        ->and($result->diagnostics['other_script_words'])->toBeGreaterThan(10)
        ->and($result->explain())->toContain('requires Bengali script')->toContain('written in Latin script');
});

it('rejects an English reply where Bengali script is required, order number and all', function () {
    $result = spv('Your order LLD-20260912-QW3 is on its way. A colleague will confirm the date.');

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe(ScriptPolicyResult::OTHER_SCRIPT)
        ->and($result->detectedScript)->toBe(LanguageRequirement::SCRIPT_LATIN)
        ->and($result->diagnostics['exempted'])->toMatchArray(['order_id' => 1]);
});

// ── A.10 H: the reverse ──────────────────────────────────────────────────────────────────────────────

it('rejects a Bengali reply where Latin script is required', function () {
    $result = spv('আপনার অর্ডার পথে আছে। একজন সহকর্মী তারিখ নিশ্চিত করবেন।', spvRequirement(LanguageRequirement::SCRIPT_LATIN, ['thread_language' => 'en', 'rule' => 'en']));

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe(ScriptPolicyResult::OTHER_SCRIPT)
        ->and($result->detectedScript)->toBe(LanguageRequirement::SCRIPT_BENGALI)
        ->and($result->requiredScript)->toBe(LanguageRequirement::SCRIPT_LATIN);
});

// ── A.10 B and F: the right script is accepted, identifiers and brand names are not prose ────────────

it('accepts a Bengali reply, and one that carries every Latin token class the policy exempts', function () {
    expect(spv('দুঃখিত, অর্ডারের অবস্থা এখান থেকে নিশ্চিত করতে পারছি না। আমাদের একজন সহকর্মী আপনার বিষয়টি দেখে আপনাকে জানাবেন।')->valid)->toBeTrue();

    $result = spv('আপনার অর্ডার LLD-20260912-QW3 (Hilsa Pickle, SKU PIC-HIL-V1) LaLaDia থেকে পাঠানো হয়েছে। '
        .'ট্র্যাক করুন https://laladia.com/track/LLD-20260912-QW3 অথবা কল করুন 01334943783 বা লিখুন support@laladia.com। '
        .'Bionic Garden ও Bor de Guna একই পরিবারের। মোট ৳1,250, ২টি প্যাক।');

    expect($result->valid)->toBeTrue($result->explain())
        ->and($result->reason)->toBe(ScriptPolicyResult::VALID)
        ->and($result->detectedScript)->toBe(LanguageRequirement::SCRIPT_BENGALI)
        ->and($result->diagnostics['other_script_words'])->toBe(0)
        ->and($result->diagnostics['exempted'])->toMatchArray(['url' => 1, 'email' => 1, 'order_id' => 1, 'sku' => 1, 'phone' => 1, 'brand_name' => 3, 'permitted_term' => 1]);
});

it('accepts an English reply with Bangla brand names and a Bangla product name beside it, where Latin is required', function () {
    $latin = spvRequirement(LanguageRequirement::SCRIPT_LATIN, ['thread_language' => 'en', 'rule' => 'en']);

    expect(spv('Your order LLD-20260912-QW3 from লালাদিয়া is on its way; a colleague will confirm the date.', $latin)->valid)->toBeTrue()
        ->and(spv('Vaiya, hilsa pickle ta stock e ase, 500gm ৳650. Order korte chaile bolben.', $latin)->valid)->toBeTrue();
});

it('accepts a reply that is nothing but an identifier, since nothing in it is prose', function () {
    $result = spv('LLD-20260912-QW3');

    expect($result->valid)->toBeTrue()
        ->and($result->reason)->toBe(ScriptPolicyResult::NO_PROSE)
        ->and($result->detectedScript)->toBe(ScriptPolicyResult::DETECTED_NONE);
});

// ── The tolerance, and what it is for ────────────────────────────────────────────────────────────────

it('tolerates a Latin loanword inside Bengali prose up to the policy share, and rejects a reply that is half and half', function () {
    // One Latin word among many Bengali ones: a loanword, within v1's one-in-five.
    expect(spv('আপনার অর্ডারটি আজ courier এর কাছে দেওয়া হয়েছে, কাল পেয়ে যাবেন বলে আশা করছি।')->valid)->toBeTrue();

    // Bengali and English halves: more than the policy tolerates.
    $result = spv('আপনার অর্ডার এখনো pending, please wait for update');
    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe(ScriptPolicyResult::MIXED_SCRIPT)
        ->and($result->detectedScript)->toBe(ScriptPolicyResult::DETECTED_MIXED)
        ->and($result->diagnostics['other_script_share'])->toBeGreaterThan(0.2);

    // The same half-and-half reply under a policy with no tolerance at all: still rejected; under a permissive one: accepted.
    expect(spv('আপনার অর্ডার এখনো pending, please wait for update', spvRequirement(overrides: ['max_other_script_share' => 0.0]))->valid)->toBeFalse()
        ->and(spv('আপনার অর্ডার এখনো pending, please wait for update', spvRequirement(overrides: ['max_other_script_share' => 0.7]))->valid)->toBeTrue();
});

it('exempts only the token categories the policy allows, so a policy can narrow the exemptions', function () {
    $noBrands = spvRequirement(overrides: ['allowed_tokens' => ['order_id', 'url']]);

    // Without the brand exemption, three brand names (five Latin words) beside two Bengali words are the larger share.
    $result = spv('LaLaDia, Bionic Garden ও Bor de Guna পরিবার', $noBrands);
    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics['exempted'])->not->toHaveKey('brand_name');

    // With it, the same text is a Bengali reply.
    expect(spv('LaLaDia, Bionic Garden ও Bor de Guna পরিবার')->valid)->toBeTrue();
});

it('is deterministic, keeps no copy of the text, and reads a brand name inflected in Bangla as the Bangla word it is', function () {
    $text = 'Dukkhoito, order LLD-20260912-QW3 er khobor nei.';
    $first = spv($text)->toArray();
    $second = spv($text)->toArray();

    expect($first)->toEqual($second)
        ->and(json_encode($first, JSON_UNESCAPED_UNICODE))->not->toContain('Dukkhoito')->not->toContain('LLD-20260912-QW3')
        ->and(array_keys($first))->toBe(['valid', 'reason', 'policy_version', 'required_language', 'required_script', 'detected_script', 'diagnostics']);

    // "লালাদিয়ার" (LaLaDia's) is not the exempt brand token: it stays a Bengali word, which is what it is.
    $inflected = spv('লালাদিয়ার অর্ডার পথে আছে');
    expect($inflected->valid)->toBeTrue()
        ->and($inflected->diagnostics['exempted'])->not->toHaveKey('brand_name')
        ->and($inflected->diagnostics['required_script_words'])->toBe(4);
});
