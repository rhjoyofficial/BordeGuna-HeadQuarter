import { type Tone } from '@/components/ui/status-badge';

/** What the two briefing pages share (docs/80 C.1): the row shape and how a state reads. */

export type BriefingReason = { code: string; check: string; subject: string | null; message: string; blocks_narration: boolean };

export type BriefingSummary = {
    uuid: string;
    briefing_date: string;
    timezone: string;
    business: string | null;
    restricted: boolean;
    state: string;
    degraded_reasons: BriefingReason[];
    narration_state: string;
    items: number;
    generated_at: string;
    capability: string;
    capability_version: string;
    bundle_version: string;
};

export const STATE_TONE: Record<string, Tone> = { complete: 'ok', degraded: 'warn' };

export const NARRATION_LABEL: Record<string, string> = {
    off: 'evidence only (narration is off)',
    runtime_off: 'evidence only (the AI runtime is off)',
    blocked: 'not narrated (the bundle is not fit to narrate)',
    unavailable: 'not narrated (no narrator)',
    empty: 'not narrated (the narrator wrote nothing)',
    rejected: 'narrative rejected (unsourced figure)',
    narrated: 'narrated',
};

export function scopeLabel(b: { business: string | null; restricted: boolean }): string {
    if (b.business) {
        return b.business;
    }
    return b.restricted ? 'Some businesses' : 'The group';
}
