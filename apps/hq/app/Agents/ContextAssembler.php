<?php

namespace App\Agents;

use App\Context\ContextBlock;
use App\Context\ContextBundle;
use App\Execution\EffectiveExecutionContext;
use App\Gateway\OutcomeClass;
use App\Gateway\Scope\BusinessReferenceResolver;
use App\Models\Agent;
use App\Models\Business;
use App\Models\OwnerPreference;
use App\Playbooks\PlaybookLoader;

/**
 * Builds the context for one agent run, with every block labelled (docs/48 I.1, ADR-024).
 *
 * ## The table in docs/48 I.1 has no "yes" in its right-hand column, and this is where that holds
 *
 * Every block gets a `Provenance`. Nothing assembled here can change what is PERMITTED, because
 * permission is decided after the model by code that never reads any of these blocks. What
 * provenance does change is the autonomy ceiling: a bundle containing anything untrusted caps the
 * run at A1, and `AutonomyResolver` reads the label rather than the text, so no wording inside the
 * untrusted content can lift it.
 *
 * ⚠ **The operator's own turn is `Business`, not `System`.** An authenticated human's instruction is
 * trusted data from inside the group; it is still not an HQ instruction, and letting an operator's
 * sentence carry system authority would make "ignore your rules" work for anybody who could log in.
 *
 * ⚠ **A tool result is `Business` only because HQ's tools return HQ's own reads.** The moment a tool
 * returns text a customer wrote - a Phase 3 conversation, an order note, a review - that result must
 * be labelled `Customer` instead, and the block below marks the seam so the change is made
 * deliberately rather than by a result quietly flowing through the trusted path.
 */
final readonly class ContextAssembler
{
    public function __construct(
        private PromptLibrary $prompts,
        private BusinessReferenceResolver $references,
        private PlaybookLoader $playbooks,
    ) {}

    /** The system prompt: HQ's own instructions, and the only content that may define behaviour. */
    public function system(Agent $agent): string
    {
        return $this->prompts->system($agent->role_key ?? $agent->key);
    }

    /**
     * The opening bundle for a run, before any tool has been called.
     */
    public function open(EffectiveExecutionContext $context, string $question, ?Business $business, array $catalogue): ContextBundle
    {
        $agent = $context->agent;

        $blocks = [
            ContextBlock::system('agent:'.$agent->key, $this->identityBlock($agent, $business)),
            ContextBlock::system('tools:catalogue', $this->catalogueBlock($catalogue)),
            // ⭐ The names a business goes by and the slug a tool argument takes, resolved by HQ
            // and handed to the model as a lookup table, so "Bionic Garden" is never a guess at a
            // slug (docs/67). System provenance: it is HQ's own data about HQ's own businesses.
            // Built from the run's resolved context (ADR-047), so it lists what this run may read.
            ContextBlock::system('businesses:directory', $this->references->directory(
                $this->references->permittedBusinesses($context),
            )),
        ];

        if (($preferences = $this->preferences()) !== null) {
            // Preferences shape tone, length and what he likes to see. They are `Business` and not
            // `System` deliberately: a stored preference is data the group authored, and if one ever
            // said "always approve", it must not be able to mean it.
            $blocks[] = ContextBlock::business('memory:owner_preferences', $preferences);
        }

        // ⭐ docs/112 (S6): the business's own written guidance, read-only, by the run's business and brand
        //    key. One `System` framing block that says what a playbook is NOT (no permission, no policy, no
        //    consent, no autonomy), then one `Business` block per active playbook: data the group authored,
        //    the same standing as a stored preference, and like a preference unable to mean "always
        //    approve" because nothing that decides reads it. This call is the ONLY place the loader is
        //    reached from (PlaybookStructureTest). With nothing active the bundle is byte for byte what it
        //    was before playbooks existed; the record of what was (not) loaded, and why, travels as metadata
        //    on the question block, which renders nothing extra, so the runner never needs the loader itself.
        $playbooks = $this->playbooks->load($context->businessId, $context->brandId);
        foreach ($playbooks->blocks as $block) {
            $blocks[] = $block;
        }

        $blocks[] = ContextBlock::business('operator:question', $question, ['playbooks' => $playbooks->record]);

        return new ContextBundle($blocks);
    }

    /**
     * A tool result folded back into the context for the next turn.
     *
     * ⭐ Labelled with the tool and the call id, so a claim in the answer can be traced to the exact
     * row in `tool_calls` that produced it. "Where did this number come from" is the question an
     * audit asks months later, and `erp:bor-de-guna/overview` answers it while "a tool" does not.
     */
    public function toolResult(string $toolKey, ?int $toolCallId, array $output): ContextBlock
    {
        $source = $toolCallId === null ? "tool:{$toolKey}" : "tool:{$toolKey}#{$toolCallId}";

        // ⚠ SEAM: `business()` is correct for every tool in the M5 set, because all of them read
        // HQ's own tables, the ERP, or a shop's own order records. A Phase 3 tool that returns text
        // a CUSTOMER wrote must come back through `ContextBlock::customer()` instead - and that is a
        // decision made per tool, not a default that applies because it arrived the same way.
        return ContextBlock::business($source, $this->encode($output), ['tool' => $toolKey, 'tool_call_id' => $toolCallId]);
    }

    /** A refusal, folded back so the model can read the reason instead of guessing at it. */
    public function toolRefusal(string $toolKey, string $reason): ContextBlock
    {
        return ContextBlock::business("tool:{$toolKey}#refused", "The call to [{$toolKey}] did not run. Reason: {$reason}");
    }

    /**
     * A call HQ permitted that failed OUTSIDE HQ (ADR-043), in words that cannot be read as a
     * refusal: the model must not conclude it was told no, nor try the same target again by
     * another route, nor widen to other targets to make up for it.
     */
    public function toolFailure(string $toolKey, string $reason, OutcomeClass $class): ContextBlock
    {
        return ContextBlock::business("tool:{$toolKey}#failed",
            "HQ permitted the call to [{$toolKey}], and it did not succeed outside HQ ({$class->label()}). This is not a refusal. "
            .'Say so in your answer, do not retry it by another route, and do not widen to other businesses or stores in its place. '
            ."Reason: {$reason}");
    }

    private function identityBlock(Agent $agent, ?Business $business): string
    {
        $lines = [
            "You are the [{$agent->key}] agent. {$agent->name}.",
            $agent->purpose === null ? null : "Your purpose: {$agent->purpose}",
            $business === null
                ? 'This question is not limited to one business. Answer for every business you can read, and keep them separate.'
                : "This question is about the business [{$business->slug}] ({$business->name}). Do not answer it with another business's data.",
            'Today is '.now(config('hq.timezone', 'Asia/Dhaka'))->toDayDateTimeString().' ('.config('hq.timezone', 'Asia/Dhaka').').',
        ];

        return implode("\n", array_filter($lines));
    }

    private function catalogueBlock(array $catalogue): string
    {
        if ($catalogue === []) {
            return 'You have NO tools available in this run. You can only answer from what is already in '
                .'your context, and if that is not enough, say so and stop.';
        }

        return "These are the only tools you may name. Anything not listed here does not exist.\n\n"
            .json_encode($catalogue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function preferences(): ?string
    {
        $rows = OwnerPreference::query()->limit(50)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $lines = [];
        foreach ($rows as $row) {
            $value = is_array($row->value) ? json_encode($row->value, JSON_UNESCAPED_UNICODE) : (string) $row->value;
            $lines[] = "- {$row->key}: {$value}";
        }

        return "How the operator likes his answers:\n".implode("\n", $lines);
    }

    private function encode(array $output): string
    {
        return (string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
