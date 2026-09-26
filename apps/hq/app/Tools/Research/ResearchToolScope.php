<?php

namespace App\Tools\Research;

use App\Models\ResearchTask;
use App\Tools\ToolContext;

/** A research task, only if it belongs to the business the call is scoped to and the caller may read. */
final class ResearchToolScope
{
    public static function task(string $uuid, ToolContext $context): ?ResearchTask
    {
        $task = ResearchTask::query()->where('uuid', $uuid)->first();
        if ($task === null) {
            return null;
        }
        if ($context->businessId !== null && $task->business_id !== $context->businessId) {
            return null;
        }
        if ($context->permittedBusinessIds !== null && ! in_array($task->business_id, $context->permittedBusinessIds, true)) {
            return null;
        }

        return $task;
    }
}
