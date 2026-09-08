<?php

namespace App\Console\Commands;

use App\Gateway\Approval\ApprovalService;
use Illuminate\Console\Command;

/**
 * Expires approvals nobody decided, and escalates ones past their deadline.
 *
 * ⚠ Expiry NEVER approves. An ignored approval becomes `expired`, which is a refusal. Auto-approval
 * on timeout would make every gate decorative under exactly the load that makes gates matter.
 */
final class ApprovalsSweep extends Command
{
    protected $signature = 'hq:approvals:sweep';

    protected $description = 'Expire approvals past their expiry and escalate ones past their SLA. Never approves anything.';

    public function handle(ApprovalService $approvals): int
    {
        $escalated = $approvals->escalateOverdue();
        $expired = $approvals->expireLapsed();

        $this->line("escalated {$escalated}, expired {$expired}");

        return self::SUCCESS;
    }
}
