import { type Tone } from '@/components/ui/status-badge';
import { humanKey } from '@/lib/format';

/** A job's state and its approval state as one badge: a job that is not approved does not run whatever its state says. */
export function jobState(job: { state: string; approval_state: string }): { tone: Tone; label: string } {
    if (job.approval_state !== 'approved') return { tone: 'pending', label: `${job.state}, ${humanKey(job.approval_state).toLowerCase()}` };
    if (job.state === 'active') return { tone: 'ok', label: 'active' };
    if (job.state === 'paused') return { tone: 'neutral', label: 'paused' };
    if (job.state === 'done') return { tone: 'ok', label: 'done' };
    if (job.state === 'cancelled') return { tone: 'neutral', label: 'cancelled' };
    return { tone: 'neutral', label: job.state };
}
