<?php

namespace App\Playbooks;

/**
 * The canonical form of a playbook's text, its digest, its size cap and the advisory lint (docs/112 §4).
 *
 * ## The digest is over the content alone
 *
 * `sha256(kind \n title \n body)` with line endings normalised and the ends trimmed, so the same text
 * pasted from Windows and from a phone gives one digest, and so a run that recorded the digest can be
 * matched to the exact text months later, whatever happened to the row's timestamps or names.
 *
 * ## The lint is advice, not a control
 *
 * A playbook is read as `Business` data behind a framing block, and nothing that decides reads it at
 * all; that is the control. The lint only tells the reviewer when a line reads like an instruction to
 * the agent ("ignore", "you may now", a `[tool.key]`) rather than guidance to the business, because a
 * reviewer who sees "approve automatically" in a pricing playbook should ask why it is there. It never
 * blocks: a claims playbook legitimately says "never override the label".
 */
final class PlaybookContent
{
    /** @var list<array{pattern: string, note: string}> */
    private const INSTRUCTION_SHAPES = [
        ['pattern' => '/\bignore\s+(?:all\s+|any\s+|the\s+|your\s+)?(?:previous|prior|above|earlier|system|other)\b/iu', 'note' => 'reads as "ignore previous instructions"'],
        ['pattern' => '/\bdisregard\b/iu', 'note' => 'reads as an instruction to disregard something'],
        ['pattern' => '/\bsystem\s+prompt\b/iu', 'note' => 'names the system prompt'],
        ['pattern' => '/\byou\s+(?:may|can|are\s+allowed\s+to|are\s+authori[sz]ed\s+to)\s+now\b/iu', 'note' => 'reads as a grant ("you may now")'],
        ['pattern' => '/\b(?:without|no)\s+(?:an?\s+)?(?:approval|permission|consent)\b/iu', 'note' => 'reads as waiving an approval, permission or consent'],
        ['pattern' => '/\b(?:approve|send|execute|run|publish)\s+(?:it\s+|them\s+|this\s+)?automatically\b/iu', 'note' => 'reads as an instruction to act without a person'],
        ['pattern' => '/\bcall\s+(?:the\s+)?tool\b/iu', 'note' => 'reads as an instruction to call a tool'],
        ['pattern' => '/\[[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+\]/u', 'note' => 'contains a tool key in brackets'],
        ['pattern' => '/\boverride\s+(?:the\s+|any\s+|all\s+)?(?:policy|policies|rule|rules|gateway|consent|autonomy)\b/iu', 'note' => 'reads as overriding a policy or rule'],
        ['pattern' => '/\bautonomy\s+(?:level\s+)?A[0-4]\b/iu', 'note' => 'names an autonomy level'],
    ];

    public static function maxCharsPerVersion(): int
    {
        return (int) config('hq.playbooks.max_chars_per_version', 16000);
    }

    public static function maxCharsPerRun(): int
    {
        return (int) config('hq.playbooks.max_chars_per_run', 40000);
    }

    /** Line endings to `\n`, ends trimmed. What is stored, what is hashed, what the model reads. */
    public static function normalise(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    public static function digest(string $kind, string $title, string $body): string
    {
        return hash('sha256', $kind."\n".self::normalise($title)."\n".self::normalise($body));
    }

    /**
     * What is wrong with a draft's content; empty when nothing is.
     *
     * @return list<string>
     */
    public static function problems(string $title, string $body): array
    {
        $problems = [];
        if (self::normalise($title) === '') {
            $problems[] = 'title is missing';
        }
        $body = self::normalise($body);
        if ($body === '') {
            $problems[] = 'body is empty';
        }
        $max = self::maxCharsPerVersion();
        if (mb_strlen($body) > $max) {
            $problems[] = 'body is '.mb_strlen($body)." characters; the cap is {$max}";
        }

        return $problems;
    }

    /**
     * Lines that read as instructions to the agent rather than guidance to the business. Advisory.
     *
     * @return list<array{line: int, note: string, text: string}>
     */
    public static function warnings(string $body): array
    {
        $warnings = [];
        foreach (explode("\n", self::normalise($body)) as $i => $line) {
            foreach (self::INSTRUCTION_SHAPES as $shape) {
                if (preg_match($shape['pattern'], $line) === 1) {
                    $warnings[] = ['line' => $i + 1, 'note' => $shape['note'], 'text' => mb_substr(trim($line), 0, 160)];
                    break;
                }
            }
        }

        return $warnings;
    }
}
