<?php

namespace App\Tools;

use App\Support\Features\Features;
use Throwable;

/**
 * The tools whose feature flag is ON right now (config `hq.flagged_tools`, docs/144). Fail closed: when the flags cannot be read
 * (no database yet, an error) the answer is none, so an unreadable switch never adds a capability.
 */
final class FlaggedTools
{
    public function __construct(private readonly Features $features) {}

    /** @return list<class-string<Tool>> */
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
