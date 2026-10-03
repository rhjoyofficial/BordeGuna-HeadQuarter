<?php

namespace Database\Seeders;

use App\Conversation\Simulation\ScenarioLibrary;
use App\Evaluation\EvaluationDimension;
use App\Models\EvaluationCase;
use App\Models\EvaluationSuite;

/**
 * The mixed-script case versioned for the Support language contract (docs/92 A.7, stream W1).
 *
 * `sim.mixed.script.agent_active` failed P3-G2 attempts 3 and 4 the same way: the model answered a mixed
 * Bangla-and-English customer in romanised Bangla. The semantic requirement is UNCHANGED (mixed input ->
 * Bengali-script reply; docs/92 A.1 forbids weakening it); what version 2 adds is what the runtime now owns:
 * the case states the requirement the runtime must resolve (`language_requirement`), the reply is checked
 * by the same `ScriptPolicyValidator` the runtime enforces (so an order number, a URL, a phone number or a
 * brand name in Latin letters is not a false failure), and a reply refused for its script after the one
 * repair fails the case rather than passing as a hand-over.
 *
 * ⛔ Versioned, never edited: version 1 is RETIRED in place (its input, expectation and every result row of
 * attempts 1 to 4 kept; `retired_at`, the reason and `replaced_by_key` set; never evaluated again), exactly as
 * `SupportCoreHandoverCases` and `SupportAttemptFourCases` do. The scenario itself carries `version: 2`, so
 * `SupportBehaviourSuiteSeeder::simulationCases()` seeds `sim.v2.mixed.script.agent_active` as every other
 * case; this class only retires version 1 with the reason that belongs to it.
 */
final class SupportLanguageContractCases
{
    public const SCENARIO = 'mixed.script.agent_active';

    public const RETIRED_KEY = 'sim.mixed.script.agent_active';

    public const REPLACEMENT_KEY = 'sim.v2.mixed.script.agent_active';

    public const RETIREMENT_REASON = 'Case versioned for the Support language contract (docs/92 A.7, ChatGPT ruling of 27 September 2026, retired as '
        .'version 1). The requirement is unchanged: a mixed Bangla-and-English message is answered in Bengali script. Version 2, the same input, '
        .'adds what the runtime now owns: the resolved requirement (language bn, script bengali, from the active language policy) and a reply '
        .'check by the runtime\'s own ScriptPolicyValidator, which exempts order numbers, URLs, phone numbers and brand names. P3-G2 attempts 1 to 4 '
        .'keep pointing at this row; attempt 5 measures the replacement.';

    /** Retires version 1 in place. Called by the Support suite seeder, last, so the upsert above can never re-activate it. */
    public static function seedSupport(EvaluationSuite $suite): void
    {
        $scenario = ScenarioLibrary::find(self::SCENARIO);
        if ($scenario === null || $scenario->version < 2) {
            return;
        }

        // A fresh database records the retired version exactly as attempts 1 to 4 graded it.
        $row = EvaluationCase::firstOrCreate(
            ['suite_id' => $suite->id, 'key' => self::RETIRED_KEY],
            [
                'purpose' => 'Simulated thread: Bangla and English in one message, agent active: any reply carries Bangla script.',
                'input' => ['scenario' => $scenario->key, 'brand' => 'LLD', 'prompt' => 'Answer the customer on this thread.'],
                'expectation' => $scenario->legacySupportExpectation(),
                'grader' => 'deterministic',
                'severity' => 'blocker',
                'dimension' => EvaluationDimension::Reasoning->value,
                'applies_to_role' => null,
                'tags' => ['support', 'simulation', 'family:support_simulation', 'mixed', 'in_role'],
                'is_active' => false,
            ],
        );
        $row->forceFill([
            'is_active' => false,
            'retired_at' => $row->retired_at ?? now(),
            'retirement_reason' => self::RETIREMENT_REASON,
            'replaced_by_key' => self::REPLACEMENT_KEY,
        ])->save();
    }
}
