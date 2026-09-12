import { type Tone } from '@/components/ui/status-badge';
import { humanKey } from '@/lib/format';

/** What a run's state means to the reader, in one badge, said the same way on both agent screens. */
export function runStatus(run: { status: string; stop_reason: string | null; shadow: boolean }): { tone: Tone; label: string } {
    const why = run.stop_reason ? `, ${humanKey(run.stop_reason).toLowerCase()}` : '';
    if (run.status === 'failed') return { tone: 'danger', label: `failed${why}` };
    if (run.status === 'refused') return { tone: 'warn', label: 'refused' };
    if (run.status === 'stopped') return { tone: 'warn', label: `stopped${why}` };
    if (run.status === 'answered') return run.shadow ? { tone: 'pending', label: 'answered in shadow' } : { tone: 'ok', label: 'answered' };
    if (run.status === 'running') return { tone: 'info', label: 'running' };
    return { tone: 'neutral', label: run.status };
}
