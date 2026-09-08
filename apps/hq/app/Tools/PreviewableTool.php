<?php

namespace App\Tools;

/**
 * A tool that can describe what it WOULD do without doing it.
 *
 * Implemented by tools whose effect can be computed or asked for without being applied: a shop's
 * checkout preview, a campaign budget calculation, a diff of what a write would change.
 *
 * ⚠ **A preview must not perform the external side effect.** If a tool cannot answer without acting,
 * it must not implement this interface, and its approval card will say plainly that no preview is
 * available. Filling that field with a live execution would defeat the purpose of asking a human
 * first, and would do it while looking reassuring.
 */
interface PreviewableTool
{
    /**
     * @param  array<string, mixed>  $payload  the VALIDATED arguments
     * @return array<string, mixed> a description of the intended effect, safe to show a human
     */
    public function preview(array $payload, ToolContext $context): array;
}
