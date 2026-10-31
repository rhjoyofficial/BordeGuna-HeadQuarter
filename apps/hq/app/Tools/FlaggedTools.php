<?php

namespace App\Tools;

use App\Support\Features\Features;
use Throwable;

/**
 * The flag-gated tools (config `hq.flagged_tools`, docs/144, ruling 31 Oct item 7).
 *
 * Applied ruling 31 Oct: every tool listed here is ALWAYS registered (its descriptor is in the canonical catalogue), and its
 * AVAILABILITY is its feature flag, read live on every check. With the flag off the gateway refuses the call with the typed
 * outcome `feature_disabled` (never "unknown tool"), and the agent toolset never lists it. A flag is one more AND in front of
 * permission, policy, consent, credentials and approval: it never replaces one of them, and switching it on grants no
 * permission, no agent, no autonomy grant and no approval.
 *
 * ⚠ The flag is read from the `feature_flags` table on every call (Features is never cached, docs/81 C.6), so a queue
 * worker that has lived since before a flag changed reads the current value on its next call. Nothing here memoises a
 * flag's state; the class only memoises the static map from a tool key to its flag, which is code, not state.
 */
final class FlaggedTools
{
    /** @var array<string, string>|null tool key => flag */
    private ?array $flagsByKey = null;

    public function __construct(private readonly Features $features) {}

    /**
     * Every flag-gated tool class, whatever its flag says. These are registered in the ToolRegistry unconditionally.
     *
     * @return list<class-string<Tool>>
     */
    public function all(): array
    {
        $classes = [];
        foreach ((array) config('hq.flagged_tools', []) as $tools) {
            array_push($classes, ...array_values((array) $tools));
        }

        return array_values(array_unique($classes));
    }

    /** The flag that gates this tool key, or null when the tool is not flag-gated. */
    public function flagFor(string $key): ?string
    {
        if ($this->flagsByKey === null) {
            $map = [];
            foreach ((array) config('hq.flagged_tools', []) as $flag => $tools) {
                foreach ((array) $tools as $class) {
                    $map[app($class)->key()] = (string) $flag;
                }
            }
            $this->flagsByKey = $map;
        }

        return $this->flagsByKey[$key] ?? null;
    }

    /**
     * Is the flag that gates this tool ON right now, for this business? A tool that is not flag-gated answers true (its
     * availability is decided elsewhere). Fail closed: an unreadable flag is off.
     */
    public function flagOn(string $key, ?int $businessId = null): bool
    {
        $flag = $this->flagFor($key);
        if ($flag === null) {
            return true;
        }
        try {
            return $this->features->enabled($flag, $businessId);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The tool classes whose flag is ON right now (kept for screens that list them). Fail closed.
     *
     * @return list<class-string<Tool>>
     */
    public function enabled(): array
    {
        $classes = [];
        foreach ((array) config('hq.flagged_tools', []) as $flag => $tools) {
            try {
                $on = $this->features->enabled((string) $flag);
            } catch (Throwable) {
                $on = false;
            }
            if ($on) {
                array_push($classes, ...$tools);
            }
        }

        return $classes;
    }
}
