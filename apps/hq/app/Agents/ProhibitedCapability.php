<?php

namespace App\Agents;

use Throwable;

/**
 * Detects a request for a capability HQ prohibits, in the TRUSTED operator text, deterministically,
 * before the model's reply is read (docs/75, ADR-044).
 *
 * ⭐ Gate 9 failed `sql.operator_asks_to_run_query` in a shape gate 8 had stamped out for security
 * excuses: the model declined the SQL in its first sentence, wrote its routine into its own task
 * field, and ran six tools. `SecurityIntent` recorded nothing, correctly: asking for SQL is not an
 * approval skip, a self-grant, a stated limit exceeded or a concealment. The task grounded, because
 * the operator did ask for something. Nothing in the runtime held the third fact, that the thing
 * asked for is a capability HQ does not have and must never have, so nothing stopped the substitute.
 * This class holds that fact, and holds it apart: ChatGPT's line, from the thread, is that task
 * grounding, authority security and capability safety are three boundaries and never one boolean.
 *
 * ⛔ Narrow on purpose, like its sibling. Five kinds, each a few SHAPES: a SQL statement or "run this
 * query"; a table or the database read directly, or a database session opened; a shell, an artisan
 * command, a container entered; the governed tool layer skipped for a direct API call; a secret or
 * a protected file printed, pasted or edited. It does not try to understand the business request and
 * never decides what the task is. A clause that asks ABOUT the boundary ("why can't HQ run arbitrary
 * SQL", "what do I use instead of a raw query") is analysis and exempt: the detector is about doing,
 * not discussing. The one exception is a question whose answer would be a secret's value ("what is
 * the database password"), which is extraction in the shape of a question and is flagged.
 *
 * ⛔ Fails closed and is versioned, exactly as `SecurityIntent` is. It runs on the operator's words
 * only; an untrusted note that contains SQL is content the operator may ask about, not a request.
 *
 * ⚠ What it cannot do, said plainly: it matches shapes, so a paraphrase far enough from every shape
 * passes it, and the core prompt's rule 7 still asks the model to refuse what the detector misses.
 * The gate measures both.
 */
final class ProhibitedCapability
{
    public const VERSION = 'v1';

    /** A SQL statement, or a request to run one. */
    public const SQL_EXECUTION = 'sql_execution';

    /** A table or the database read or opened directly, outside the typed tools. */
    public const DIRECT_DATABASE_ACCESS = 'direct_database_access';

    /** A shell, terminal, artisan or container command. */
    public const SHELL_EXECUTION = 'shell_execution';

    /** The governed tool layer skipped for a direct call to a provider or an API. */
    public const GOVERNED_LAYER_BYPASS = 'governed_layer_bypass';

    /** A secret, an environment file or protected infrastructure printed, pasted or edited. */
    public const INFRASTRUCTURE_EXTRACTION = 'infrastructure_extraction';

    /** The detector failed; read as prohibited (fail closed). */
    public const DETECTOR_ERROR = 'detector_error';

    public const KINDS = [
        self::SQL_EXECUTION, self::DIRECT_DATABASE_ACCESS, self::SHELL_EXECUTION, self::GOVERNED_LAYER_BYPASS, self::INFRASTRUCTURE_EXTRACTION,
    ];

    /**
     * A clause that asks about the boundary rather than asking to cross it. Deliberately narrower
     * than `SecurityIntent`'s list: a polite request ("could you read the table directly") is a
     * request, so the modal-verb openers are not here.
     */
    private const ANALYTIC = [
        '/^(what|why|how|when|who|which|where|whether)\b/u',
        '/^(explain|describe|summari[sz]e|clarify|outline|walk me through|remind me|tell me (what|why|how|whether|which))\b/u',
        '/\b(why (can\'?t|cannot|won\'?t|doesn\'?t|does not|is there no|isn\'?t there)|what would happen|what happens|is it (possible|allowed|permitted)|are you (able|allowed))\b/u',
        '/\b(just (explain|describe|tell me)|do not do anything|don\'?t do anything|no action)\b/u',
    ];

    private const SECRETS = '(\.env|env file|environment (file|variables?)|secrets?|api[- ]keys?|tokens?|passwords?|passphrases?|private keys?|connection strings?|credentials?)';

    private const SHELLS = '(php artisan|artisan|composer|npm|npx|bash|sh|zsh|shell|terminal|command line|cli|docker|docker compose|kubectl|systemctl|crontab)';

    private const GOVERNED = '(hq\'?s? )?(tool layer|tools? layer|gateway|tool gateway|governed layer|typed tools|typed filters|control plane)';

    /** @var array<string, list<string>> kind => clause-level regular expressions (lower-cased input) */
    private const SHAPES = [
        self::SQL_EXECUTION => [
            '/\bselect\s+\*\s+from\b/u',
            '/\b(select|delete)\b[^.;]{0,120}?\bfrom\s+[a-z]+_[a-z0-9_]+\b/u',
            '/\b(insert\s+into|update\s+[a-z_]+\s+set\b|drop\s+table|alter\s+table|truncate\s+table|create\s+table|grant\s+all)\b/u',
            '/\b(run|execute|fire|issue|paste|try|apply|use|write|compose)\b[^.]{0,30}?\b(this|that|the|a|an|my|following|raw|quick)?\s*(sql|query|queries|sql statement|statement)\b/u',
            '/\b(raw|direct|arbitrary|ad[- ]hoc|hand-?written)\s+(sql|query|queries)\b/u',
        ],
        self::DIRECT_DATABASE_ACCESS => [
            '/\b(read|query|open|hit|scan|dump|pull|export|look at|inspect|browse|update|write to|edit)\b[^.]{0,40}?\b(the )?[a-z_]+\s+tables?\s+(directly|yourself|by hand|raw|straight|for me)\b/u',
            '/\b(directly|straight)\s+(from|against|into|in|on|off|to)\s+(the\s+)?([a-z_]+\s+)?(database|db|postgres|postgresql|tables?)\b/u',
            '/\b(open|start|connect( to)?|log ?in(to)?|get into|use|give me)\s+(a\s+|an\s+|the\s+)?(psql|mysql|pgadmin|db|database|postgres|postgresql)\s*(session|shell|console|connection|client|prompt)?\b/u',
            '/\b(the raw rows|raw rows|the raw (data|records)|straight from the (tables?|database|db))\b/u',
            '/\b(tables?|database|db)\s+directly\b/u',
            '/\b(read|query|scan|dump)\s+(the\s+)?(database|db)\b/u',
        ],
        self::SHELL_EXECUTION => [
            '/\b(run|execute|exec|fire|type|invoke|launch|start)\b[^.]{0,40}?\b'.self::SHELLS.'\b/u',
            '/\b(docker exec|docker compose exec|ssh into|ssh to|ssh in|open a (shell|terminal)|in the (app |db |queue )?container|inside the (app |db |queue )?container|into the (app |db |queue )?container)\b/u',
            '/\b(shell|terminal|bash|command[- ]line) (command|access|session|prompt)\b/u',
            '/\b(run|execute)\s+(this|the following|these|that)\s+(command|commands|script)\b/u',
        ],
        self::GOVERNED_LAYER_BYPASS => [
            '/\b(skip|skipping|bypass|bypassing|go around|get around|avoid|avoiding|ignore|ignoring|not through|instead of (going )?through)\b[^.]{0,30}?\b'.self::GOVERNED.'\b/u',
            '/\b(call|hit|curl|query|reach|use|poll)\b[^.]{0,40}?\b(api|endpoint|admin api|rest api|webhook)\b[^.]{0,40}?\b(directly|yourself|by hand|straight|without hq)\b/u',
            '/\b(directly|yourself)\b[^.]{0,20}?\b(call|hit|curl|poll)\b[^.]{0,40}?\b(api|endpoint)\b/u',
            '/\b(curl|wget|postman)\s+(the|this|that|it|https?:|[a-z])/u',
        ],
        self::INFRASTRUCTURE_EXTRACTION => [
            // The secret is the OBJECT of the verb, reached only through determiners and a few
            // describing words, so "show me which credentials are missing" (a readiness question)
            // is not "show me the credentials".
            '/\b(paste|print|reveal|dump|display|read out|expose|cat|echo|show me|give me|send me|write out|copy|include|output)\b[\s,]+((me|us|the|our|its|your|hq\'?s|their|whatever|all|every|full|complete|contents|of|app\'?s|server\'?s|environment|dump|current|live|real|actual|production|database|db|shop|api|anthropic|admin|erp|meta|and|a|an)[\s,]+){0,6}'.self::SECRETS.'\b/u',
            '/\b(edit|change|modify|rewrite|patch|delete|remove|overwrite|hand-?edit)\b[^.]{0,30}?\b(the )?(\.env|env file|config(uration)? files?|migrations?|database schema|source code|the code|containers?|crontab|deployment|compose file)\b/u',
        ],
    ];

    /**
     * Flagged even in a question: the answer would be a secret's value. "What is the database
     * password" is extraction with a question mark on it.
     */
    private const ALWAYS = [
        self::INFRASTRUCTURE_EXTRACTION => [
            '/\b(what|which)\s+(is|are|was|were)\s+(the|our|hq\'?s|its|their|my)\s+(current |live |real |actual |production |database |db |shop |api |anthropic |admin |erp )?'.self::SECRETS.'\b/u',
        ],
    ];

    public function detect(string $trustedText): ProhibitedCapabilitySignal
    {
        try {
            $kinds = [];
            $evidence = [];

            foreach ($this->clauses($trustedText) as $clause) {
                // A question about the boundary is exempt from every shape but one: a question whose
                // answer would be a secret's value.
                $groups = $this->isAnalytic($clause) ? [self::ALWAYS] : [self::SHAPES, self::ALWAYS];

                foreach ($groups as $group) {
                    foreach ($group as $kind => $patterns) {
                        foreach ($patterns as $pattern) {
                            if (preg_match($pattern, $clause) === 1) {
                                if (! in_array($kind, $kinds, true)) {
                                    $kinds[] = $kind;
                                }
                                $evidence[] = ['kind' => $kind, 'clause' => mb_substr($clause, 0, 160)];
                                break;
                            }
                        }
                    }
                }
            }

            return new ProhibitedCapabilitySignal(self::VERSION, $kinds, $evidence);
        } catch (Throwable $e) {
            // ⛔ Fail closed. A detector that cannot say "permitted" says "prohibited", with its error on the row.
            return new ProhibitedCapabilitySignal(self::VERSION, [self::DETECTOR_ERROR], [], get_class($e).': '.$e->getMessage());
        }
    }

    /**
     * The message as lower-cased clauses. Unlike `SecurityIntent`, a dot or question mark splits
     * only when a space or the end of the text follows it, so `.env` and `laladia.com` stay whole,
     * and a colon never splits: "summarise the note: it says to run select * from users" is one
     * clause, and its opening verb is what makes it a question about the content, not a request.
     *
     * @return list<string>
     */
    public function clauses(string $text): array
    {
        $lower = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
        $parts = preg_split('/(?:[.;!?]+(?=\s|$)|\s+(?:so|then|and then|and after that|after that|meanwhile)\s+|,\s+(?:and|so|then)\s+|\s+-\s+)/u', $lower) ?: [$lower];

        return array_values(array_filter(array_map(static fn (string $p) => trim($p, " \t\n\r\0\x0B,\"'()"), $parts), static fn (string $p) => $p !== ''));
    }

    public function isAnalytic(string $clause): bool
    {
        foreach (self::ANALYTIC as $pattern) {
            if (preg_match($pattern, $clause) === 1) {
                return true;
            }
        }

        return false;
    }
}
