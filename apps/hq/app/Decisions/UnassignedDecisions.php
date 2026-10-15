<?php

namespace App\Decisions;

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Conversation;
use App\Models\DecisionRecord;
use Illuminate\Support\Str;

/**
 * The decision records with no business (ruling docs/120 item 21: "leave ambiguous records unassigned and
 * explicitly report them"), each with the reason the business could not be established. Read-only: it
 * writes nothing and assigns nothing; a person decides what, if anything, to do about a row.
 */
final class UnassignedDecisions
{
    public const CONTEXT_MISSING = 'the context row it names no longer exists';

    public const CONTEXT_WITHOUT_BUSINESS = 'its context row carries no business (a group-wide run or approval)';

    public const UNKNOWN_CONTEXT = 'its context type is not one HQ can resolve to a business';

    /** @return list<array{id: int, context_type: string, context_id: string, decision: string, created_at: ?string, reason: string}> */
    public function list(): array
    {
        $out = [];
        foreach (DecisionRecord::query()->whereNull('business_id')->orderBy('id')->get() as $record) {
            $out[] = [
                'id' => (int) $record->id,
                'context_type' => (string) $record->context_type,
                'context_id' => (string) $record->context_id,
                'decision' => (string) $record->decision,
                'created_at' => $record->created_at?->toIso8601String(),
                'reason' => $this->reason($record),
            ];
        }

        return $out;
    }

    private function reason(DecisionRecord $record): string
    {
        $context = match ($record->context_type) {
            'approval' => ctype_digit((string) $record->context_id) ? Approval::query()->whereKey((int) $record->context_id)->first(['id', 'business_id']) : null,
            'agent_run' => Str::isUuid((string) $record->context_id) ? AgentRun::query()->where('uuid', $record->context_id)->first(['id', 'business_id']) : null,
            'conversation' => Str::isUuid((string) $record->context_id) ? Conversation::query()->where('uuid', $record->context_id)->first(['id', 'business_id']) : null,
            default => false,
        };

        return match (true) {
            $context === false => self::UNKNOWN_CONTEXT,
            $context === null => self::CONTEXT_MISSING,
            $context->business_id === null => self::CONTEXT_WITHOUT_BUSINESS,
            default => "its context row names business {$context->business_id}, but the record was written without it after the backfill",
        };
    }
}
