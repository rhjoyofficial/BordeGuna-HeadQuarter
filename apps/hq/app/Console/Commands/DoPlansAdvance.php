<?php

namespace App\Console\Commands;

use App\Models\DoPlan;
use App\OwnerConsole\Do\DoPlanService;
use App\OwnerConsole\Flag;
use Illuminate\Console\Command;
use Throwable;

/**
 * docs/143 A: reads decided approvals back into running Do plans and puts the next step to the gateway.
 * Does nothing while the flag `console.do_mode` is OFF (the shipped state). It decides no approval: a step
 * that needs one stays waiting for a person.
 */
final class DoPlansAdvance extends Command
{
    protected $signature = 'hq:do-plans:advance';

    protected $description = 'Advance running Do plans (reads decided approvals back, submits the next step). Inert while console.do_mode is off.';

    public function handle(DoPlanService $plans): int
    {
        if (! Flag::on(Flag::DO_MODE)) {
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
