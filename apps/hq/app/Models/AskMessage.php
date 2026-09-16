<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in an Ask HQ conversation (docs/48 K.2): the operator's question, the agent's answer, or a
 * system line saying why nothing was asked.
 *
 * An answer names the agent run that produced it; the run's correlation id reaches its model calls and
 * tool calls, which is the trace docs/48 N.1 asks for, from this row to an external HTTP status.
 */
class AskMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const REFUSED = 'refused';

    protected $fillable = ['ask_conversation_id', 'role', 'content', 'structured', 'agent_run_id', 'correlation_id', 'status'];

    protected function casts(): array
    {
        return ['structured' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AskConversation::class, 'ask_conversation_id');
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
