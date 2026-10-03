<?php

namespace App\Conversation\Language;

/**
 * What `ScriptPolicyValidator` decided about one proposed reply (docs/92 A.4): valid or not, the one-word
 * reason, the policy version it was judged under, the requirement, the script the prose was found in,
 * and a diagnostic summary made of COUNTS (prose words per script, tokens exempted per category) and
 * never of the text, so a run's evidence and an audit row explain the verdict without keeping a copy of
 * what was going to be said to the customer.
 */
final readonly class ScriptPolicyResult
{
    public const VALID = 'valid';

    /** No human prose was left once the exempt tokens were removed: nothing is in the wrong script. */
    public const NO_PROSE = 'no_prose';

    /** The prose is written in a script other than the required one: romanised Bangla or English where Bengali is required, and the reverse. */
    public const OTHER_SCRIPT = 'other_script';

    /** The required script is present, and more of another script than the policy tolerates sits beside it. */
    public const MIXED_SCRIPT = 'mixed_script';

    public const DETECTED_NONE = 'none';

    public const DETECTED_MIXED = 'mixed';

    /** @param  array<string, mixed>  $diagnostics */
    public function __construct(
        public bool $valid,
        public string $reason,
        public ?int $policyVersion,
        public string $requiredLanguage,
        public string $requiredScript,
        public string $detectedScript,
        public array $diagnostics,
    ) {}

    /** The verdict in words, for a refusal shown to the model and for the audit trail. */
    public function explain(): string
    {
        $required = LanguageRequirement::scriptName($this->requiredScript);
        $version = $this->policyVersion === null ? 'the language policy' : "language policy v{$this->policyVersion}";

        return match ($this->reason) {
            self::VALID => "the reply's prose is in {$required}, as {$version} requires",
            self::NO_PROSE => "the reply holds no human prose outside the exempt tokens, so nothing in it is in the wrong script ({$version})",
            self::OTHER_SCRIPT => "{$version} requires {$required} on this thread and the reply's prose is written in "
                .($this->detectedScript === self::DETECTED_MIXED ? 'a mix of scripts with none of the required one' : LanguageRequirement::scriptName($this->detectedScript)),
            self::MIXED_SCRIPT => "{$version} requires {$required} on this thread and {$this->diagnostics['other_script_words']} of the reply's "
                ."{$this->diagnostics['prose_words']} prose words are in another script, more than the policy tolerates",
            default => $this->reason,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'reason' => $this->reason,
            'policy_version' => $this->policyVersion,
            'required_language' => $this->requiredLanguage,
            'required_script' => $this->requiredScript,
            'detected_script' => $this->detectedScript,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
