<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One raw model reply on one `ai_runs` row (docs/90 A.1 step 1): the words the model wrote, the
 * object they decoded to, and the tool calls the runtime read from them.
 *
 * ⛔ Evidence, never authority. Nothing reads this table into a decision; `ModelReplyCaptureTest`
 *    holds that the only writer is the runtime and the only readers are observability and tests.
 */
class AiRunReply extends Model
{
    public const UPDATED_AT = null;

    public const KIND_FINAL = 'final';

    public const KIND_REPAIRED = 'repaired';

    public const KIND_INVALID = 'invalid';

    public const KIND_TRUNCATED = 'truncated';

    protected $fillable = [
        'ai_run_id', 'correlation_id', 'agent_key', 'provider', 'model', 'prompt_version', 'attempt',
        'kind', 'stop_reason', 'raw_text', 'raw_json', 'normalised',
    ];

    protected function casts(): array
    {
        return ['raw_json' => 'array', 'normalised' => 'array', 'attempt' => 'integer', 'created_at' => 'datetime'];
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }
}
