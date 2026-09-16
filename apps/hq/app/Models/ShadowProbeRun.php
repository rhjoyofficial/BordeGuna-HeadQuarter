<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled Shadow Probe in one slot of a shadow period (docs/77 E.1).
 *
 * `status` says whether it was asked (`asked`), could not be asked because the runtime or the agent
 * was off (`skipped`), or failed to run (`failed`). `verdict` is the run's own ending, and
 * `task_matched` whether the runtime's canonical task stayed exactly the probe's question: a probe
 * is trusted workload, and a run that served anything else is a discrepancy, not evidence.
 */
class ShadowProbeRun extends Model
{
    public const ASKED = 'asked';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /** Verdicts that make a probe evidence. A run that stopped or failed measured nothing. */
    public const VERDICTS = ['answered', 'refused', 'clarified'];

    protected $fillable = ['shadow_period_id', 'probe_key', 'question', 'slot_date', 'slot', 'agent_run_id', 'status', 'verdict', 'task_matched', 'note'];

    protected function casts(): array
    {
        return [
            'slot_date' => 'date:Y-m-d',
            'task_matched' => 'boolean',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ShadowPeriod::class, 'shadow_period_id');
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /** This probe is evidence: asked, a verdict reached, and the task unchanged. */
    public function isEvidence(): bool
    {
        return $this->status === self::ASKED && in_array($this->verdict, self::VERDICTS, true) && $this->task_matched === true;
    }
}
