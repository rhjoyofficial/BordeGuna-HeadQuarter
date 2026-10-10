<?php

namespace App\Identity\Quarantine;

use App\Models\CrossBusinessQuarantine;
use App\Models\CrossBusinessQuarantineReview;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of quarantine flags and reviews (docs/117 §3 and §5). Both are append-only rows with an
 * audit record each. Nothing here touches the flagged rows: no merge is undone, no row is re-pointed,
 * nothing is deleted.
 */
final class QuarantineLedger
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Writes a flag for each finding not flagged yet. Idempotent: a finding already flagged (same subject
     * row and reason) adds nothing, so running the command twice writes nothing the second time.
     *
     * @param  list<Finding>  $findings
     * @return list<CrossBusinessQuarantine> the flags written by this call
     */
    public function flag(array $findings, ?int $byUserId = null): array
    {
        $written = [];

        foreach ($findings as $finding) {
            $flag = DB::transaction(function () use ($finding, $byUserId) {
                $exists = CrossBusinessQuarantine::query()
                    ->where('subject_table', $finding->subjectTable)->where('subject_id', $finding->subjectId)->where('reason', $finding->reason)
                    ->lockForUpdate()->exists();
                if ($exists) {
                    return null;
                }

                $flag = CrossBusinessQuarantine::create([
                    ...$finding->toArray(),
                    'detected_at' => now(),
                    'flagged_by' => $byUserId,
                ]);

                $after = ['kind' => $finding->kind, 'subject' => $finding->key(), 'customer_id' => $finding->customerId,
                    'customer_business_id' => $finding->customerBusinessId, 'row_business_id' => $finding->rowBusinessId, 'merge_id' => $finding->mergeId];
                $byUserId === null
                    ? $this->auditor->recordSystem('identity.quarantine.flagged', $flag, after: $after, reason: $finding->reason)
                    : $this->auditor->record('identity.quarantine.flagged', $flag, after: $after, reason: $finding->reason, actorUserId: $byUserId);

                return $flag;
            });

            if ($flag !== null) {
                $written[] = $flag;
            }
        }

        return $written;
    }

    /** A person's decision on a flag: a new review row and an audit row. The flag and its subject stay as they are. */
    public function review(CrossBusinessQuarantine $flag, QuarantineDecision $decision, string $note, User $reviewer): CrossBusinessQuarantineReview
    {
        $note = trim($note);
        if ($note === '') {
            throw new InvalidArgumentException('A quarantine review needs a note saying why.');
        }

        return DB::transaction(function () use ($flag, $decision, $note, $reviewer) {
            $previous = $flag->latestReview()->first();

            $review = CrossBusinessQuarantineReview::create([
                'quarantine_id' => $flag->id,
                'decision' => $decision->value,
                'note' => $note,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $this->auditor->record('identity.quarantine.reviewed', $flag,
                before: $previous === null ? null : ['decision' => $previous->decision->value, 'review_id' => $previous->id],
                after: ['decision' => $decision->value, 'review_id' => $review->id, 'subject' => "{$flag->subject_table}:{$flag->subject_id}:{$flag->reason}"],
                reason: $note, actorUserId: $reviewer->id);

            return $review;
        });
    }
}
