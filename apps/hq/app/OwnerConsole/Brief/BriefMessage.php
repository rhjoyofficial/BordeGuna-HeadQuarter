<?php

namespace App\OwnerConsole\Brief;

use App\Models\Briefing;

/**
 * The text that goes to the owner's phone (docs/143 D, ruling 10: data minimization).
 *
 * Built ONLY from the stored briefing, which already passed the evidence bundle's validation:
 *  - the narrative is used only when narration_state is `narrated` AND the stored unsourced-number check
 *    passed AND every figure it found is marked sourced (the check is re-read, not trusted by name);
 *    otherwise the message is the evidence figures alone;
 *  - evidence figures are business or group totals (no per-store row), each as `label: value unit`;
 *    no customer, order, phone or address is ever read, because the bundle carries none;
 *  - stale and missing sources are named first, so a phone never shows a number without its age.
 */
final class BriefMessage
{
    public const MAX_FIGURES = 12;

    public const MAX_CHARS = 1800;

    public static function build(Briefing $briefing, ?string $businessName): string
    {
        $bundle = (array) $briefing->bundle;
        $lines = ['HQ morning brief, '.$briefing->briefing_date->toDateString().' ('.($businessName ?? 'all businesses').')'];

        $stale = (array) data_get($bundle, 'freshness.stale', []);
        $missing = (array) data_get($bundle, 'freshness.missing', []);
        if ($stale !== []) {
            $lines[] = 'Stale sources: '.implode(', ', array_map('strval', $stale));
        }
        if ($missing !== []) {
            $lines[] = 'Missing sources: '.implode(', ', array_map('strval', $missing));
        }
        if ($briefing->state !== Briefing::STATE_COMPLETE) {
            $lines[] = 'This brief is degraded: '.count((array) $briefing->degraded_reasons).' problem(s), see HQ.';
        }

        $lines[] = '';
        if (self::narrativeIsSourced($briefing)) {
            $lines[] = trim((string) $briefing->narrative);
        } else {
            $count = 0;
            foreach ((array) ($bundle['items'] ?? []) as $item) {
                if (! is_array($item) || data_get($item, 'scope.store') !== null || ! is_scalar($item['value'] ?? null)) {
                    continue;
                }
                if (in_array($item['unit'] ?? null, ['text', 'datetime'], true)) {
                    continue;
                }
                $lines[] = '- '.mb_substr((string) ($item['label'] ?? $item['id'] ?? ''), 0, 80).': '.$item['value'].' '.($item['unit'] ?? '');
                if (++$count >= self::MAX_FIGURES) {
                    break;
                }
            }
            if ($count === 0) {
                $lines[] = 'No figures were available.';
            }
        }

        return mb_substr(rtrim(implode("\n", $lines)), 0, self::MAX_CHARS);
    }

    /** The narrative may speak only when the stored check passed and no figure in it is unsourced. */
    public static function narrativeIsSourced(Briefing $briefing): bool
    {
        if ($briefing->narration_state !== 'narrated' || trim((string) $briefing->narrative) === '') {
            return false;
        }
        $check = (array) $briefing->narrative_check;
        if (($check['passed'] ?? false) !== true) {
            return false;
        }
        foreach ((array) ($check['figures'] ?? []) as $figure) {
            if (! is_array($figure) || ($figure['sourced'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }
}
