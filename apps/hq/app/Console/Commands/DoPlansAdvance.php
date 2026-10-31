<?php

namespace App\Console\Commands;

use App\Models\DoPlan;
use App\OwnerConsole\Do\DoModeActivation;
use App\OwnerConsole\Do\DoPlanService;
use Illuminate\Console\Command;
use Throwable;

/**
 * docs/143 A: reads decided approvals back into running Do plans and runs read steps. It never puts an
 * effectful step to the gateway (ruling 31 Oct item 1: no automatic continuation; a named person continues).
 * Does nothing while Do mode is not activated (`DoModeActivation`, the shipped state). It decides no approval.
 */
final class DoPlansAdvance extends Command
{
    protected $signature = 'hq:do-plans:advance';

    protected $description = 'Read decided approvals back into running Do plans; never submits an effectful step. Inert while Do mode is not activated.';

    public function handle(DoPlanService $plans, DoModeActivation $activation): int
    {
        if (! $activation->active()) {
            return self::SUCCESS;
        }

        $count = 0;
        DoPlan::query()->where('state', 'running')->orderBy('id')->each(function (DoPlan $plan) use ($plans, &$count) {
            try {
                $plans->advance($plan);
                $count++;
            } catch (Throwable $e) {
                $this->warn("plan {$plan->uuid}: ".class_basename($e));
            }
        });
        $this->info("do plans advanced: {$count}");

        return self::SUCCESS;
    }
}
