<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one case went in one run.
 *
 * `context_provenance` records the LABELS of the context the case was graded against, never the
 * text: an evaluation record that quoted a customer's message back would be a second copy of
 * personal data in a table nobody thinks of as holding any.
 */
class EvaluationResult extends Model
{
    protected $fillable = [
        'run_id', 'case_id', 'passed', 'score', 'grader', 'dimension', 'checks', 'expected', 'actual', 'reason',
        'latency_ms', 'ai_run_id', 'context_provenance',
    ];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'score' => 'float',
            'checks' => 'array',
            'expected' => 'array',
            'actual' => 'array',
            'context_provenance' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class, 'run_id');
    }

    public function evaluationCase(): BelongsTo
    {
        return $this->belongsTo(EvaluationCase::class, 'case_id');
    }
}
