<?php

namespace App\Conversation\Language;

use App\Conversation\Language;
use App\Models\LanguagePolicy;
use App\Models\LanguagePolicyEvent;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\GroupWideAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The one writer and the one reader of customer language policy versions (docs/92 A.8).
 *
 * A policy version maps the language the conversation core recorded on a thread to the language and
 * the script a reply must be written in, and declares what the script check exempts. It is a business
 * decision written as data: the owner may later answer a mixed Bangla-and-English customer in romanised
 * Bangla by activating a new version, and nothing in the runtime changes for it (docs/92 A.1: business
 * policy is not the enforcement mechanism). Version 1 is the rule that stood before this class existed
 * (mixed Bangla and English is answered in Bengali script) and is seeded ACTIVE by its migration.
 *
 * Mirrors `App\Autonomy\Evidence\EvidencePolicies`: `propose()` writes a version as data and not in
 * force; `activate()` is the explicit, audited act of a named, enabled person holding
 * `hq.policies.manage`, with a reason, retiring the previous version in the same transaction;
 * `retire()` withdraws the active one. Every act is an append-only event row and an audit row. The
 * database refuses the same things again underneath (`language_policies` triggers on pgsql).
 *
 * ⛔ The model never chooses the policy, and neither does a prompt: `requirementFor()` is called by the
 * conversation core (`SupportContext`) with the thread's recorded language, and the result travels to
 * the runtime as a trusted fact.
 */
final class LanguagePolicies
{
    public const PERMISSION = 'hq.policies.manage';

    /** The thread tags the conversation core records (`Language::detect`), and the key `unknown` for none. */
    public const THREAD_TAGS = [Language::BANGLA, Language::ENGLISH, Language::BANGLISH];

    /** The policy's own language keys: the core's three tags read as what they mean, plus romanised Bangla on its own. */
    public const RULE_KEYS = ['bn', 'en', 'mixed', 'banglish'];

    /**
     * Version 1: the standing rule, as docs/92 A.1 states it. ⚠ The same rules are written, literally,
     * in the migration that seeds the version; the test suite holds the two digests equal.
     *
     * - `bn` (Bangla in Bengali script) -> Bangla, Bengali script
     * - `en` (English) -> English, Latin script
     * - `mixed` (Bangla and English in one message; the core tags it `banglish`) -> Bangla, Bengali script
     * - `banglish` (romanised Bangla alone) -> Bangla, Latin script. Today the core tags such a message
     *   `en` (Latin letters only), so this rule is reached only by an explicit thread tag; it is here so
     *   the policy can express the choice without a code change.
     * - `unknown` (no script detected: a sticker, an attachment) -> no requirement; nothing is checked.
     */
    public const V1 = [
        'name' => 'Customer reply script, v1',
        'summary' => 'The rule that stood before docs/92: Bangla in Bengali script, English in Latin script, a mixed Bangla-and-English message '
            .'answered in Bengali script, romanised Bangla answered as written. Owner-controlled; a new version changes it.',
        'rules' => [
            'rules' => [
                'bn' => ['language' => 'bn', 'script' => 'bengali'],
                'en' => ['language' => 'en', 'script' => 'latin'],
                'mixed' => ['language' => 'bn', 'script' => 'bengali'],
                'banglish' => ['language' => 'bn', 'script' => 'latin'],
            ],
            'thread_tags' => ['bn' => 'bn', 'en' => 'en', 'banglish' => 'mixed'],
            'allowed_tokens' => ['order_id', 'sku', 'url', 'email', 'phone', 'number', 'brand_name', 'permitted_term', 'identifier'],
            'brand_names' => ['LaLaDia', 'Bionic Garden', 'Bor de Guna', 'Bôr dé Gûna', 'লালাদিয়া', 'বায়োনিক গার্ডেন', 'বর দে গুনা'],
            'permitted_terms' => ['Hilsa Pickle', 'Hilsa', 'Pink Salt', 'Mustard Oil', 'Ghee', 'Messenger', 'WhatsApp', 'Facebook', 'Instagram'],
            'max_other_script_share' => 0.2,
        ],
    ];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly GroupWideAuthority $authority,
    ) {}

    public function active(): ?LanguagePolicy
    {
        return LanguagePolicy::query()->where('status', LanguagePolicy::ACTIVE)->first();
    }

    /**
     * The reply requirement for a thread, from the active policy and the language the core recorded on
     * it: null when no policy is active, when the thread carries no detected script, or when the active
     * policy maps the tag to nothing. The runtime checks nothing in that case and records why.
     */
    public function requirementFor(?string $threadLanguage, ?LanguagePolicy $policy = null): ?LanguageRequirement
    {
        $policy ??= $this->active();
        if ($policy === null || $threadLanguage === null) {
            return null;
        }

        $rules = (array) $policy->rules;
        $key = $rules['thread_tags'][$threadLanguage] ?? null;
        $rule = is_string($key) ? ($rules['rules'][$key] ?? null) : null;
        if (! is_array($rule) || ! is_string($rule['language'] ?? null) || ! in_array($rule['script'] ?? null, LanguageRequirement::SCRIPTS, true)) {
            return null;
        }

        return $this->requirement($policy, $rule['language'], $rule['script'], $threadLanguage, $key);
    }

    /**
     * Why `requirementFor()` answered null, in words, for the run's record and the context the model reads.
     */
    public function reasonForNone(?string $threadLanguage, ?LanguagePolicy $policy = null): string
    {
        $policy ??= $this->active();

        return match (true) {
            $policy === null => 'no language policy is active, so no reply script is required',
            $threadLanguage === null => 'no script was detected on the thread (no text yet), so no reply script is required',
            default => "the active language policy v{$policy->version} maps the thread's language tag [{$threadLanguage}] to no requirement",
        };
    }

    /**
     * The requirement a CASE states by script alone (`reply_script: bangla|latin`), for the grader, so the
     * measurement and the runtime read the same validator with the same exemptions. With no active policy
     * the exemptions are version 1's and the version is null: the case is still measured.
     */
    public function requirementForScript(string $script): LanguageRequirement
    {
        $policy = $this->active();
        $script = $script === 'bangla' ? LanguageRequirement::SCRIPT_BENGALI : ($script === 'latin' ? LanguageRequirement::SCRIPT_LATIN : $script);

        return $this->requirement($policy, $script === LanguageRequirement::SCRIPT_BENGALI ? 'bn' : 'en', $script, null, null);
    }

    /**
     * A new proposed version, as data and not in force. Shape: `{"name", "summary", "rules": {"rules":
     * {key: {language, script}}, "thread_tags": {tag: key}, "allowed_tokens": [...], "brand_names": [...],
     * "permitted_terms": [...], "max_other_script_share": 0.0-1.0}}`.
     *
     * @param  array<string, mixed>  $definition
     */
    public function propose(array $definition, ?User $by = null, ?string $reason = null): LanguagePolicy
    {
        $problems = $this->definitionProblems($definition);
        if ($problems !== []) {
            throw new InvalidArgumentException('The language policy definition is not valid: '.implode('; ', $problems));
        }

        return DB::transaction(function () use ($definition, $by, $reason) {
            $policy = LanguagePolicy::create([
                'uuid' => (string) Str::uuid(),
                'version' => ((int) LanguagePolicy::query()->max('version')) + 1,
                'name' => (string) $definition['name'],
                'summary' => isset($definition['summary']) ? (string) $definition['summary'] : null,
                'status' => LanguagePolicy::PROPOSED,
                'rules' => $this->canonical((array) $definition['rules']),
                'supersedes_id' => $this->active()?->id,
                'proposed_by' => $by?->getKey(),
                'proposed_at' => now(),
            ]);

            $this->event($policy, 'proposed', $by, $reason);
            $this->auditor->record('conversation.language_policy.proposed', $policy, after: $this->snapshot($policy), reason: $reason, actorUserId: $by?->getKey());

            return $policy;
        });
    }

    /**
     * Every reason this version cannot become active, for this person, now. Empty means it can.
     *
     * @return list<string>
     */
    public function activationRefusals(LanguagePolicy $policy, ?User $by, ?string $reason): array
    {
        $refusals = [];

        if ($policy->status !== LanguagePolicy::PROPOSED) {
            $refusals[] = "version {$policy->version} is {$policy->status}, and only a proposed version can be activated";
        }

        if ($by === null) {
            $refusals[] = 'no person named: activation is the act of a named human (--by)';
        } elseif (! $by->is_active) {
            $refusals[] = "user {$by->email} is disabled";
        } elseif (! $by->hasPermissionTo(self::PERMISSION)) {
            $refusals[] = "user {$by->email} does not hold ".self::PERMISSION;
        } elseif (($groupWide = $this->authority->refusal($by, self::PERMISSION)) !== null) {
            // docs/138 ruling 13: the one active version acts on every business.
            $refusals[] = $groupWide;
        }

        if (trim((string) $reason) === '') {
            $refusals[] = 'no reason given (--reason)';
        }

        return [...$refusals, ...array_map(static fn (string $p) => 'rules: '.$p, $this->definitionProblems(['name' => $policy->name, 'rules' => (array) $policy->rules]))];
    }

    public function activate(LanguagePolicy $policy, ?User $by, ?string $reason): LanguagePolicy
    {
        $refusals = $this->activationRefusals($policy, $by, $reason);
        if ($refusals !== []) {
            throw new RuntimeException('Not activated: '.implode('; ', $refusals));
        }

        return DB::transaction(function () use ($policy, $by, $reason) {
            $previous = $this->active();
            if ($previous !== null) {
                $this->retireRow($previous, $by, "superseded by version {$policy->version}: {$reason}");
            }

            $policy->forceFill([
                'status' => LanguagePolicy::ACTIVE,
                'supersedes_id' => $previous?->id ?? $policy->supersedes_id,
                'activated_by' => $by->getKey(),
                'activated_at' => now(),
                'activation_reason' => $reason,
                'content_digest' => $this->digest($policy),
            ])->save();

            $policy->refresh();
            $this->event($policy, 'activated', $by, $reason);
            $this->auditor->record('conversation.language_policy.activated', $policy,
                before: ['active_version' => $previous?->version], after: $this->snapshot($policy), reason: $reason, actorUserId: $by->getKey(),
                context: $this->authority->auditContext(self::PERMISSION, $by));

            return $policy;
        });
    }

    /** The active version withdrawn by a named person with a reason; nothing is active afterwards and nothing is checked. */
    public function retire(LanguagePolicy $policy, ?User $by, ?string $reason): LanguagePolicy
    {
        return DB::transaction(function () use ($policy, $by, $reason) {
            $fresh = LanguagePolicy::query()->lockForUpdate()->find($policy->getKey());

            $refusals = [];
            if ($fresh === null) {
                $refusals[] = "version {$policy->version} does not exist";
            } elseif ($fresh->status !== LanguagePolicy::ACTIVE) {
                $refusals[] = "version {$fresh->version} is {$fresh->status}, and only the active version can be retired";
            }
            if ($by === null) {
                $refusals[] = 'no person named (--by)';
            } elseif (! $by->is_active) {
                $refusals[] = "user {$by->email} is disabled";
            } elseif (! $by->hasPermissionTo(self::PERMISSION)) {
                $refusals[] = "user {$by->email} does not hold ".self::PERMISSION;
            } elseif (($groupWide = $this->authority->refusal($by, self::PERMISSION)) !== null) {
                // docs/138 ruling 13: the one active version acts on every business.
                $refusals[] = $groupWide;
            }
            if (trim((string) $reason) === '') {
                $refusals[] = 'no reason given (--reason)';
            }
            if ($refusals !== []) {
                throw new RuntimeException('Not retired: '.implode('; ', $refusals));
            }

            $this->retireRow($fresh, $by, $reason);
            $this->auditor->record('conversation.language_policy.retired', $fresh, before: ['status' => 'active'], after: ['status' => 'retired'], reason: $reason, actorUserId: $by->getKey(),
                context: $this->authority->auditContext(self::PERMISSION, $by));

            return $fresh;
        });
    }

    /** @return array<string, mixed> the version as data, in canonical order: what the digest is taken over and an event row snapshots */
    public function snapshot(LanguagePolicy $policy): array
    {
        return ['version' => $policy->version, 'name' => $policy->name, 'summary' => $policy->summary, 'rules' => $this->canonical((array) $policy->rules)];
    }

    public function digest(LanguagePolicy $policy): string
    {
        return self::digestOf((array) $policy->rules);
    }

    /** sha256 over the canonical JSON of the rules alone, so the same rules in any key order give one digest. */
    public static function digestOf(array $rules): string
    {
        return hash('sha256', (string) json_encode(self::canonical($rules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * What is wrong with a definition, field by field; empty when nothing is.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function definitionProblems(array $definition): array
    {
        $problems = [];
        if (trim((string) ($definition['name'] ?? '')) === '') {
            $problems[] = 'name is missing';
        }
        $rules = $definition['rules'] ?? null;
        if (! is_array($rules)) {
            return [...$problems, 'rules is missing'];
        }

        $map = $rules['rules'] ?? null;
        if (! is_array($map) || $map === []) {
            $problems[] = 'rules.rules names no language';
        } else {
            foreach ($map as $key => $rule) {
                if (! in_array($key, self::RULE_KEYS, true)) {
                    $problems[] = "rules.rules.{$key} is not a known language key (".implode(', ', self::RULE_KEYS).')';
                }
                if (! is_array($rule) || ! is_string($rule['language'] ?? null) || trim($rule['language']) === '') {
                    $problems[] = "rules.rules.{$key}: language is missing";
                }
                if (! is_array($rule) || ! in_array($rule['script'] ?? null, LanguageRequirement::SCRIPTS, true)) {
                    $problems[] = "rules.rules.{$key}: script must be one of ".implode(', ', LanguageRequirement::SCRIPTS);
                }
            }
        }

        $tags = $rules['thread_tags'] ?? null;
        if (! is_array($tags)) {
            $problems[] = 'rules.thread_tags is missing';
        } else {
            foreach ($tags as $tag => $key) {
                if (! in_array($tag, self::THREAD_TAGS, true)) {
                    $problems[] = "rules.thread_tags.{$tag} is not a tag the conversation core records (".implode(', ', self::THREAD_TAGS).')';
                }
                if (! is_string($key) || ! isset($map[$key])) {
                    $problems[] = "rules.thread_tags.{$tag} points at a language key the rules do not define";
                }
            }
        }

        foreach ((array) ($rules['allowed_tokens'] ?? []) as $token) {
            if (! in_array($token, ScriptPolicyValidator::TOKEN_CATEGORIES, true)) {
                $problems[] = "rules.allowed_tokens: [{$token}] is not a token category the validator knows";
            }
        }
        foreach (['brand_names', 'permitted_terms'] as $list) {
            if (isset($rules[$list]) && (! is_array($rules[$list]) || array_filter($rules[$list], static fn ($t) => ! is_string($t)) !== [])) {
                $problems[] = "rules.{$list} must be a list of strings";
            }
        }
        $share = $rules['max_other_script_share'] ?? null;
        if (! is_numeric($share) || (float) $share < 0.0 || (float) $share > 1.0) {
            $problems[] = 'rules.max_other_script_share must be a number from 0 to 1';
        }

        return $problems;
    }

    private function requirement(?LanguagePolicy $policy, string $language, string $script, ?string $threadLanguage, ?string $rule): LanguageRequirement
    {
        $rules = $policy === null ? self::V1['rules'] : (array) $policy->rules;

        return new LanguageRequirement(
            language: $language,
            script: $script,
            policyVersion: $policy?->version,
            policyDigest: $policy?->content_digest,
            threadLanguage: $threadLanguage,
            rule: $rule,
            allowedTokens: array_values(array_filter((array) ($rules['allowed_tokens'] ?? []), 'is_string')),
            brandNames: array_values(array_filter((array) ($rules['brand_names'] ?? []), 'is_string')),
            permittedTerms: array_values(array_filter((array) ($rules['permitted_terms'] ?? []), 'is_string')),
            maxOtherScriptShare: (float) ($rules['max_other_script_share'] ?? 0.0),
        );
    }

    /** @return array<string, mixed> the rules with their keys sorted at every level, lists kept in order */
    private static function canonical(array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $value) {
            $out[$key] = is_array($value) && ! array_is_list($value) ? self::canonical($value) : $value;
        }
        ksort($out);

        return $out;
    }

    private function retireRow(LanguagePolicy $policy, User $by, string $reason): void
    {
        $policy->forceFill([
            'status' => LanguagePolicy::RETIRED,
            'retired_by' => $by->getKey(),
            'retired_at' => now(),
            'retirement_reason' => $reason,
        ])->save();

        $policy->refresh();
        $this->event($policy, 'retired', $by, $reason);
    }

    private function event(LanguagePolicy $policy, string $event, ?User $by, ?string $reason): void
    {
        LanguagePolicyEvent::create([
            'policy_id' => $policy->id,
            'event' => $event,
            'actor_user_id' => $by?->getKey(),
            'actor_label' => $by?->email ?? 'system',
            'reason' => $reason,
            'snapshot' => $this->snapshot($policy),
            'content_digest' => $this->digest($policy),
            'occurred_at' => now(),
        ]);
    }
}
